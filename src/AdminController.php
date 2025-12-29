<?php

namespace MiniCog;

use MiniCog\UserHandler\UserHandlerInterface;

class AdminController
{
    public static function index(UserHandlerInterface $userHandler, array $vars = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/../templates/admin.html');
    }

    public static function list(UserHandlerInterface $userHandler, array $vars = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        self::renderUserList($userHandler);
    }

    public static function create(UserHandlerInterface $userHandler, array $vars = []): void
    {
        header('Content-Type: text/html; charset=utf-8');

        try {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $email = $_POST['email'] ?? '';

            if (empty($username) || empty($password)) {
                http_response_code(400);
                echo '<p>ユーザー名とパスワードは必須です</p>';
                return;
            }

            $attributes = [];
            if (!empty($email)) {
                $attributes['email'] = $email;
            }

            $userHandler->createUser($username, $password, $attributes);

            echo '<p>✓ ユーザー「' . htmlspecialchars($username) . '」を作成しました</p>';
            self::renderUserList($userHandler);
        } catch (\Exception $e) {
            http_response_code(500);
            echo '<p>エラー: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    public static function delete(UserHandlerInterface $userHandler, array $vars): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $username = $vars['username'] ?? '';

        try {
            if (empty($username)) {
                http_response_code(400);
                echo '<p>ユーザー名が指定されていません</p>';
                return;
            }

            $userHandler->deleteUser($username);

            echo '<p>✓ ユーザー「' . htmlspecialchars($username) . '」を削除しました</p>';
            self::renderUserList($userHandler);
        } catch (\Exception $e) {
            http_response_code(500);
            echo '<p>エラー: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    public static function confirm(UserHandlerInterface $userHandler, array $vars): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $username = $vars['username'] ?? '';

        try {
            if (empty($username)) {
                http_response_code(400);
                echo '<p>ユーザー名が指定されていません</p>';
                return;
            }

            $userHandler->updateUserStatus($username, 'CONFIRMED');

            echo '<p>✓ ユーザー「' . htmlspecialchars($username) . '」を確認しました</p>';
            self::renderUserList($userHandler);
        } catch (\Exception $e) {
            http_response_code(500);
            echo '<p>エラー: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    private static function renderUserList(UserHandlerInterface $userHandler): void
    {
        $users = $userHandler->listUsers();

        if (empty($users)) {
            echo '<h2>ユーザー一覧</h2>';
            echo '<p>ユーザーが登録されていません。</p>';
            return;
        }

        echo '<h2>ユーザー一覧</h2>';
        echo '<table>';
        echo '<thead>';
        echo '<tr>';
        echo '<th>ユーザー名</th>';
        echo '<th>メールアドレス</th>';
        echo '<th>ステータス</th>';
        echo '<th>作成日時</th>';
        echo '<th>操作</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($users as $username => $user) {
            $email = $user['attributes']['email'] ?? '-';
            $status = $user['userStatus'] ?? 'UNCONFIRMED';
            $createdAt = isset($user['userCreateDate']) 
                ? date('Y-m-d H:i:s', $user['userCreateDate']) 
                : '-';

            $statusLabel = $status === 'CONFIRMED' ? '確認済み' : '未確認';
            $encodedUsername = rawurlencode($username);

            echo '<tr>';
            echo '<td>' . htmlspecialchars($username) . '</td>';
            echo '<td>' . htmlspecialchars($email) . '</td>';
            echo '<td>' . htmlspecialchars($statusLabel) . '</td>';
            echo '<td>' . htmlspecialchars($createdAt) . '</td>';
            echo '<td>';
            if ($status === 'UNCONFIRMED') {
                echo '<a hx-post="/admin/users/' . $encodedUsername . '/confirm" hx-target="#user-list" hx-swap="outerHTML">確認</a> ';
            }
            echo '<a hx-post="/admin/users/' . $encodedUsername . '/delete" hx-target="#user-list" hx-swap="outerHTML" hx-confirm="ユーザー「' . htmlspecialchars($username) . '」を削除しますか？">削除</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }
}
