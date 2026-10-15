<?php

namespace MiniCog\Http;

use GuzzleHttp\Psr7\Response;
use MiniCog\Cognito\CognitoException;
use MiniCog\Cognito\UserPool;
use MiniCog\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ユーザー管理画面（/admin）。
 */
final class AdminController
{
    public function __construct(
        private readonly Config $config,
        private readonly UserPool $users,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $groupsByUser = [];
        foreach ($this->users->groups() as $group) {
            foreach ($group['members'] as $member) {
                $groupsByUser[$member][] = $group['name'];
            }
        }

        $body = self::render(__DIR__ . '/../../templates/admin.php', [
            'config' => $this->config,
            'users' => $this->users->all(),
            'groupsByUser' => $groupsByUser,
            'flash' => $request->getQueryParams()['error'] ?? null,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], Html::page('Users', $body));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $form = (array) $request->getParsedBody();
        $username = trim((string) ($form['username'] ?? ''));
        $password = (string) ($form['password'] ?? '');
        $email = trim((string) ($form['email'] ?? ''));
        $confirmed = !empty($form['confirmed']);

        $attributes = [];
        if ($email !== '') {
            $attributes['email'] = $email;
            $attributes['email_verified'] = $confirmed ? 'true' : 'false';
        }

        try {
            $this->users->create(
                $username,
                $password,
                $attributes,
                $confirmed ? UserPool::STATUS_CONFIRMED : UserPool::STATUS_UNCONFIRMED,
            );
        } catch (CognitoException $e) {
            return self::redirect('/admin?' . http_build_query(['error' => $e->getMessage()]));
        }

        return self::redirect('/admin');
    }

    public function confirm(string $username): ResponseInterface
    {
        $this->users->modify($username, function (array &$user) {
            $user['status'] = UserPool::STATUS_CONFIRMED;
            unset($user['codes']['signUp']);
        });

        return self::redirect('/admin');
    }

    public function delete(string $username): ResponseInterface
    {
        $this->users->delete($username);

        return self::redirect('/admin');
    }

    private static function redirect(string $location): ResponseInterface
    {
        return new Response(303, ['Location' => $location]);
    }

    private static function render(string $template, array $vars): string
    {
        extract($vars);
        ob_start();
        try {
            include $template;

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
