<?php

namespace MiniCog\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use MiniCog\Cognito\CognitoException;
use MiniCog\Cognito\CognitoService;
use MiniCog\Cognito\UserPool;
use MiniCog\Config;
use MiniCog\OAuth\OAuthController;
use MiniCog\TokenService;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

use function FastRoute\simpleDispatcher;

/**
 * PSR-7 リクエストを受けてレスポンスを返す HTTP アプリケーション。
 * 組み込みサーバー（public/index.php）と AWS SDK の http_handler の両方から使う。
 */
final class App
{
    private readonly OAuthController $oauth;
    private readonly AdminController $admin;
    private readonly Dispatcher $dispatcher;

    public function __construct(
        private readonly Config $config,
        private readonly CognitoService $cognito,
        UserPool $users,
        private readonly TokenService $tokens,
        private readonly LoggerInterface $logger,
    ) {
        $view = new View(['config' => $config], $config->templatesDir);
        $this->oauth = new OAuthController($config, $users, $tokens, $view);
        $this->admin = new AdminController($users, $view);
        $this->dispatcher = simpleDispatcher($this->routes(...));
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        $request = self::toServerRequest($request);

        if ($request->getMethod() === 'OPTIONS') {
            return $this->cors($request, new Response(204));
        }

        try {
            $response = $this->route($request);
        } catch (\Throwable $e) {
            $this->logger->error('[MiniCog] Unhandled error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $response = self::json(['error' => 'InternalError', 'message' => $e->getMessage()], 500);
        }

        return $this->cors($request, $response);
    }

    private function route(ServerRequestInterface $request): ResponseInterface
    {
        // Cognito API（AWS JSON 1.1）はパスではなく X-Amz-Target ヘッダーで呼び分ける
        if ($request->getMethod() === 'POST' && $request->hasHeader('X-Amz-Target')) {
            return $this->cognitoApi($request);
        }

        $route = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        return match ($route[0]) {
            Dispatcher::FOUND => $route[1]($request, array_map('rawurldecode', $route[2])),
            Dispatcher::METHOD_NOT_ALLOWED => self::json(['error' => 'Method Not Allowed'], 405)
                ->withHeader('Allow', implode(', ', $route[1])),
            default => self::json(['error' => 'Not Found'], 404),
        };
    }

    /**
     * ハンドラは (ServerRequestInterface $request, array $vars): ResponseInterface。
     */
    private function routes(RouteCollector $r): void
    {
        // OIDC
        $r->get('/{pool}/.well-known/jwks.json', $this->forPool(fn () => self::json($this->tokens->jwks())));
        $r->get('/{pool}/.well-known/openid-configuration', $this->forPool(fn () => $this->oauth->openidConfiguration()));

        // OAuth 2.0 とマネージドログイン
        $r->get('/oauth2/authorize', fn ($request) => $this->oauth->authorize($request));
        $r->get('/login', fn ($request) => $this->oauth->authorize($request));
        $r->post('/login', fn ($request) => $this->oauth->login($request));
        $r->post('/oauth2/token', fn ($request) => $this->oauth->token($request));
        $r->addRoute(['GET', 'POST'], '/oauth2/userInfo', fn ($request) => $this->oauth->userInfo($request));
        $r->addRoute(['GET', 'POST'], '/oauth2/userinfo', fn ($request) => $this->oauth->userInfo($request));
        $r->post('/oauth2/revoke', fn ($request) => $this->oauth->revoke($request));
        $r->get('/logout', fn ($request) => $this->oauth->logout($request));

        // 管理画面
        $r->get('/', fn ($request) => $this->admin->index($request));
        $r->get('/admin', fn ($request) => $this->admin->index($request));
        $r->post('/admin/users', fn ($request) => $this->admin->create($request));
        $r->post('/admin/users/{username}/{action:confirm|delete}', fn ($request, array $vars) => $this->adminAction($vars['action'], $vars['username']));

        $r->get('/health', fn () => self::json(['status' => 'ok']));
    }

    /**
     * {pool} が設定のユーザープール ID と一致するときだけハンドラを呼ぶ。
     */
    private function forPool(\Closure $handler): \Closure
    {
        return fn (ServerRequestInterface $request, array $vars) => $vars['pool'] === $this->config->userPoolId
            ? $handler($request, $vars)
            : self::json(['error' => 'Not Found'], 404);
    }

    private function adminAction(string $action, string $username): ResponseInterface
    {
        try {
            return $action === 'confirm' ? $this->admin->confirm($username) : $this->admin->delete($username);
        } catch (CognitoException $e) {
            return new Response(303, ['Location' => '/admin?' . http_build_query(['error' => $e->getMessage()])]);
        }
    }

    private function cognitoApi(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = UserPool::uuid();
        $target = $request->getHeaderLine('X-Amz-Target');
        [$service, $operation] = array_pad(explode('.', $target, 2), 2, '');

        try {
            if (strcasecmp($service, 'AWSCognitoIdentityProviderService') !== 0) {
                throw new CognitoException('UnknownOperationException', "Unknown target: $target");
            }

            $body = (string) $request->getBody();
            $args = $body === '' ? [] : json_decode($body, true);
            if (!is_array($args)) {
                throw new CognitoException('SerializationException', 'Request body is not valid JSON');
            }

            $this->logger->debug("[MiniCog] $operation", ['args' => self::redact($args)]);
            $result = $this->cognito->call($operation, $args);

            return self::json($result === [] ? (object) [] : $result, 200, [
                'Content-Type' => 'application/x-amz-json-1.1',
                'x-amzn-RequestId' => $requestId,
            ]);
        } catch (CognitoException $e) {
            $this->logger->info("[MiniCog] $operation failed", ['type' => $e->type, 'message' => $e->getMessage()]);

            return self::json(['__type' => $e->type, 'message' => $e->getMessage()], $e->status, [
                'Content-Type' => 'application/x-amz-json-1.1',
                'x-amzn-RequestId' => $requestId,
                'x-amzn-ErrorType' => $e->type,
            ]);
        }
    }

    /**
     * ブラウザ（Amplify など）から直接呼べるように CORS を許可する。
     */
    private function cors(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', $request->getHeaderLine('Access-Control-Request-Headers') ?: '*')
            ->withHeader('Access-Control-Expose-Headers', 'x-amzn-RequestId, x-amzn-ErrorType')
            ->withHeader('Access-Control-Max-Age', '600');
    }

    /**
     * SDK から渡される素の RequestInterface を、クエリ・フォーム・Cookie を解析済みの ServerRequest にする。
     */
    private static function toServerRequest(RequestInterface $request): ServerRequestInterface
    {
        if ($request instanceof ServerRequestInterface) {
            $server = $request;
        } else {
            $server = new ServerRequest(
                $request->getMethod(),
                $request->getUri(),
                $request->getHeaders(),
                $request->getBody(),
                $request->getProtocolVersion(),
            );
        }

        if ($server->getQueryParams() === []) {
            parse_str($server->getUri()->getQuery(), $query);
            $server = $server->withQueryParams($query);
        }

        if ($server->getCookieParams() === [] && $server->hasHeader('Cookie')) {
            $cookies = [];
            foreach (explode(';', $server->getHeaderLine('Cookie')) as $pair) {
                [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
                if ($name !== '') {
                    $cookies[$name] = urldecode($value);
                }
            }
            $server = $server->withCookieParams($cookies);
        }

        if ($server->getParsedBody() === null
            && str_starts_with(strtolower($server->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
            parse_str((string) $server->getBody(), $form);
            $server = $server->withParsedBody($form);
            $server->getBody()->rewind();
        }

        return $server;
    }

    private static function redact(array $args): array
    {
        array_walk_recursive($args, function (&$value, $key) {
            if (is_string($key) && preg_match('/password|secret|token/i', $key)) {
                $value = '***';
            }
        });

        return $args;
    }

    private static function json(array|object $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
