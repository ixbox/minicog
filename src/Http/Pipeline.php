<?php

namespace MiniCog\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 のミドルウェアを先頭から順に通し、最後にハンドラを呼ぶ。
 */
final class Pipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly RequestHandlerInterface $handler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middlewares === []) {
            return $this->handler->handle($request);
        }

        [$first, $rest] = [$this->middlewares[0], array_slice($this->middlewares, 1)];

        return $first->process($request, new self($rest, $this->handler));
    }
}
