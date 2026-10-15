<?php

namespace MiniCog\Http;

use MiniCog\Cognito\CognitoException;
use MiniCog\Cognito\CognitoService;
use MiniCog\Cognito\UserPool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cognito User Pools API（AWS JSON 1.1）。X-Amz-Target ヘッダーでオペレーションを選ぶ。
 */
final class CognitoApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CognitoService $cognito,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function accepts(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'POST' && $request->hasHeader('X-Amz-Target');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
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

            return Json::response($result === [] ? (object) [] : $result, 200, [
                'Content-Type' => 'application/x-amz-json-1.1',
                'x-amzn-RequestId' => $requestId,
            ]);
        } catch (CognitoException $e) {
            $this->logger->info("[MiniCog] $operation failed", ['type' => $e->type, 'message' => $e->getMessage()]);

            return Json::response(['__type' => $e->type, 'message' => $e->getMessage()], $e->status, [
                'Content-Type' => 'application/x-amz-json-1.1',
                'x-amzn-RequestId' => $requestId,
                'x-amzn-ErrorType' => $e->type,
            ]);
        }
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
}
