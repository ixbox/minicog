<?php

namespace MiniCog\Http\Admin;

use FastRoute\RouteCollector;
use GuzzleHttp\Psr7\Response;
use MiniCog\Cognito\UserPool;
use MiniCog\Config;
use MiniCog\Http\View;
use MiniCog\TokenService;

/**
 * 管理画面のルート定義（/admin 以下）。
 */
final class AdminRoutes
{
    private readonly UserController $users;
    private readonly GroupController $groups;
    private readonly TokenController $tokens;
    private readonly SettingsController $settings;

    public function __construct(View $view, Config $config, UserPool $users, TokenService $tokens)
    {
        $this->users = new UserController($view, $users, $tokens);
        $this->groups = new GroupController($view, $users);
        $this->tokens = new TokenController($view, $config, $users, $tokens);
        $this->settings = new SettingsController($view, $config, $users->store(), $tokens);
    }

    public function register(RouteCollector $r): void
    {
        $u = $this->users;
        $g = $this->groups;

        $r->get('/admin', fn () => new Response(302, ['Location' => '/admin/users']));

        $r->get('/admin/users', fn ($req) => $u->index($req));
        $r->post('/admin/users', fn ($req) => $u->create($req));
        $r->get('/admin/users/{username}', fn ($req, $v) => $u->show($req, $v['username']));
        $r->post('/admin/users/{username}/attributes', fn ($req, $v) => $u->setAttribute($req, $v['username']));
        $r->post('/admin/users/{username}/attributes/{name}/delete', fn ($req, $v) => $u->deleteAttribute($req, $v['username'], $v['name']));
        $r->post('/admin/users/{username}/password', fn ($req, $v) => $u->setPassword($req, $v['username']));
        $r->post('/admin/users/{username}/groups', fn ($req, $v) => $u->addToGroup($req, $v['username']));
        $r->post('/admin/users/{username}/groups/{group}/delete', fn ($req, $v) => $u->removeFromGroup($req, $v['username'], $v['group']));
        $r->post('/admin/users/{username}/{action:confirm|enable|disable|signout|delete}', fn ($req, $v) => $u->action($req, $v['username'], $v['action']));

        $r->get('/admin/groups', fn ($req) => $g->index($req));
        $r->post('/admin/groups', fn ($req) => $g->create($req));
        $r->get('/admin/groups/{name}', fn ($req, $v) => $g->show($req, $v['name']));
        $r->post('/admin/groups/{name}/delete', fn ($req, $v) => $g->delete($req, $v['name']));
        $r->post('/admin/groups/{name}/members', fn ($req, $v) => $g->addMember($req, $v['name']));
        $r->post('/admin/groups/{name}/members/{username}/delete', fn ($req, $v) => $g->removeMember($req, $v['name'], $v['username']));

        $r->get('/admin/tokens', fn ($req) => $this->tokens->index($req));
        $r->post('/admin/tokens', fn ($req) => $this->tokens->issue($req));
        $r->post('/admin/tokens/decode', fn ($req) => $this->tokens->decode($req));

        $r->get('/admin/settings', fn ($req) => $this->settings->index($req));
        $r->post('/admin/reset', fn ($req) => $this->settings->reset($req));
    }
}
