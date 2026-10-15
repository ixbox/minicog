<?php

namespace MiniCog\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use GuzzleHttp\Psr7\Response;
use MiniCog\Config;
use MiniCog\Http\Admin\AdminRoutes;
use MiniCog\OAuth\OAuthController;
use MiniCog\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function FastRoute\simpleDispatcher;

/**
 * パスとメソッドでハンドラを選ぶ。Cognito API だけは X-Amz-Target ヘッダーで判定する。
 */
final class Router implements RequestHandlerInterface
{
    private readonly Dispatcher $dispatcher;

    public function __construct(
        private readonly Config $config,
        private readonly TokenService $tokens,
        private readonly CognitoApiHandler $cognitoApi,
        private readonly OAuthController $oauth,
        private readonly AdminRoutes $admin,
    ) {
        $this->dispatcher = simpleDispatcher($this->routes(...));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (CognitoApiHandler::accepts($request)) {
            return $this->cognitoApi->handle($request);
        }

        $route = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        return match ($route[0]) {
            Dispatcher::FOUND => $route[1]($request, array_map('rawurldecode', $route[2])),
            Dispatcher::METHOD_NOT_ALLOWED => Json::response(['error' => 'Method Not Allowed'], 405)
                ->withHeader('Allow', implode(', ', $route[1])),
            default => Json::response(['error' => 'Not Found'], 404),
        };
    }

    /**
     * ハンドラは (ServerRequestInterface $request, array $vars): ResponseInterface。
     */
    private function routes(RouteCollector $r): void
    {
        // OIDC
        $r->get('/{pool}/.well-known/jwks.json', $this->forPool(fn () => Json::response($this->tokens->jwks())));
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

        // 管理画面と静的ファイル
        $r->get('/', fn () => new Response(302, ['Location' => '/admin']));
        $this->admin->register($r);
        $r->get('/assets/{file:[a-z0-9.\-]+}', fn ($request, array $vars) => self::asset($request, $vars['file']));

        $r->get('/health', fn () => Json::response(['status' => 'ok']));
    }

    /**
     * {pool} が設定のユーザープール ID と一致するときだけハンドラを呼ぶ。
     */
    private function forPool(\Closure $handler): \Closure
    {
        return fn (ServerRequestInterface $request, array $vars) => $vars['pool'] === $this->config->userPoolId
            ? $handler($request, $vars)
            : Json::response(['error' => 'Not Found'], 404);
    }

    /**
     * 同梱の CSS / JS（assets/）を返す。phar やオフラインでも CDN なしで動くように同梱している。
     */
    private static function asset(ServerRequestInterface $request, string $file): ResponseInterface
    {
        $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];
        $path = View::ASSETS_DIR . '/' . $file;
        $type = $types[pathinfo($file, PATHINFO_EXTENSION)] ?? null;
        if ($type === null || !is_file($path)) {
            return Json::response(['error' => 'Not Found'], 404);
        }

        // テンプレートは ?v=<内容のハッシュ> 付きで参照するので、その URL は長くキャッシュしてよい。
        // ハッシュなしで参照された場合は、古いファイルが残らないよう毎回 ETag で再検証させる
        $etag = '"' . md5_file($path) . '"';
        $versioned = ($request->getQueryParams()['v'] ?? null) === substr(trim($etag, '"'), 0, 10);
        $headers = [
            'Content-Type' => $type,
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'no-cache',
            'ETag' => $etag,
        ];
        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new Response(304, $headers);
        }

        return new Response(200, $headers, file_get_contents($path));
    }
}
