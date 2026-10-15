<?php

namespace MiniCog\Http\Admin;

use MiniCog\Cognito\CognitoException;
use MiniCog\Cognito\UserPool;
use MiniCog\Http\View;
use MiniCog\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /admin/users: ユーザーの一覧・作成・詳細・編集。
 */
final class UserController extends AdminController
{
    public function __construct(
        View $view,
        private readonly UserPool $users,
        private readonly TokenService $tokens,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $groupsByUser = [];
        foreach ($this->users->groups() as $group) {
            foreach ($group['members'] as $member) {
                $groupsByUser[$member][] = $group['name'];
            }
        }

        return $this->page($request, 'admin/users.html.twig', [
            'section' => 'users',
            'users' => $this->users->all(),
            'groupsByUser' => $groupsByUser,
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $username = self::input($request, 'username');
        $email = self::input($request, 'email');
        $confirmed = !empty(self::form($request)['confirmed']);

        $attributes = [];
        if ($email !== '') {
            $attributes['email'] = $email;
            $attributes['email_verified'] = $confirmed ? 'true' : 'false';
        }

        try {
            $user = $this->users->create(
                $username,
                (string) (self::form($request)['password'] ?? ''),
                $attributes,
                $confirmed ? UserPool::STATUS_CONFIRMED : UserPool::STATUS_UNCONFIRMED,
            );
        } catch (CognitoException $e) {
            return $this->redirect('/admin/users', error: $e->getMessage());
        }

        return $this->redirect(self::userPath($user['username']), notice: "ユーザー {$user['username']} を作成しました。");
    }

    public function show(ServerRequestInterface $request, string $username): ResponseInterface
    {
        $user = $this->users->find($username);
        if ($user === null) {
            return $this->redirect('/admin/users', error: "ユーザー $username は存在しません。");
        }

        $memberOf = array_column($this->users->groupsOf($user), 'name');
        $sessions = array_filter(
            $this->users->store()->read()['sessions'] ?? [],
            fn (array $session) => $session['username'] === $user['username'] && $session['expiresAt'] >= time(),
        );

        return $this->page($request, 'admin/user.html.twig', [
            'section' => 'users',
            'user' => $user,
            'memberOf' => $memberOf,
            'otherGroups' => array_values(array_diff(array_column($this->users->groups(), 'name'), $memberOf)),
            'sessionCount' => count($sessions),
        ]);
    }

    public function setAttribute(ServerRequestInterface $request, string $username): ResponseInterface
    {
        $name = self::input($request, 'name');
        $value = (string) (self::form($request)['value'] ?? '');

        return $this->attempt(self::userPath($username), "属性 $name を更新しました。", function () use ($username, $name, $value) {
            if ($name === '') {
                throw CognitoException::invalidParameter('属性名を入力してください。');
            }
            $this->users->updateAttributes($username, [$name => $value]);

            return null;
        });
    }

    public function deleteAttribute(ServerRequestInterface $request, string $username, string $name): ResponseInterface
    {
        return $this->attempt(self::userPath($username), "属性 $name を削除しました。", function () use ($username, $name) {
            $this->users->deleteAttributes($username, [$name]);

            return null;
        });
    }

    public function setPassword(ServerRequestInterface $request, string $username): ResponseInterface
    {
        $password = (string) (self::form($request)['password'] ?? '');
        $permanent = !empty(self::form($request)['permanent']);
        $notice = $permanent
            ? 'パスワードを設定しました。'
            : '一時パスワードを設定しました。次回サインイン時に変更が必要です。';

        return $this->attempt(self::userPath($username), $notice, function () use ($username, $password, $permanent) {
            $this->users->setPassword(
                $username,
                $password,
                $permanent ? UserPool::STATUS_CONFIRMED : UserPool::STATUS_FORCE_CHANGE_PASSWORD,
            );

            return null;
        });
    }

    public function action(ServerRequestInterface $request, string $username, string $action): ResponseInterface
    {
        $back = self::userPath($username);

        return match ($action) {
            'confirm' => $this->attempt($back, '確認済みにしました。', function () use ($username) {
                $this->users->modify($username, function (array &$user) {
                    $user['status'] = UserPool::STATUS_CONFIRMED;
                    unset($user['codes']['signUp']);
                });

                return null;
            }),
            'enable', 'disable' => $this->attempt($back, $action === 'enable' ? '有効にしました。' : '無効にしました。', function () use ($username, $action) {
                $this->users->modify($username, function (array &$user) use ($action) {
                    $user['enabled'] = $action === 'enable';
                });

                return null;
            }),
            'signout' => $this->attempt($back, 'すべてのセッションをサインアウトしました。', function () use ($username) {
                $this->tokens->signOutEverywhere($this->users->get($username));

                return null;
            }),
            'delete' => $this->attempt($back, "ユーザー $username を削除しました。", function () use ($username) {
                $this->users->delete($username);

                return '/admin/users';
            }),
        };
    }

    public function addToGroup(ServerRequestInterface $request, string $username): ResponseInterface
    {
        $group = self::input($request, 'group');

        return $this->attempt(self::userPath($username), "グループ $group に追加しました。", function () use ($username, $group) {
            $this->users->setMembership($username, $group, true);

            return null;
        });
    }

    public function removeFromGroup(ServerRequestInterface $request, string $username, string $group): ResponseInterface
    {
        return $this->attempt(self::userPath($username), "グループ $group から外しました。", function () use ($username, $group) {
            $this->users->setMembership($username, $group, false);

            return null;
        });
    }
}
