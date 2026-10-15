<?php

namespace MiniCog\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use MiniCog\Http\Middleware\CorsMiddleware;
use MiniCog\Http\Middleware\ErrorHandlerMiddleware;
use MiniCog\Http\Middleware\ParseRequestMiddleware;
use MiniCog\Http\Pipeline;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;

final class Psr15Test extends TestCase
{
    public function testMiniCogIsARequestHandler(): void
    {
        $this->assertInstanceOf(RequestHandlerInterface::class, $this->minicog);

        // 他の PSR-15 のミドルウェアと組み合わせられる
        $stamp = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute('seen', true))->withHeader('X-Outer', 'yes');
            }
        };
        $response = (new Pipeline([$stamp], $this->minicog))->handle(new ServerRequest('GET', 'http://localhost/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Outer'));
        $this->assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testAcceptsPlainRequestFromSdk(): void
    {
        $response = $this->minicog->handle(new Request('GET', 'http://localhost/health'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPipelineRunsMiddlewaresInOrder(): void
    {
        $log = [];
        $middleware = function (string $name) use (&$log) {
            return new class ($name, $log) implements MiddlewareInterface {
                public function __construct(private string $name, private array &$log)
                {
                }

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $this->log[] = "{$this->name}:before";
                    $response = $handler->handle($request);
                    $this->log[] = "{$this->name}:after";

                    return $response;
                }
            };
        };

        (new Pipeline([$middleware('a'), $middleware('b')], self::handler(function () use (&$log) {
            $log[] = 'handler';

            return new Response(204);
        })))->handle(new ServerRequest('GET', '/'));

        $this->assertSame(['a:before', 'b:before', 'handler', 'b:after', 'a:after'], $log);
    }

    public function testErrorHandlerMiddleware(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        $response = (new ErrorHandlerMiddleware($logger))->process(
            new ServerRequest('GET', '/boom'),
            self::handler(fn () => throw new \LogicException('boom')),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['error' => 'InternalError', 'message' => 'boom'], json_decode((string) $response->getBody(), true));
        $this->assertSame('error', $logger->records[0][0]);
        $this->assertSame('/boom', $logger->records[0][2]['path']);
    }

    public function testCorsMiddleware(): void
    {
        $cors = new CorsMiddleware();
        $ok = self::handler(fn () => new Response(200));

        $preflight = $cors->process(new ServerRequest('OPTIONS', '/', ['Origin' => 'http://localhost:3000']), self::handler(fn () => $this->fail('preflight must not reach the handler')));
        $this->assertSame(204, $preflight->getStatusCode());
        $this->assertSame('http://localhost:3000', $preflight->getHeaderLine('Access-Control-Allow-Origin'));

        $simple = $cors->process(new ServerRequest('POST', '/', ['Origin' => 'http://localhost:3000']), $ok);
        $this->assertSame('http://localhost:3000', $simple->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $simple->getHeaderLine('Vary'));

        $sameOrigin = $cors->process(new ServerRequest('GET', '/'), $ok);
        $this->assertFalse($sameOrigin->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testParseRequestMiddleware(): void
    {
        $captured = null;
        $capture = self::handler(function (ServerRequestInterface $request) use (&$captured) {
            $captured = $request;

            return new Response(204);
        });

        (new ParseRequestMiddleware())->process(new ServerRequest('POST', '/login?a=1&b=two', [
            'Cookie' => 'minicog_session=abc; other=x%20y',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
        ], 'username=alice&password=p%40ss'), $capture);

        $this->assertSame(['a' => '1', 'b' => 'two'], $captured->getQueryParams());
        $this->assertSame(['minicog_session' => 'abc', 'other' => 'x y'], $captured->getCookieParams());
        $this->assertSame(['username' => 'alice', 'password' => 'p@ss'], $captured->getParsedBody());
        $this->assertSame('username=alice&password=p%40ss', (string) $captured->getBody(), 'ボディは読み直せる');

        // SAPI が解析済みの値は上書きしない
        (new ParseRequestMiddleware())->process(
            (new ServerRequest('GET', '/?a=1', ['Cookie' => 'x=1']))->withQueryParams(['a' => 'kept'])->withCookieParams(['x' => 'kept']),
            $capture,
        );
        $this->assertSame(['a' => 'kept'], $captured->getQueryParams());
        $this->assertSame(['x' => 'kept'], $captured->getCookieParams());
    }

    private static function handler(\Closure $handle): RequestHandlerInterface
    {
        return new class ($handle) implements RequestHandlerInterface {
            public function __construct(private \Closure $handle)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };
    }
}
