<?php

namespace MiniCog\Http;

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

/**
 * PSR-7 リクエストを受けてレスポンスを返す HTTP アプリケーション。
 * 組み込みサーバー（public/index.php）と AWS SDK の http_handler の両方から使う。
 */
final class App
{
    private readonly OAuthController $oauth;
    private readonly AdminController $admin;

    public function __construct(
        private readonly Config $config,
        private readonly CognitoService $cognito,
        UserPool $users,
        private readonly TokenService $tokens,
        private readonly LoggerInterface $logger,
    ) {
        $this->oauth = new OAuthController($config, $users, $tokens);
        $this->admin = new AdminController($config, $users);
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
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $pool = preg_quote($this->config->userPoolId, '#');

        // Cognito API（AWS JSON 1.1）
        if ($method === 'POST' && $request->hasHeader('X-Amz-Target')) {
            return $this->cognitoApi($request);
        }

        return match (true) {
            $method === 'GET' && (bool) preg_match("#^/$pool/\\.well-known/jwks\\.json$#", $path) => self::json($this->tokens->jwks()),
            $method === 'GET' && (bool) preg_match("#^/$pool/\\.well-known/openid-configuration$#", $path) => $this->oauth->openidConfiguration(),
            $method === 'GET' && $path === '/oauth2/authorize' => $this->oauth->authorize($request),
            $method === 'GET' && $path === '/login' => $this->oauth->authorize($request),
            $method === 'POST' && $path === '/login' => $this->oauth->login($request),
            $method === 'POST' && $path === '/oauth2/token' => $this->oauth->token($request),
            in_array($method, ['GET', 'POST'], true) && strcasecmp($path, '/oauth2/userInfo') === 0 => $this->oauth->userInfo($request),
            $method === 'POST' && $path === '/oauth2/revoke' => $this->oauth->revoke($request),
            $method === 'GET' && $path === '/logout' => $this->oauth->logout($request),
            $method === 'GET' && ($path === '/' || $path === '/admin') => $this->admin->index($request),
            $method === 'POST' && $path === '/admin/users' => $this->admin->create($request),
            $method === 'POST' && (bool) preg_match('#^/admin/users/([^/]+)/(confirm|delete)$#', $path, $m) => $this->adminAction($m[2], rawurldecode($m[1])),
            $method === 'GET' && $path === '/health' => self::json(['status' => 'ok']),
            default => self::json(['error' => 'Not Found'], 404),
        };
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
