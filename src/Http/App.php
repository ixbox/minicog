<?php

namespace MiniCog\Http;

use GuzzleHttp\Psr7\ServerRequest;
use MiniCog\Cognito\CognitoService;
use MiniCog\Cognito\UserPool;
use MiniCog\Config;
use MiniCog\Http\Admin\AdminRoutes;
use MiniCog\Http\Middleware\CorsMiddleware;
use MiniCog\Http\Middleware\ErrorHandlerMiddleware;
use MiniCog\Http\Middleware\ParseRequestMiddleware;
use MiniCog\OAuth\OAuthController;
use MiniCog\TokenService;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * MiniCog の HTTP アプリケーション（PSR-15 のリクエストハンドラ）。
 *
 * CORS → 例外処理 → リクエストの解析 → ルーター の順に通す。
 * 組み込みサーバー（public/index.php）、AWS SDK の http_handler、任意の PSR-15 ランナーから使う。
 */
final class App implements RequestHandlerInterface
{
    private readonly RequestHandlerInterface $pipeline;

    public function __construct(
        Config $config,
        CognitoService $cognito,
        UserPool $users,
        TokenService $tokens,
        LoggerInterface $logger,
    ) {
        $view = new View(['config' => $config], $config->templatesDir);
        $router = new Router(
            $config,
            $tokens,
            new CognitoApiHandler($cognito, $logger),
            new OAuthController($config, $users, $tokens, $view),
            new AdminRoutes($view, $config, $users, $tokens),
        );

        $this->pipeline = new Pipeline([
            new CorsMiddleware(),
            new ErrorHandlerMiddleware($logger),
            new ParseRequestMiddleware(),
        ], $router);
    }

    /**
     * PSR-15 の handle(ServerRequestInterface) より広い RequestInterface を受け付ける（引数の反変）。
     * AWS SDK の http_handler からは ServerRequest ではない素のリクエストが来るため。
     */
    public function handle(RequestInterface $request): ResponseInterface
    {
        if (!$request instanceof ServerRequestInterface) {
            $request = new ServerRequest(
                $request->getMethod(),
                $request->getUri(),
                $request->getHeaders(),
                $request->getBody(),
                $request->getProtocolVersion(),
            );
        }

        return $this->pipeline->handle($request);
    }
}
