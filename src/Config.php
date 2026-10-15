<?php

namespace MiniCog;

/**
 * MiniCog の設定。ユーザープールは 1 つだけ持つ。
 *
 * 配列（JSON 設定ファイル）または環境変数から生成する。
 */
final class Config
{
    /**
     * @param list<array{clientId: string, clientSecret?: string|null, clientName?: string, callbackUrls?: list<string>, logoutUrls?: list<string>, allowedScopes?: list<string>}> $clients
     *        空なら任意の ClientId を受け付ける（ゆるいモード）。
     * @param list<string> $usernameAttributes email / phone_number をユーザー名として使う場合に指定
     * @param list<string> $aliasAttributes    email / phone_number / preferred_username でのサインインを許可
     * @param array{MinimumLength?: int, RequireUppercase?: bool, RequireLowercase?: bool, RequireNumbers?: bool, RequireSymbols?: bool} $passwordPolicy
     * @param string|null $fixedCode 確認コードを固定値にする（E2E テスト向け）。null ならランダム 6 桁
     */
    public function __construct(
        public readonly string $userPoolId = 'ap-northeast-1_minicog',
        public readonly string $userPoolName = 'MiniCogUserPool',
        public readonly string $baseUrl = 'http://localhost:9229',
        public readonly ?string $issuer = null,
        public readonly array $clients = [],
        public readonly array $usernameAttributes = [],
        public readonly array $aliasAttributes = [],
        public readonly array $passwordPolicy = ['MinimumLength' => 8],
        public readonly ?string $fixedCode = null,
        public readonly int $accessTokenTtl = 3600,
        public readonly int $idTokenTtl = 3600,
        public readonly int $refreshTokenTtl = 2592000,
    ) {
    }

    public static function fromArray(array $config): self
    {
        $known = [
            'userPoolId', 'userPoolName', 'baseUrl', 'issuer', 'clients', 'usernameAttributes',
            'aliasAttributes', 'passwordPolicy', 'fixedCode', 'accessTokenTtl', 'idTokenTtl', 'refreshTokenTtl',
        ];

        return new self(...array_intersect_key($config, array_flip($known)));
    }

    /**
     * MINICOG_CONFIG（JSON ファイル）を読み、個別の環境変数で上書きする。
     */
    public static function fromEnv(): self
    {
        $config = [];

        $file = getenv('MINICOG_CONFIG');
        if ($file !== false && $file !== '') {
            $json = @file_get_contents($file);
            if ($json === false) {
                throw new \RuntimeException("Cannot read MINICOG_CONFIG: $file");
            }
            $config = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }

        $env = [
            'MINICOG_USER_POOL_ID' => 'userPoolId',
            'MINICOG_BASE_URL' => 'baseUrl',
            'MINICOG_ISSUER' => 'issuer',
            'MINICOG_FIXED_CODE' => 'fixedCode',
        ];
        foreach ($env as $name => $key) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $config[$key] = $value;
            }
        }

        return self::fromArray($config);
    }

    public function region(): string
    {
        return explode('_', $this->userPoolId, 2)[0];
    }

    public function issuer(): string
    {
        return $this->issuer ?? rtrim($this->baseUrl, '/') . '/' . $this->userPoolId;
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    /**
     * クライアント設定を返す。clients が空のときは任意の ClientId を秘密鍵なしのクライアントとして扱う。
     */
    public function client(string $clientId): ?array
    {
        if ($this->clients === []) {
            return ['clientId' => $clientId, 'clientSecret' => null];
        }

        foreach ($this->clients as $client) {
            if (($client['clientId'] ?? null) === $clientId) {
                return $client + ['clientSecret' => null];
            }
        }

        return null;
    }
}
