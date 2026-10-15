<?php

namespace MiniCog\OAuth;

use GuzzleHttp\Psr7\Response;
use MiniCog\Cognito\CognitoException;
use MiniCog\Cognito\UserPool;
use MiniCog\Config;
use MiniCog\Http\View;
use MiniCog\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Cognito のマネージドログイン（Hosted UI）と OAuth 2.0 / OIDC エンドポイントの最小実装。
 *
 *   GET  /oauth2/authorize   ログインフォーム（セッションがあれば即リダイレクト）
 *   POST /login              フォーム送信
 *   POST /oauth2/token       authorization_code（PKCE 対応）/ refresh_token / client_credentials
 *   GET  /oauth2/userInfo
 *   POST /oauth2/revoke
 *   GET  /logout
 */
final class OAuthController
{
    private const SESSION_COOKIE = 'minicog_session';
    private const DEFAULT_SCOPES = ['openid', 'email', 'phone', 'profile', TokenService::ADMIN_SCOPE];

    public function __construct(
        private readonly Config $config,
        private readonly UserPool $users,
        private readonly TokenService $tokens,
        private readonly View $view,
    ) {
    }

    public function openidConfiguration(): ResponseInterface
    {
        return self::json([
            'issuer' => $this->config->issuer(),
            'authorization_endpoint' => $this->config->url('/oauth2/authorize'),
            'token_endpoint' => $this->config->url('/oauth2/token'),
            'userinfo_endpoint' => $this->config->url('/oauth2/userInfo'),
            'revocation_endpoint' => $this->config->url('/oauth2/revoke'),
            'end_session_endpoint' => $this->config->url('/logout'),
            'jwks_uri' => $this->config->url('/' . $this->config->userPoolId . '/.well-known/jwks.json'),
            'response_types_supported' => ['code', 'token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'email', 'phone', 'profile'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials', 'implicit'],
        ]);
    }

    // ------------------------------------------------------------------
    // ログイン
    // ------------------------------------------------------------------

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        if ($error = $this->validateAuthorizeParams($params)) {
            return $error;
        }

        $session = $this->webSession($request);
        if ($session !== null && ($user = $this->users->find($session['username'])) !== null && $user['enabled']) {
            return $this->completeAuthorization($user, $params);
        }

        return $this->loginForm($params);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $form = (array) $request->getParsedBody();
        $params = json_decode($form['params'] ?? '{}', true) ?: [];
        if ($error = $this->validateAuthorizeParams($params)) {
            return $error;
        }

        try {
            $user = $this->users->get((string) ($form['username'] ?? ''));
            if (!$user['enabled']) {
                throw CognitoException::notAuthorized('User is disabled.');
            }
            if (!$this->users->verifyPassword($user, (string) ($form['password'] ?? ''))) {
                throw CognitoException::notAuthorized();
            }

            if ($user['status'] === UserPool::STATUS_FORCE_CHANGE_PASSWORD) {
                $newPassword = (string) ($form['new_password'] ?? '');
                if ($newPassword === '') {
                    return $this->loginForm($params, 'パスワードの変更が必要です。新しいパスワードを入力してください。', (string) $form['username'], (string) $form['password']);
                }
                $this->users->setPassword($user['username'], $newPassword, UserPool::STATUS_CONFIRMED);
                $user = $this->users->get($user['username']);
            }
            if ($user['status'] === UserPool::STATUS_UNCONFIRMED) {
                throw new CognitoException('UserNotConfirmedException', 'User is not confirmed.');
            }
            if ($user['status'] === UserPool::STATUS_RESET_REQUIRED) {
                throw new CognitoException('PasswordResetRequiredException', 'Password reset required for the user');
            }
        } catch (CognitoException $e) {
            $message = $e->type === 'UserNotFoundException' ? 'Incorrect username or password.' : $e->getMessage();

            return $this->loginForm($params, $message, (string) ($form['username'] ?? ''));
        }

        $sessionId = TokenService::base64url(random_bytes(32));
        $this->users->store()->update(function (array &$state) use ($sessionId, $user) {
            $state['webSessions'][$sessionId] = [
                'username' => $user['username'],
                'expiresAt' => time() + 3600,
            ];
        });

        return $this->completeAuthorization($user, $params)
            ->withAddedHeader('Set-Cookie', self::SESSION_COOKIE . "=$sessionId; Path=/; HttpOnly; SameSite=Lax");
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $cookie = $request->getCookieParams()[self::SESSION_COOKIE] ?? null;
        if ($cookie !== null) {
            $this->users->store()->update(function (array &$state) use ($cookie) {
                unset($state['webSessions'][$cookie]);
            });
        }

        $clear = self::SESSION_COOKIE . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax';

        if (isset($params['logout_uri'])) {
            $client = $this->config->client((string) ($params['client_id'] ?? ''));
            $allowed = $client['logoutUrls'] ?? [];
            if ($client === null || ($allowed !== [] && !in_array($params['logout_uri'], $allowed, true))) {
                return $this->errorPage('logout_uri が許可されていません。', 400);
            }

            return new Response(302, ['Location' => $params['logout_uri'], 'Set-Cookie' => $clear]);
        }

        if (isset($params['redirect_uri'])) {
            return new Response(302, [
                'Location' => $this->config->url('/oauth2/authorize?' . http_build_query($params)),
                'Set-Cookie' => $clear,
            ]);
        }

        return $this->view->response('signed_out.html.twig', headers: ['Set-Cookie' => $clear]);
    }

    private function validateAuthorizeParams(array $params): ?ResponseInterface
    {
        $clientId = (string) ($params['client_id'] ?? '');
        $client = $this->config->client($clientId);
        if ($clientId === '' || $client === null) {
            return $this->errorPage('client_id が不正です。', 400);
        }

        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        $allowed = $client['callbackUrls'] ?? [];
        if ($redirectUri === '' || ($allowed !== [] && !in_array($redirectUri, $allowed, true))) {
            return $this->errorPage('redirect_uri が許可されていません: ' . $redirectUri, 400);
        }

        if (!in_array($params['response_type'] ?? '', ['code', 'token'], true)) {
            return self::redirectError($redirectUri, 'unsupported_response_type', $params['state'] ?? null);
        }

        return null;
    }

    private function completeAuthorization(array $user, array $params): ResponseInterface
    {
        $scopes = $this->scopes($params['scope'] ?? null, $this->config->client($params['client_id']));
        $state = $params['state'] ?? null;

        if ($params['response_type'] === 'token') {
            $tokens = $this->tokens->issueForUser($user, $params['client_id'], $scopes, [
                'nonce' => $params['nonce'] ?? null,
                'includeIdToken' => in_array('openid', $scopes, true),
                'includeRefreshToken' => false,
                'atHash' => true,
            ]);
            $fragment = array_filter([
                'access_token' => $tokens['AccessToken'],
                'id_token' => $tokens['IdToken'] ?? null,
                'token_type' => 'Bearer',
                'expires_in' => $tokens['ExpiresIn'],
                'state' => $state,
            ], fn ($value) => $value !== null);

            return new Response(302, ['Location' => $params['redirect_uri'] . '#' . http_build_query($fragment)]);
        }

        $code = UserPool::uuid();
        $this->users->store()->update(function (array &$state) use ($code, $user, $params, $scopes) {
            $state['authCodes'][$code] = [
                'username' => $user['username'],
                'clientId' => $params['client_id'],
                'redirectUri' => $params['redirect_uri'],
                'scopes' => $scopes,
                'nonce' => $params['nonce'] ?? null,
                'codeChallenge' => $params['code_challenge'] ?? null,
                'codeChallengeMethod' => $params['code_challenge_method'] ?? 'plain',
                'expiresAt' => time() + 300,
            ];
        });

        return new Response(302, ['Location' => self::appendQuery($params['redirect_uri'], array_filter([
            'code' => $code,
            'state' => $state,
        ], fn ($value) => $value !== null))]);
    }

    // ------------------------------------------------------------------
    // トークン
    // ------------------------------------------------------------------

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        try {
            $client = $this->authenticateClient($request, $body);

            return match ($body['grant_type'] ?? null) {
                'authorization_code' => $this->authorizationCodeGrant($client, $body),
                'refresh_token' => $this->refreshTokenGrant($client, $body),
                'client_credentials' => $this->clientCredentialsGrant($client, $body),
                default => throw new OAuthException('unsupported_grant_type'),
            };
        } catch (OAuthException $e) {
            return self::json(['error' => $e->error], $e->error === 'invalid_client' ? 401 : 400);
        }
    }

    private function authorizationCodeGrant(array $client, array $body): ResponseInterface
    {
        $code = (string) ($body['code'] ?? '');
        $entry = $this->users->store()->update(function (array &$state) use ($code) {
            $entry = $state['authCodes'][$code] ?? null;
            unset($state['authCodes'][$code]);

            return $entry;
        });

        if ($entry === null || $entry['expiresAt'] < time() || $entry['clientId'] !== $client['clientId']
            || ($body['redirect_uri'] ?? null) !== $entry['redirectUri']) {
            throw new OAuthException('invalid_grant');
        }

        if ($entry['codeChallenge'] !== null) {
            $verifier = (string) ($body['code_verifier'] ?? '');
            $computed = $entry['codeChallengeMethod'] === 'S256'
                ? TokenService::base64url(hash('sha256', $verifier, true))
                : $verifier;
            if (!hash_equals($entry['codeChallenge'], $computed)) {
                throw new OAuthException('invalid_grant');
            }
        }

        $user = $this->users->find($entry['username']);
        if ($user === null || !$user['enabled']) {
            throw new OAuthException('invalid_grant');
        }

        $tokens = $this->tokens->issueForUser($user, $client['clientId'], $entry['scopes'], [
            'nonce' => $entry['nonce'],
            'includeIdToken' => in_array('openid', $entry['scopes'], true),
            'atHash' => true,
        ]);

        return self::tokenResponse($tokens);
    }

    private function refreshTokenGrant(array $client, array $body): ResponseInterface
    {
        try {
            $tokens = $this->tokens->refresh((string) ($body['refresh_token'] ?? ''), $client['clientId']);
        } catch (CognitoException) {
            throw new OAuthException('invalid_grant');
        }

        return self::tokenResponse($tokens);
    }

    private function clientCredentialsGrant(array $client, array $body): ResponseInterface
    {
        if (($client['clientSecret'] ?? null) === null) {
            throw new OAuthException('unauthorized_client');
        }

        $requested = array_values(array_filter(explode(' ', (string) ($body['scope'] ?? ''))));
        $allowed = $client['allowedScopes'] ?? null;
        if ($allowed !== null && array_diff($requested, $allowed) !== []) {
            throw new OAuthException('invalid_scope');
        }

        return self::tokenResponse($this->tokens->issueForClient($client['clientId'], $requested ?: ($allowed ?? [])));
    }

    public function userInfo(ServerRequestInterface $request): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return self::json(['error' => 'invalid_token'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        try {
            $user = $this->tokens->userFromAccessToken($m[1], requireAdminScope: false);
        } catch (CognitoException) {
            return self::json(['error' => 'invalid_token'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        $claims = $user['attributes'];
        $claims['username'] = $user['username'];

        // Cognito の userInfo は *_verified を文字列で返す
        return self::json($claims);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        try {
            $client = $this->authenticateClient($request, $body);
            $this->tokens->revokeRefreshToken((string) ($body['token'] ?? ''), $client['clientId']);
        } catch (OAuthException $e) {
            return self::json(['error' => $e->error], 400);
        } catch (CognitoException) {
            return self::json(['error' => 'unauthorized_client'], 400);
        }

        return new Response(200);
    }

    // ------------------------------------------------------------------
    // 補助
    // ------------------------------------------------------------------

    private function authenticateClient(ServerRequestInterface $request, array $body): array
    {
        $clientId = $body['client_id'] ?? null;
        $secret = $body['client_secret'] ?? null;

        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Basic\s+(\S+)$/i', $header, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                throw new OAuthException('invalid_client');
            }
            [$clientId, $secret] = array_map('urldecode', explode(':', $decoded, 2));
        }

        $client = $clientId === null ? null : $this->config->client((string) $clientId);
        if ($client === null) {
            throw new OAuthException('invalid_client');
        }
        if (($client['clientSecret'] ?? null) !== null && !hash_equals($client['clientSecret'], (string) $secret)) {
            throw new OAuthException('invalid_client');
        }

        return $client;
    }

    /**
     * @return list<string>
     */
    private function scopes(?string $requested, array $client): array
    {
        $allowed = $client['allowedScopes'] ?? self::DEFAULT_SCOPES;
        if ($requested === null || trim($requested) === '') {
            return $allowed;
        }

        return array_values(array_intersect(array_filter(explode(' ', $requested)), $allowed));
    }

    private function webSession(ServerRequestInterface $request): ?array
    {
        $cookie = $request->getCookieParams()[self::SESSION_COOKIE] ?? null;
        if ($cookie === null) {
            return null;
        }
        $session = $this->users->store()->read()['webSessions'][$cookie] ?? null;

        return $session !== null && $session['expiresAt'] >= time() ? $session : null;
    }

    /**
     * @param string|null $currentPassword 指定時は FORCE_CHANGE_PASSWORD 用に新パスワード欄を出す
     */
    private function loginForm(array $params, ?string $error = null, string $username = '', ?string $currentPassword = null): ResponseInterface
    {
        return $this->view->response('login.html.twig', [
            'params' => $params,
            'error' => $error,
            'username' => $username,
            'currentPassword' => $currentPassword,
        ], $error === null ? 200 : 400);
    }

    private static function tokenResponse(array $tokens): ResponseInterface
    {
        return self::json(array_filter([
            'access_token' => $tokens['AccessToken'],
            'id_token' => $tokens['IdToken'] ?? null,
            'refresh_token' => $tokens['RefreshToken'] ?? null,
            'token_type' => 'Bearer',
            'expires_in' => $tokens['ExpiresIn'],
        ], fn ($value) => $value !== null))->withHeader('Cache-Control', 'no-store');
    }

    private static function redirectError(string $redirectUri, string $error, ?string $state): ResponseInterface
    {
        return new Response(302, ['Location' => self::appendQuery($redirectUri, array_filter([
            'error' => $error,
            'state' => $state,
        ], fn ($value) => $value !== null))]);
    }

    private static function appendQuery(string $uri, array $query): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($query);
    }

    private function errorPage(string $message, int $status): ResponseInterface
    {
        return $this->view->response('error.html.twig', ['message' => $message], $status);
    }

    private static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
