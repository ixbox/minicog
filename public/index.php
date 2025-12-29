<?php

require __DIR__ . '/../vendor/autoload.php';

use MiniCog\AdminController;
use MiniCog\Handler;
use MiniCog\NanoLogger;
use MiniCog\UserHandler\FileUserHandler;

// 設定
$debug = (bool) getenv('MINICOG_DEBUG');
$dataFile = getenv('MINICOG_DATA_FILE') ?: __DIR__ . '/../data/users.json';

// 依存性の生成
$logger = new NanoLogger($debug);
$userHandler = new FileUserHandler($dataFile);
$adminController = new AdminController($logger, $userHandler);

// URIからクエリ文字列を除去
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ルーティング
$routes = [
    'GET' => [
        '/admin' => fn() => $adminController->index(),
        '/admin/users' => fn() => $adminController->list(),
    ],
    'POST' => [
        '/' => fn() => Handler::handleCognitoApi($userHandler),
        '/admin/users' => fn() => $adminController->create(),
    ],
];

// 動的ルート（パラメータあり）
if ($method === 'POST' && preg_match('#^/admin/users/([^/]+)/delete$#', $uri, $matches)) {
    $adminController->delete(rawurldecode($matches[1]));
    exit;
}

if ($method === 'POST' && preg_match('#^/admin/users/([^/]+)/confirm$#', $uri, $matches)) {
    $adminController->confirm(rawurldecode($matches[1]));
    exit;
}

// 静的ルートのマッチング
if (isset($routes[$method][$uri])) {
    $routes[$method][$uri]();
    exit;
}

// Not Found
http_response_code(404);
echo json_encode(['error' => 'Not Found']);
