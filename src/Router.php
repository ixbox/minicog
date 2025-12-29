<?php

namespace MiniCog;

use FastRoute\RouteCollector;
use FastRoute\Dispatcher;

class Router
{
    public static function createDispatcher(): Dispatcher
    {
        return \FastRoute\simpleDispatcher(function (RouteCollector $r) {
            // Cognito API エンドポイント
            $r->post('/', 'MiniCog\Handler::handleCognitoApi');

            // 管理画面
            $r->get('/admin', 'MiniCog\AdminController::index');
            $r->get('/admin/users', 'MiniCog\AdminController::list');
            $r->post('/admin/users', 'MiniCog\AdminController::create');
            $r->post('/admin/users/{username}/delete', 'MiniCog\AdminController::delete');
            $r->post('/admin/users/{username}/confirm', 'MiniCog\AdminController::confirm');
        });
    }
}
