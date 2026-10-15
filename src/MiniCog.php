<?php

namespace MiniCog;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use MiniCog\Cognito\CognitoService;
use MiniCog\Cognito\UserPool;
use MiniCog\Http\App;
use MiniCog\Store\FileStore;
use MiniCog\Store\MemoryStore;
use MiniCog\Store\StoreInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MiniCog のエントリーポイント。
 *
 * PSR-15 のリクエストハンドラなので、サーバーとして動かす場合は handle() に PSR-7 リクエストを渡す
 * （任意の PSR-15 ランナーにもそのまま渡せる）。
 * サーバーを立てずに使う場合は httpHandler() を AWS SDK の http_handler に渡す:
 *
 *     $minicog = MiniCog::create(['userPoolId' => 'ap-northeast-1_example']);
 *     $client = new CognitoIdentityProviderClient([
 *         'region' => 'ap-northeast-1',
 *         'version' => 'latest',
 *         'credentials' => false,
 *         'http_handler' => $minicog->httpHandler(),
 *     ]);
 */
final class MiniCog implements RequestHandlerInterface
{
    public readonly UserPool $users;
    public readonly TokenService $tokens;
    public readonly CognitoService $cognito;
    private readonly App $app;

    public function __construct(
        public readonly Config $config,
        public readonly StoreInterface $store = new MemoryStore(),
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->users = new UserPool($store, $config);
        $this->tokens = new TokenService($store, $config, $this->users);
        $this->cognito = new CognitoService($config, $this->users, $this->tokens, $logger);
        $this->app = new App($config, $this->cognito, $this->users, $this->tokens, $logger);
    }

    /**
     * @param array $config Config::fromArray() の設定に加え、dataFile（JSON 永続化先）を指定できる
     */
    public static function create(array $config = [], ?LoggerInterface $logger = null): self
    {
        $store = isset($config['dataFile']) ? new FileStore($config['dataFile']) : new MemoryStore();

        return new self(Config::fromArray($config), $store, $logger ?? new NullLogger());
    }

    /**
     * PSR-15 の handle(ServerRequestInterface) より広い RequestInterface も受け付ける（引数の反変）。
     */
    public function handle(RequestInterface $request): ResponseInterface
    {
        return $this->app->handle($request);
    }

    /**
     * AWS SDK for PHP の http_handler として使える callable。
     *
     * @return callable(RequestInterface, array): PromiseInterface
     */
    public function httpHandler(): callable
    {
        return function (RequestInterface $request, array $options = []): PromiseInterface {
            $response = $this->handle($request);
            if ($response->getStatusCode() < 400) {
                return Create::promiseFor($response);
            }

            // SDK の GuzzleHandler と同じ形で reject すると、SDK がエラーボディを解析して
            // CognitoIdentityProviderException（getAwsErrorCode() が使える）を投げる
            return Create::rejectionFor([
                'exception' => new \RuntimeException(sprintf(
                    'Error executing "%s" on "%s"; HTTP %d',
                    $request->getMethod(),
                    $request->getUri(),
                    $response->getStatusCode(),
                )),
                'connection_error' => false,
                'response' => $response,
            ]);
        };
    }

    /**
     * 確認コードなどを経由せず、すぐにサインインできるユーザーを作る（テストのシード用）。
     *
     * @param array<string, string> $attributes
     */
    public function createConfirmedUser(string $username, string $password, array $attributes = []): array
    {
        if (isset($attributes['email']) && !isset($attributes['email_verified'])) {
            $attributes['email_verified'] = 'true';
        }

        return $this->users->create($username, $password, $attributes, UserPool::STATUS_CONFIRMED);
    }

    public function jwks(): array
    {
        return $this->tokens->jwks();
    }
}
