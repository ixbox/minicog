<?php

namespace MiniCog;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use MiniCog\Cognito\CognitoException;
use MiniCog\Cognito\UserPool;
use MiniCog\Store\StoreInterface;

/**
 * Cognito 互換の ID / アクセス / リフレッシュトークンを発行・検証する。
 *
 * - ID・アクセストークンは RS256 の JWT。鍵はストアに保存し、初回に生成する。
 * - リフレッシュトークンは不透明な文字列。state['sessions'][origin_jti] にハッシュを保存する。
 * - アクセストークンは origin_jti のセッションが残っている間だけ有効（GlobalSignOut / RevokeToken で失効）。
 */
final class TokenService
{
    public const ADMIN_SCOPE = 'aws.cognito.signin.user.admin';

    private ?array $key = null;

    public function __construct(
        private readonly StoreInterface $store,
        private readonly Config $config,
        private readonly UserPool $users,
    ) {
    }

    // ------------------------------------------------------------------
    // 鍵
    // ------------------------------------------------------------------

    /**
     * @return array{kid: string, privateKey: string, publicKey: string}
     */
    public function signingKey(): array
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $key = $this->store->read()['signingKey'] ?? null;
        if ($key === null) {
            $key = $this->store->update(function (array &$state) {
                return $state['signingKey'] ??= self::generateKey();
            });
        }

        return $this->key = $key;
    }

    public function jwks(): array
    {
        $key = $this->signingKey();
        $details = openssl_pkey_get_details(openssl_pkey_get_public($key['publicKey']));

        return [
            'keys' => [[
                'alg' => 'RS256',
                'e' => self::base64url($details['rsa']['e']),
                'kid' => $key['kid'],
                'kty' => 'RSA',
                'n' => self::base64url($details['rsa']['n']),
                'use' => 'sig',
            ]],
        ];
    }

    private static function generateKey(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);

        return [
            'kid' => self::base64url(random_bytes(24)),
            'privateKey' => $privateKey,
            'publicKey' => openssl_pkey_get_details($resource)['key'],
        ];
    }

    // ------------------------------------------------------------------
    // 発行
    // ------------------------------------------------------------------

    /**
     * ユーザー認証に成功したときのトークン一式を発行する。
     *
     * @param list<string> $scopes   OAuth のスコープ。API 経由の認証では [ADMIN_SCOPE]
     * @param array{nonce?: string, atHash?: bool, includeIdToken?: bool, includeRefreshToken?: bool} $options
     * @return array{AccessToken: string, IdToken?: string, RefreshToken?: string, ExpiresIn: int, TokenType: string}
     */
    public function issueForUser(array $user, string $clientId, array $scopes = [self::ADMIN_SCOPE], array $options = []): array
    {
        $originJti = UserPool::uuid();
        $authTime = time();
        $refreshToken = self::base64url(random_bytes(64));

        // インプリシットフローでもアクセストークンの失効判定のためにセッションは作る
        $this->store->update(function (array &$state) use ($originJti, $user, $clientId, $scopes, $authTime, $refreshToken) {
            $state['sessions'][$originJti] = [
                'refreshTokenHash' => hash('sha256', $refreshToken),
                'username' => $user['username'],
                'clientId' => $clientId,
                'scopes' => $scopes,
                'authTime' => $authTime,
                'expiresAt' => $authTime + $this->config->refreshTokenTtl,
            ];
        });

        $tokens = $this->issueTokens($user, $clientId, $scopes, $originJti, $authTime, $options);
        if ($options['includeRefreshToken'] ?? true) {
            $tokens['RefreshToken'] = $refreshToken;
        }

        return $tokens;
    }

    /**
     * リフレッシュトークンから新しい ID / アクセストークンを発行する（リフレッシュトークンは再発行しない）。
     */
    public function refresh(string $refreshToken, ?string $clientId): array
    {
        [$originJti, $session] = $this->findSession($refreshToken)
            ?? throw CognitoException::notAuthorized('Invalid Refresh Token');

        if ($clientId !== null && $session['clientId'] !== $clientId) {
            throw CognitoException::notAuthorized('Invalid Refresh Token');
        }

        $user = $this->users->find($session['username']);
        if ($user === null) {
            throw CognitoException::notAuthorized('Invalid Refresh Token');
        }
        if (!$user['enabled']) {
            throw CognitoException::notAuthorized('User is disabled.');
        }

        return $this->issueTokens($user, $session['clientId'], $session['scopes'], $originJti, $session['authTime'], [
            'includeIdToken' => in_array(self::ADMIN_SCOPE, $session['scopes'], true) || in_array('openid', $session['scopes'], true),
        ]);
    }

    /**
     * client_credentials グラント用のアクセストークン。
     *
     * @param list<string> $scopes
     */
    public function issueForClient(string $clientId, array $scopes): array
    {
        $now = time();
        $claims = [
            'sub' => $clientId,
            'token_use' => 'access',
            'scope' => implode(' ', $scopes),
            'auth_time' => $now,
            'iss' => $this->config->issuer(),
            'exp' => $now + $this->config->accessTokenTtl,
            'iat' => $now,
            'version' => 2,
            'jti' => UserPool::uuid(),
            'client_id' => $clientId,
        ];

        return [
            'AccessToken' => $this->sign($claims),
            'ExpiresIn' => $this->config->accessTokenTtl,
            'TokenType' => 'Bearer',
        ];
    }

    private function issueTokens(array $user, string $clientId, array $scopes, string $originJti, int $authTime, array $options): array
    {
        $now = time();
        $groups = array_column($this->users->groupsOf($user), 'name');
        $common = [
            'sub' => $user['attributes']['sub'],
            'iss' => $this->config->issuer(),
            'origin_jti' => $originJti,
            'event_id' => UserPool::uuid(),
            'auth_time' => $authTime,
            'iat' => $now,
        ];
        if ($groups !== []) {
            $common['cognito:groups'] = $groups;
        }

        $access = $common + [
            'client_id' => $clientId,
            'token_use' => 'access',
            'scope' => implode(' ', $scopes),
            'exp' => $now + $this->config->accessTokenTtl,
            'jti' => UserPool::uuid(),
            'username' => $user['username'],
        ];
        $accessToken = $this->sign($access);

        $tokens = [
            'AccessToken' => $accessToken,
            'ExpiresIn' => $this->config->accessTokenTtl,
            'TokenType' => 'Bearer',
        ];

        if ($options['includeIdToken'] ?? true) {
            $id = $common + self::idTokenAttributes($user['attributes']) + [
                'cognito:username' => $user['username'],
                'aud' => $clientId,
                'token_use' => 'id',
                'exp' => $now + $this->config->idTokenTtl,
                'jti' => UserPool::uuid(),
            ];
            if (isset($options['nonce'])) {
                $id['nonce'] = $options['nonce'];
            }
            if (isset($options['atHash'])) {
                $id['at_hash'] = self::base64url(substr(hash('sha256', $accessToken, true), 0, 16));
            }
            $tokens['IdToken'] = $this->sign($id);
        }

        return $tokens;
    }

    /**
     * ID トークンに入れる属性。*_verified は真偽値になる。
     */
    private static function idTokenAttributes(array $attributes): array
    {
        unset($attributes['sub']);
        foreach ($attributes as $name => $value) {
            if (str_ends_with($name, '_verified')) {
                $attributes[$name] = $value === 'true';
            }
        }

        return $attributes;
    }

    private function sign(array $claims): string
    {
        $key = $this->signingKey();

        return JWT::encode($claims, $key['privateKey'], 'RS256', $key['kid']);
    }

    // ------------------------------------------------------------------
    // 検証・失効
    // ------------------------------------------------------------------

    /**
     * Cognito API の AccessToken パラメータを検証し、ユーザーを返す。
     */
    public function userFromAccessToken(?string $token, bool $requireAdminScope = true): array
    {
        if ($token === null || $token === '') {
            throw CognitoException::missing('AccessToken');
        }

        try {
            $claims = (array) JWT::decode($token, JWK::parseKeySet($this->jwks()));
        } catch (\Throwable $e) {
            $message = str_contains($e->getMessage(), 'Expired') ? 'Access Token has expired' : 'Invalid Access Token';
            throw CognitoException::notAuthorized($message);
        }

        if (($claims['token_use'] ?? null) !== 'access' || ($claims['iss'] ?? null) !== $this->config->issuer()) {
            throw CognitoException::notAuthorized('Invalid Access Token');
        }
        if ($requireAdminScope && !in_array(self::ADMIN_SCOPE, explode(' ', $claims['scope'] ?? ''), true)) {
            throw CognitoException::notAuthorized('Access Token does not have required scopes');
        }

        $session = $this->store->read()['sessions'][$claims['origin_jti'] ?? ''] ?? null;
        if ($session === null) {
            throw CognitoException::notAuthorized('Access Token has been revoked');
        }

        $user = $this->users->find($session['username']);
        if ($user === null || $user['attributes']['sub'] !== ($claims['sub'] ?? null)) {
            throw CognitoException::notAuthorized('Invalid Access Token');
        }
        if (!$user['enabled']) {
            throw CognitoException::notAuthorized('User is disabled.');
        }

        return $user;
    }

    public function revokeRefreshToken(string $refreshToken, ?string $clientId): void
    {
        $found = $this->findSession($refreshToken);
        if ($found === null) {
            return;
        }
        if ($clientId !== null && $found[1]['clientId'] !== $clientId) {
            throw new CognitoException('UnauthorizedException', 'Client is not authorized to revoke this token');
        }

        $this->store->update(function (array &$state) use ($found) {
            unset($state['sessions'][$found[0]]);
        });
    }

    public function signOutEverywhere(array $user): void
    {
        $this->store->update(function (array &$state) use ($user) {
            foreach ($state['sessions'] ?? [] as $id => $session) {
                if ($session['username'] === $user['username']) {
                    unset($state['sessions'][$id]);
                }
            }
        });
    }

    /**
     * @return array{0: string, 1: array}|null
     */
    private function findSession(string $refreshToken): ?array
    {
        $hash = hash('sha256', $refreshToken);
        foreach ($this->store->read()['sessions'] ?? [] as $id => $session) {
            if (hash_equals($session['refreshTokenHash'], $hash)) {
                return $session['expiresAt'] >= time() ? [$id, $session] : null;
            }
        }

        return null;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
