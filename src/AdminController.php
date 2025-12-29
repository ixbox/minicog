<?php

namespace MiniCog;

use MiniCog\UserHandler\UserHandlerInterface;
use Psr\Log\LoggerInterface;

class AdminController
{
    public function __construct(
        private LoggerInterface $logger,
        private UserHandlerInterface $userHandler,
    ) {
    }

    public function index(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $template = file_get_contents(__DIR__ . '/../templates/admin.html');
        
        ob_start();
        $this->renderUserList();
        $userList = ob_get_clean();
        
        echo str_replace('<!-- ユーザーリストはPHPで出力 -->', $userList, $template);
    }

    public function list(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $this->renderUserList();
    }

    public function create(): void
    {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $email = $_POST['email'] ?? '';

        if (!empty($username) && !empty($password)) {
            $attributes = [];
            if (!empty($email)) {
                $attributes['email'] = $email;
            }
            $this->userHandler->createUser($username, $password, $attributes);
            $this->logger->info('User created', ['username' => $username]);
        }

        header('Location: /admin');
        exit;
    }

    public function delete(string $username): void
    {
        if (!empty($username)) {
            $this->userHandler->deleteUser($username);
            $this->logger->info('User deleted', ['username' => $username]);
        }

        header('Location: /admin');
        exit;
    }

    public function confirm(string $username): void
    {
        if (!empty($username)) {
            $this->userHandler->updateUserStatus($username, 'CONFIRMED');
            $this->logger->info('User confirmed', ['username' => $username]);
        }

        header('Location: /admin');
        exit;
    }

    private function renderUserList(): void
    {
        $users = $this->userHandler->listUsers();
        include __DIR__ . '/../templates/user-list.php';
    }
}
