<?php

namespace MiniCog\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

final class AdminTest extends TestCase
{
    /** @var array<string, string> ブラウザの Cookie の代わり */
    private array $cookies = [];

    public function testUserLifecycle(): void
    {
        $created = $this->post('/admin/users', ['username' => 'alice', 'password' => 'password123', 'email' => 'alice@example.com', 'confirmed' => '1']);
        $this->assertSame(303, $created->getStatusCode());
        $this->assertSame('/admin/users/alice', $created->getHeaderLine('Location'));

        $page = $this->follow($created);
        $this->assertStringContainsString('ユーザー alice を作成しました。', $page);
        $this->assertStringContainsString('alice@example.com', $page);
        $this->assertStringNotContainsString('を作成しました。', $this->html($this->get('/admin/users/alice')), 'フラッシュは一度だけ表示する');

        $this->follow($this->post('/admin/users/alice/attributes', ['name' => 'custom:tenant', 'value' => 'acme']));
        $this->assertSame('acme', $this->minicog->users->get('alice')['attributes']['custom:tenant']);
        $this->follow($this->post('/admin/users/alice/attributes/custom%3Atenant/delete', []));
        $this->assertArrayNotHasKey('custom:tenant', $this->minicog->users->get('alice')['attributes']);
        $this->assertStringContainsString('Cannot delete an immutable attribute: sub', $this->follow($this->post('/admin/users/alice/attributes/sub/delete', [])));

        $this->follow($this->post('/admin/users/alice/password', ['password' => 'temporary1']));
        $this->assertSame('FORCE_CHANGE_PASSWORD', $this->minicog->users->get('alice')['status']);
        $this->assertStringContainsString('Password did not conform with policy', $this->follow($this->post('/admin/users/alice/password', ['password' => 'x', 'permanent' => '1'])));
        $this->follow($this->post('/admin/users/alice/password', ['password' => 'permanent1', 'permanent' => '1']));
        $this->assertSame('CONFIRMED', $this->minicog->users->get('alice')['status']);

        $tokens = $this->login('alice', 'permanent1');
        $this->assertStringContainsString('有効なセッション: 1', $this->html($this->get('/admin/users/alice')));
        $this->follow($this->post('/admin/users/alice/signout', []));
        $this->expectCognitoError('NotAuthorizedException', fn () => $this->client->getUser(['AccessToken' => $tokens['AccessToken']]));

        $this->follow($this->post('/admin/users/alice/disable', []));
        $this->assertFalse($this->minicog->users->get('alice')['enabled']);
        $this->expectCognitoError('NotAuthorizedException', fn () => $this->login('alice', 'permanent1'));
        $this->follow($this->post('/admin/users/alice/enable', []));
        $this->login('alice', 'permanent1');

        $deleted = $this->post('/admin/users/alice/delete', []);
        $this->assertSame('/admin/users', $deleted->getHeaderLine('Location'));
        $this->assertNull($this->minicog->users->find('alice'));
    }

    public function testErrorsAreShownOnTheNextPage(): void
    {
        $failed = $this->post('/admin/users', ['username' => 'bob', 'password' => 'short']);
        $this->assertSame('/admin/users', $failed->getHeaderLine('Location'));
        $this->assertStringContainsString('class="flash error"', $this->follow($failed));

        $missing = $this->get('/admin/users/ghost');
        $this->assertSame('/admin/users', $missing->getHeaderLine('Location'));
        $this->assertStringContainsString('ユーザー ghost は存在しません。', $this->follow($missing));
    }

    public function testGroups(): void
    {
        $this->minicog->createConfirmedUser('carol', 'password123');

        $this->assertStringContainsString('優先度は 0 以上の整数', $this->follow($this->post('/admin/groups', ['name' => 'admins', 'precedence' => '-1'])));

        $created = $this->post('/admin/groups', ['name' => 'admins', 'description' => '管理者', 'precedence' => '1']);
        $this->assertSame('/admin/groups/admins', $created->getHeaderLine('Location'));
        $this->assertStringContainsString('<option value="carol">carol</option>', $this->follow($created));

        $this->follow($this->post('/admin/groups/admins/members', ['username' => 'carol']));
        $this->assertSame(['admins'], $this->decode($this->login('carol', 'password123')['IdToken'])['cognito:groups']);
        $this->assertStringContainsString('href="/admin/groups/admins"', $this->html($this->get('/admin/users/carol')));

        $this->follow($this->post('/admin/users/carol/groups/admins/delete', []));
        $this->follow($this->post('/admin/users/carol/groups', ['group' => 'admins']));
        $this->follow($this->post('/admin/groups/admins/members/carol/delete', []));
        $this->assertSame([], $this->minicog->users->group('admins')['members']);

        $this->assertSame('/admin/groups', $this->post('/admin/groups/admins/delete', [])->getHeaderLine('Location'));
        $this->assertSame([], $this->minicog->users->groups());
    }

    public function testIssueAndDecodeTokens(): void
    {
        $this->minicog->createConfirmedUser('dave', 'password123', ['email' => 'dave@example.com']);
        $this->assertStringContainsString('<option value="dave" selected>', preg_replace('/\s+/', ' ', $this->html($this->get('/admin/tokens?username=dave'))));

        $result = $this->post('/admin/tokens', ['username' => 'dave', 'client_id' => 'web', 'scope' => 'aws.cognito.signin.user.admin openid']);
        $html = $this->html($result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringNotContainsString('<html', $html, 'htmx 用のフラグメントを返す');
        preg_match('#<textarea id="token-AccessToken"[^>]*>([^<]+)</textarea>#', $html, $access);
        preg_match('#<textarea id="token-IdToken"[^>]*>([^<]+)</textarea>#', $html, $id);
        $this->assertSame('dave', $this->client->getUser(['AccessToken' => $access[1]])['Username'], '発行したトークンで API を呼べる');
        $this->assertSame('dave@example.com', $this->decode($id[1])['email']);
        $this->assertStringContainsString('token-RefreshToken', $html);

        $this->assertStringContainsString('ユーザーを選んでください。', $this->html($this->post('/admin/tokens', ['client_id' => 'web', 'scope' => 'openid'])));

        $decoded = $this->html($this->post('/admin/tokens/decode', ['jwt' => 'Bearer ' . $access[1]]));
        $this->assertStringContainsString('署名は有効で、期限内です。', $decoded);
        $this->assertStringContainsString('&quot;token_use&quot;: &quot;access&quot;', $decoded);

        $tampered = $this->html($this->post('/admin/tokens/decode', ['jwt' => substr($access[1], 0, -4) . 'AAAA']));
        $this->assertStringContainsString('検証に失敗しました', $tampered);

        $this->assertSame(422, $this->post('/admin/tokens/decode', ['jwt' => 'not-a-jwt'])->getStatusCode());
    }

    public function testIssueRequiresConfiguredClient(): void
    {
        $this->boot(['clients' => [['clientId' => 'web']]]);
        $this->minicog->createConfirmedUser('erin', 'password123');

        $this->assertStringContainsString('<option>web</option>', $this->html($this->get('/admin/tokens')));
        $this->assertStringContainsString('クライアント other は設定にありません。', $this->html($this->post('/admin/tokens', [
            'username' => 'erin', 'client_id' => 'other', 'scope' => 'openid',
        ])));
    }

    public function testSettingsAndReset(): void
    {
        $this->minicog->createConfirmedUser('frank', 'password123');
        $this->login('frank', 'password123');
        $kid = $this->minicog->tokens->signingKey()['kid'];

        $settings = $this->html($this->get('/admin/settings'));
        $this->assertStringContainsString(self::POOL_ID, $settings);
        $this->assertStringContainsString('ユーザー 1 人、グループ 0 個、有効なセッション 1 件', $settings);
        $this->assertStringContainsString($kid, $settings);

        $this->assertStringContainsString('データを初期化しました（署名鍵はそのまま）。', $this->follow($this->post('/admin/reset', [])));
        $this->assertSame([], $this->minicog->users->all());
        $this->assertSame($kid, $this->minicog->tokens->signingKey()['kid']);

        $this->follow($this->post('/admin/reset', ['rotate_key' => '1']));
        $this->assertNotSame($kid, $this->minicog->tokens->signingKey()['kid']);
    }

    public function testNavigationAndAssets(): void
    {
        $this->assertSame('/admin/users', $this->get('/admin')->getHeaderLine('Location'));

        $html = $this->html($this->get('/admin/groups'));
        $this->assertStringContainsString('hx-boost="true"', $html);
        $this->assertStringContainsString('<a href="/admin/groups" aria-current="page">', $html);

        foreach (['htmx.min.js' => 'text/javascript', 'minicog.js' => 'text/javascript', 'pico.min.css' => 'text/css', 'minicog.css' => 'text/css'] as $file => $type) {
            $asset = $this->get("/assets/$file");
            $this->assertSame(200, $asset->getStatusCode(), $file);
            $this->assertStringStartsWith($type, $asset->getHeaderLine('Content-Type'));
        }
        $this->assertSame('no-cache', $asset->getHeaderLine('Cache-Control'));
        $etag = $asset->getHeaderLine('ETag');
        $cached = $this->send(new ServerRequest('GET', 'http://localhost:9229/assets/minicog.css', ['If-None-Match' => $etag]));
        $this->assertSame(304, $cached->getStatusCode());

        // テンプレートは内容のハッシュ付きで参照し、その URL は長くキャッシュさせる
        preg_match('#href="(/assets/minicog\.css\?v=[0-9a-f]{10})"#', $html, $m);
        $this->assertNotEmpty($m, 'minicog.css をハッシュ付きで参照している');
        $versioned = $this->get($m[1]);
        $this->assertSame(200, $versioned->getStatusCode());
        $this->assertStringContainsString('immutable', $versioned->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $this->get('/assets/minicog.css?v=0000000000')->getHeaderLine('Cache-Control'), '古いハッシュはキャッシュさせない');
        $this->assertSame(404, $this->get('/assets/missing.css')->getStatusCode());
        $this->assertSame(404, $this->get('/assets/..%2Fcomposer.json')->getStatusCode());
    }

    private function get(string $path): ResponseInterface
    {
        return $this->send(new ServerRequest('GET', 'http://localhost:9229' . $path));
    }

    private function post(string $path, array $form): ResponseInterface
    {
        return $this->send(new ServerRequest('POST', 'http://localhost:9229' . $path, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query($form)));
    }

    private function send(ServerRequest $request): ResponseInterface
    {
        $response = $this->minicog->handle($request->withCookieParams($this->cookies));
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            [$pair] = explode(';', $cookie);
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '' || str_contains($cookie, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = rawurldecode($value);
            }
        }

        return $response;
    }

    /**
     * 303 のリダイレクト先を開いて HTML を返す。
     */
    private function follow(ResponseInterface $response): string
    {
        $this->assertContains($response->getStatusCode(), [302, 303]);

        return $this->html($this->get($response->getHeaderLine('Location')));
    }

    private function html(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
