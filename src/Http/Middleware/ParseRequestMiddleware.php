<?php

namespace MiniCog\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * クエリ・Cookie・フォームのボディが未解析なら解析して ServerRequest に入れる。
 *
 * PHP の SAPI から作ったリクエスト（ServerRequest::fromGlobals など）は解析済みだが、
 * AWS SDK の http_handler や、組み立てたリクエストを渡された場合はヘッダーとボディしかない。
 */
final class ParseRequestMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getQueryParams() === [] && $request->getUri()->getQuery() !== '') {
            parse_str($request->getUri()->getQuery(), $query);
            $request = $request->withQueryParams($query);
        }

        if ($request->getCookieParams() === [] && $request->hasHeader('Cookie')) {
            $cookies = [];
            foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
                [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
                if ($name !== '') {
                    $cookies[$name] = urldecode($value);
                }
            }
            $request = $request->withCookieParams($cookies);
        }

        if ($request->getParsedBody() === null
            && str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
            $body = $request->getBody();
            parse_str((string) $body, $form);
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $request = $request->withParsedBody($form);
        }

        return $handler->handle($request);
    }
}
