<?php

namespace MiniCog\Tests;

use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use MiniCog\MiniCog;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const POOL_ID = 'ap-northeast-1_test';
    protected const CLIENT_ID = 'test-client';

    protected MiniCog $minicog;
    protected CognitoIdentityProviderClient $client;

    protected function setUp(): void
    {
        $this->boot();
    }

    protected function boot(array $config = []): void
    {
        $this->minicog = MiniCog::create($config + [
            'userPoolId' => self::POOL_ID,
            'fixedCode' => '123456',
        ]);
        $this->client = new CognitoIdentityProviderClient([
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'credentials' => false,
            'http_handler' => $this->minicog->httpHandler(),
        ]);
    }

    protected function decode(string $jwt): array
    {
        return (array) JWT::decode($jwt, JWK::parseKeySet($this->minicog->jwks()));
    }

    /**
     * @return string AWS のエラーコード
     */
    protected function expectCognitoError(string $code, callable $callback): CognitoIdentityProviderException
    {
        try {
            $callback();
        } catch (CognitoIdentityProviderException $e) {
            $this->assertSame($code, $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?? '');

            return $e;
        }

        $this->fail("Expected $code");
    }

    protected function login(string $username, string $password, string $clientId = self::CLIENT_ID): array
    {
        return $this->client->initiateAuth([
            'AuthFlow' => 'USER_PASSWORD_AUTH',
            'ClientId' => $clientId,
            'AuthParameters' => ['USERNAME' => $username, 'PASSWORD' => $password],
        ])['AuthenticationResult'];
    }
}
