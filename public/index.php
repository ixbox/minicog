<?php

require __DIR__ . '/../vendor/autoload.php';

use MiniCog\Router;
use MiniCog\UserHandler\FileUserHandler;

// 設定
$dataFile = getenv('MINICOG_DATA_FILE') ?: __DIR__ . '/../data/users.json';
$userHandler = new FileUserHandler($dataFile);

// URIからクエリ文字列を除去
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$dispatcher = Router::createDispatcher();
$routeInfo = $dispatcher->dispatch($method, $uri);

switch ($routeInfo[0]) {
    case \FastRoute\Dispatcher::NOT_FOUND:
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
        break;
    case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        break;
    case \FastRoute\Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];
        
        if (is_string($handler) && strpos($handler, '::') !== false) {
            [$class, $method] = explode('::', $handler);
            call_user_func([$class, $method], $userHandler, $vars);
        } else {
            call_user_func($handler, $userHandler, $vars);
        }
        break;
}
