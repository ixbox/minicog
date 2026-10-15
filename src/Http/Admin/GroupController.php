<?php

namespace MiniCog\Http\Admin;

use MiniCog\Cognito\CognitoException;
use MiniCog\Cognito\UserPool;
use MiniCog\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /admin/groups: グループの一覧・作成・削除とメンバー管理。
 */
final class GroupController extends AdminController
{
    public function __construct(
        View $view,
        private readonly UserPool $users,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, 'admin/groups.html.twig', [
            'section' => 'groups',
            'groups' => $this->users->groups(),
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $name = self::input($request, 'name');
        $precedence = self::input($request, 'precedence');

        return $this->attempt('/admin/groups', "グループ $name を作成しました。", function () use ($request, $name, $precedence) {
            if ($name === '') {
                throw CognitoException::invalidParameter('グループ名を入力してください。');
            }
            if ($precedence !== '' && !ctype_digit($precedence)) {
                throw CognitoException::invalidParameter('優先度は 0 以上の整数で入力してください。');
            }
            $this->users->createGroup($name, [
                'Description' => self::input($request, 'description') ?: null,
                'Precedence' => $precedence === '' ? null : (int) $precedence,
            ]);

            return self::groupPath($name);
        });
    }

    public function show(ServerRequestInterface $request, string $name): ResponseInterface
    {
        try {
            $group = $this->users->group($name);
        } catch (CognitoException) {
            return $this->redirect('/admin/groups', error: "グループ $name は存在しません。");
        }

        $users = $this->users->all();
        $isMember = fn (array $user) => in_array($user['username'], $group['members'], true);

        return $this->page($request, 'admin/group.html.twig', [
            'section' => 'groups',
            'group' => $group,
            'members' => array_values(array_filter($users, $isMember)),
            'others' => array_values(array_filter($users, fn (array $user) => !$isMember($user))),
        ]);
    }

    public function delete(ServerRequestInterface $request, string $name): ResponseInterface
    {
        return $this->attempt(self::groupPath($name), "グループ $name を削除しました。", function () use ($name) {
            $this->users->deleteGroup($name);

            return '/admin/groups';
        });
    }

    public function addMember(ServerRequestInterface $request, string $name): ResponseInterface
    {
        $username = self::input($request, 'username');

        return $this->attempt(self::groupPath($name), "$username を追加しました。", function () use ($name, $username) {
            $this->users->setMembership($username, $name, true);

            return null;
        });
    }

    public function removeMember(ServerRequestInterface $request, string $name, string $username): ResponseInterface
    {
        return $this->attempt(self::groupPath($name), "$username を外しました。", function () use ($name, $username) {
            $this->users->setMembership($username, $name, false);

            return null;
        });
    }
}
