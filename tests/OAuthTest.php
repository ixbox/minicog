<?php

namespace MiniCog\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use MiniCog\TokenService;
use Psr\Http\Message\ResponseInterface;

final class OAuthTest extends TestCase
{
    private const REDIRECT = 'http://localhost:3000/api/auth/callback/cognito';

    protected function setUp(): void
    {
        $this->boot(['clients' => [
            ['clientId' => 'web', 'callbackUrls' => [self::REDIRECT], 'logoutUrls' => ['http://localhost:3000/']],
            ['clientId' => 'm2m', 'clientSecret' => 'm2m-secret', 'allowedScopes' => ['api/read', 'api/write']],
        ]]);
        $this->minicog->createConfirmedUser('alice', 'password123', ['email' => 'alice@example.com']);
    }

    public function testDiscoveryAndJwks(): void
    {
        $discovery = $this->json($this->get('/' . self::POOL_ID . '/.well-known/openid-configuration'));
        $this->assertSame('http://localhost:9229/' . self::POOL_ID, $discovery['issuer']);
        $this->assertSame('http://localhost:9229/oauth2/token', $discovery['token_endpoint']);

        $jwks = $this->json($this->get('/' . self::POOL_ID . '/.well-known/jwks.json'));
        $this->assertSame($this->minicog->jwks(), $jwks);

        $this->assertSame(404, $this->get('/ap-northeast-1_other/.well-known/jwks.json')->getStatusCode());
    }

    public function testAuthorizationCodeFlowWithPkce(): void
    {
        $verifier = TokenService::base64url(random_bytes(32));
        $params = [
            'response_type' => 'code',
            'client_id' => 'web',
            'redirect_uri' => self::REDIRECT,
            'scope' => 'openid email',
            'state' => 'xyz',
            'nonce' => 'n-0S6',
            'code_challenge' => TokenService::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];

        $form = $this->get('/oauth2/authorize?' . http_build_query($params));
        $this->assertSame(200, $form->getStatusCode());
        $this->assertStringContainsString('<form method="post" action="/login">', (string) $form->getBody());

        $failed = $this->post('/login', ['params' => json_encode($params), 'username' => 'alice', 'password' => 'nope']);
        $this->assertSame(400, $failed->getStatusCode());
        $this->assertStringContainsString('Incorrect username or password.', (string) $failed->getBody());

        $login = $this->post('/login', ['params' => json_encode($params), 'username' => 'alice', 'password' => 'password123']);
        $this->assertSame(302, $login->getStatusCode());
        parse_str(parse_url($login->getHeaderLine('Location'), PHP_URL_QUERY), $callback);
        $this->assertSame('xyz', $callback['state']);
        $cookie = explode(';', $login->getHeaderLine('Set-Cookie'))[0];

        $wrongVerifier = $this->post('/oauth2/token', [
            'grant_type' => 'authorization_code', 'client_id' => 'web', 'code' => $callback['code'],
            'redirect_uri' => self::REDIRECT, 'code_verifier' => 'wrong',
        ]);
        $this->assertSame(['error' => 'invalid_grant'], $this->json($wrongVerifier));

        // 失敗でコードは消費されるので、もう一度認可する（Cookie のセッションで即リダイレクト）
        $again = $this->get('/oauth2/authorize?' . http_build_query($params), ['Cookie' => $cookie]);
        $this->assertSame(302, $again->getStatusCode());
        parse_str(parse_url($again->getHeaderLine('Location'), PHP_URL_QUERY), $callback);

        $tokens = $this->json($this->post('/oauth2/token', [
            'grant_type' => 'authorization_code', 'client_id' => 'web', 'code' => $callback['code'],
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ]));
        $this->assertSame('Bearer', $tokens['token_type']);

        $id = $this->decode($tokens['id_token']);
        $this->assertSame('n-0S6', $id['nonce']);
        $this->assertSame('web', $id['aud']);
        $this->assertSame('alice@example.com', $id['email']);
        $this->assertSame(TokenService::base64url(substr(hash('sha256', $tokens['access_token'], true), 0, 16)), $id['at_hash']);
        $this->assertSame('openid email', $this->decode($tokens['access_token'])['scope']);

        $userInfo = $this->json($this->get('/oauth2/userInfo', ['Authorization' => 'Bearer ' . $tokens['access_token']]));
        $this->assertSame('alice@example.com', $userInfo['email']);
        $this->assertSame('alice', $userInfo['username']);

        // aws.cognito.signin.user.admin スコープが無いトークンは GetUser に使えない
        $this->expectCognitoError('NotAuthorizedException', fn () => $this->client->getUser(['AccessToken' => $tokens['access_token']]));

        $refreshed = $this->json($this->post('/oauth2/token', [
            'grant_type' => 'refresh_token', 'client_id' => 'web', 'refresh_token' => $tokens['refresh_token'],
        ]));
        $this->assertArrayHasKey('id_token', $refreshed);
        $this->assertArrayNotHasKey('refresh_token', $refreshed);

        $this->assertSame(200, $this->post('/oauth2/revoke', ['client_id' => 'web', 'token' => $tokens['refresh_token']])->getStatusCode());
        $this->assertSame(401, $this->get('/oauth2/userInfo', ['Authorization' => 'Bearer ' . $tokens['access_token']])->getStatusCode());

        $logout = $this->get('/logout?' . http_build_query(['client_id' => 'web', 'logout_uri' => 'http://localhost:3000/']), ['Cookie' => $cookie]);
        $this->assertSame('http://localhost:3000/', $logout->getHeaderLine('Location'));
        $this->assertSame(200, $this->get('/oauth2/authorize?' . http_build_query($params), ['Cookie' => $cookie])->getStatusCode());
    }

    public function testImplicitFlow(): void
    {
        $params = ['response_type' => 'token', 'client_id' => 'web', 'redirect_uri' => self::REDIRECT, 'scope' => 'openid', 'state' => 's'];
        $login = $this->post('/login', ['params' => json_encode($params), 'username' => 'alice@example.com', 'password' => 'password123']);
        $this->assertSame(400, $login->getStatusCode(), 'email はエイリアス未設定ならサインインに使えない');

        $login = $this->post('/login', ['params' => json_encode($params), 'username' => 'alice', 'password' => 'password123']);
        parse_str(parse_url($login->getHeaderLine('Location'), PHP_URL_FRAGMENT), $fragment);
        $this->assertSame('s', $fragment['state']);
        $this->assertSame('id', $this->decode($fragment['id_token'])['token_use']);
        $this->assertArrayNotHasKey('refresh_token', $fragment);
    }

    public function testForceChangePasswordOnLoginPage(): void
    {
        $this->client->adminCreateUser(['UserPoolId' => self::POOL_ID, 'Username' => 'bob', 'TemporaryPassword' => 'temporary1']);
        $params = ['response_type' => 'code', 'client_id' => 'web', 'redirect_uri' => self::REDIRECT];

        $prompt = $this->post('/login', ['params' => json_encode($params), 'username' => 'bob', 'password' => 'temporary1']);
        $this->assertStringContainsString('name="new_password"', (string) $prompt->getBody());

        $done = $this->post('/login', ['params' => json_encode($params), 'username' => 'bob', 'password' => 'temporary1', 'new_password' => 'permanent1']);
        $this->assertSame(302, $done->getStatusCode());
        $this->login('bob', 'permanent1', 'web');
    }

    public function testRejectsUnknownRedirectUri(): void
    {
        $response = $this->get('/oauth2/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => 'web', 'redirect_uri' => 'https://evil.example/',
        ]));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testClientCredentials(): void
    {
        $basic = 'Basic ' . base64_encode('m2m:m2m-secret');
        $tokens = $this->json($this->post('/oauth2/token', ['grant_type' => 'client_credentials', 'scope' => 'api/read'], ['Authorization' => $basic]));
        $access = $this->decode($tokens['access_token']);
        $this->assertSame('m2m', $access['sub']);
        $this->assertSame('api/read', $access['scope']);
        $this->assertArrayNotHasKey('id_token', $tokens);

        $this->assertSame(['error' => 'invalid_scope'], $this->json($this->post('/oauth2/token', [
            'grant_type' => 'client_credentials', 'scope' => 'api/admin',
        ], ['Authorization' => $basic])));

        $wrongSecret = $this->post('/oauth2/token', ['grant_type' => 'client_credentials'], ['Authorization' => 'Basic ' . base64_encode('m2m:nope')]);
        $this->assertSame(401, $wrongSecret->getStatusCode());

        $this->assertSame(['error' => 'unauthorized_client'], $this->json($this->post('/oauth2/token', [
            'grant_type' => 'client_credentials', 'client_id' => 'web',
        ])));
    }

    public function testRouting(): void
    {
        $this->assertSame(404, $this->get('/nope')->getStatusCode());

        // / はブラウザで開くと管理画面へ、ヘッダーなしの POST は 405
        $root = $this->get('/');
        $this->assertSame(302, $root->getStatusCode());
        $this->assertSame('/admin', $root->getHeaderLine('Location'));
        $this->assertSame(405, $this->post('/', [])->getStatusCode());

        $notAllowed = $this->minicog->handle(new ServerRequest('DELETE', 'http://localhost:9229/oauth2/token'));
        $this->assertSame(405, $notAllowed->getStatusCode());
        $this->assertSame('POST', $notAllowed->getHeaderLine('Allow'));

        // userInfo はパスの大文字小文字どちらでも受け付ける
        $this->assertSame(401, $this->get('/oauth2/userinfo')->getStatusCode());

        // 管理画面の操作（パスパラメータはデコードされる）
        $this->minicog->users->create('user name', 'password123', [], 'UNCONFIRMED');
        $confirm = $this->post('/admin/users/user%20name/confirm', []);
        $this->assertSame(303, $confirm->getStatusCode());
        $this->assertSame('CONFIRMED', $this->minicog->users->get('user name')['status']);

        $this->post('/admin/users/user%20name/delete', []);
        $this->assertNull($this->minicog->users->find('user name'));

        $missing = $this->post('/admin/users/ghost/delete', []);
        $this->assertStringStartsWith('/admin?error=', $missing->getHeaderLine('Location'));
    }

    public function testCorsPreflight(): void
    {
        $response = $this->minicog->handle(new ServerRequest('OPTIONS', 'http://localhost:9229/', [
            'Origin' => 'http://localhost:3000',
            'Access-Control-Request-Headers' => 'content-type,x-amz-target,x-amz-user-agent',
        ]));
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('http://localhost:3000', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('content-type,x-amz-target,x-amz-user-agent', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    private function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->minicog->handle(new ServerRequest('GET', 'http://localhost:9229' . $path, $headers));
    }

    private function post(string $path, array $form, array $headers = []): ResponseInterface
    {
        return $this->minicog->handle(new ServerRequest(
            'POST',
            'http://localhost:9229' . $path,
            $headers + ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
        ));
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }
}
