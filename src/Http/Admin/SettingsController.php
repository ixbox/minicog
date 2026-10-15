<?php

namespace MiniCog\Http\Admin;

use MiniCog\Config;
use MiniCog\Http\View;
use MiniCog\Store\StoreInterface;
use MiniCog\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /admin/settings: 現在の設定の表示と、データの初期化。
 */
final class SettingsController extends AdminController
{
    public function __construct(
        View $view,
        private readonly Config $config,
        private readonly StoreInterface $store,
        private readonly TokenService $tokens,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $state = $this->store->read();
        $now = time();

        return $this->page($request, 'admin/settings.html.twig', [
            'section' => 'settings',
            'counts' => [
                'users' => count($state['users'] ?? []),
                'groups' => count($state['groups'] ?? []),
                'sessions' => count(array_filter($state['sessions'] ?? [], fn (array $s) => $s['expiresAt'] >= $now)),
            ],
            'kid' => $this->tokens->signingKey()['kid'],
            'endpoints' => [
                'Cognito API' => $this->config->url('/'),
                'Issuer' => $this->config->issuer(),
                'JWKS' => $this->config->url('/' . $this->config->userPoolId . '/.well-known/jwks.json'),
                'OIDC Discovery' => $this->config->url('/' . $this->config->userPoolId . '/.well-known/openid-configuration'),
                'Authorize' => $this->config->url('/oauth2/authorize'),
                'Token' => $this->config->url('/oauth2/token'),
                'UserInfo' => $this->config->url('/oauth2/userInfo'),
                'Logout' => $this->config->url('/logout'),
            ],
        ]);
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $rotateKey = !empty(self::form($request)['rotate_key']);

        $this->store->update(function (array &$state) use ($rotateKey) {
            foreach (['users', 'groups', 'sessions', 'challenges', 'authCodes', 'webSessions'] as $key) {
                unset($state[$key]);
            }
            if ($rotateKey) {
                unset($state['signingKey']);
            }
        });
        if ($rotateKey) {
            $this->tokens->forgetSigningKey();
        }

        return $this->redirect('/admin/settings', notice: $rotateKey
            ? 'データを初期化し、署名鍵を作り直しました。'
            : 'データを初期化しました（署名鍵はそのまま）。');
    }
}
