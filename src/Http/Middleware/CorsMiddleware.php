<?php

namespace MiniCog\Http\Middleware;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * ブラウザ（Amplify など）から直接呼べるように、すべてのオリジンに CORS を許可する。
 * OPTIONS（プリフライト）はここで 204 を返す。
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $request->getMethod() === 'OPTIONS'
            ? new Response(204)
            : $handler->handle($request);

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
            ->withHeader('Access-Control-Max-Age', '600')
            ->withAddedHeader('Vary', 'Origin');
    }
}
