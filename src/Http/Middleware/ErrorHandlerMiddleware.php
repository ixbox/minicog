<?php

namespace MiniCog\Http\Middleware;

use MiniCog\Http\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 想定外の例外をログに出し、500 の JSON にする。
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error('[MiniCog] Unhandled error', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Json::response(['error' => 'InternalError', 'message' => $e->getMessage()], 500);
        }
    }
}
