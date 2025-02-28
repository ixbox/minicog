<?php

require __DIR__ . '/../vendor/autoload.php';

// 標準出力にログを出力するロガーの設定
$logger = new class extends Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        error_log(sprintf("[%s] %s %s\n", $level, $message, json_encode($context)), 3, "php://stdout");
    }
};

function sendResponse(array $data = null, int $statusCode = 200): void
{
    header('Content-Type: application/x-amz-json-1.1');
    http_response_code($statusCode);

    if ($data !== null) {
        echo json_encode($data);
    }
    exit;
}

function sendError(string $type, string $message, int $statusCode = 400): void
{
    $data = [
        '__type' => "com.amazonaws.cognito.identity.idp#$type",
        'message' => $message
    ];
    sendResponse($data, $statusCode);
}

try {
    $factory = new Http\Discovery\Psr17Factory();
    $request = $factory->createServerRequestFromGlobals();
    $method = $request->getMethod();
    $uri = $request->getUri()->getPath();

    // リクエストの基本検証
    if ($method !== 'POST' || $uri !== '/') {
        sendError('NotFoundError', 'Endpoint not found', 404);
    }

    // x-amz-targetの検証
    $target = $request->getHeaderLine('x-amz-target');
    if (empty($target)) {
        sendError('ValidationError', 'Missing required header: x-amz-target');
    }

    [$service, $name] = explode('.', $target, 2);
    if (strtolower($service) !== 'awscognitoidentityproviderservice') {
        sendError('ValidationError', 'Invalid service name in x-amz-target');
    }

    // リクエストボディの解析
    $body = $request->getBody()->getContents();
    $args = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('ValidationError', 'Invalid JSON in request body');
    }

    // ユーザーハンドラーとハンドラーの初期化
    $userHandler = new MiniCog\UserHandler\MemoryUserHandler();
    $handler = new MiniCog\Handler($logger, $userHandler);

    // コマンドの実行
    $result = $handler(new Aws\Command($name, $args), $request)->wait();
    sendResponse($result->toArray());
} catch (\InvalidArgumentException $e) {
    sendError('ValidationError', $e->getMessage());
} catch (\Exception $e) {
    $logger->error('[MiniCog] Unexpected error', ['error' => $e->getMessage()]);
    sendError('InternalErrorException', 'An internal error has occurred');
}
