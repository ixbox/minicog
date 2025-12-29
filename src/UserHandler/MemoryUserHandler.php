<?php

namespace MiniCog\UserHandler;

class MemoryUserHandler implements UserHandlerInterface
{
    private array $users = [];

    public function __construct(array $initialUsers = [])
    {
        foreach ($initialUsers as $user) {
            if (isset($user['username'])) {
                $this->users[$user['username']] = $this->normalizeUser($user);
            }
        }
    }

    public function listUsers(): array
    {
        return $this->users;
    }

    public function getUser(string $username): ?array
    {
        return $this->users[$username] ?? null;
    }

    public function createUser(string $username, string $password, array $attributes = []): array
    {
        if (isset($this->users[$username])) {
            throw new \InvalidArgumentException("User `$username` already exists");
        }

        $this->users[$username] = $this->normalizeUser([
            'username' => $username,
            'password' => $this->hashPassword($password),
            'attributes' => $attributes,
            'sub' => uniqid('user-'),
            'userStatus' => 'UNCONFIRMED',
            'enabled' => true,
            'userCreateDate' => time(),
            'userLastModifiedDate' => time(),
        ]);

        return $this->users[$username];
    }

    public function updateUser(string $username, array $attributes): array
    {
        if (!isset($this->users[$username])) {
            throw new \InvalidArgumentException("User `$username` does not exist");
        }

        $this->users[$username]['attributes'] = array_merge(
            $this->users[$username]['attributes'] ?? [],
            $attributes
        );
        $this->users[$username]['userLastModifiedDate'] = time();

        return $this->users[$username];
    }

    public function deleteUser(string $username): void
    {
        unset($this->users[$username]);
    }

    public function updateUserStatus(string $username, string $status): array
    {
        if (!isset($this->users[$username])) {
            throw new \InvalidArgumentException("User `$username` does not exist");
        }

        $this->users[$username]['userStatus'] = $status;
        $this->users[$username]['userLastModifiedDate'] = time();

        return $this->users[$username];
    }

    public function verifyPassword(string $username, string $password): bool
    {
        $user = $this->getUser($username);
        if (!$user) {
            return false;
        }

        return $user['password'] === $this->hashPassword($password);
    }

    public function updatePassword(string $username, string $password): void
    {
        if (!isset($this->users[$username])) {
            throw new \InvalidArgumentException("User `$username` does not exist");
        }

        $this->users[$username]['password'] = $this->hashPassword($password);
        $this->users[$username]['userLastModifiedDate'] = time();
    }

    private function normalizeUser(array $user): array
    {
        return array_merge([
            'username' => '',
            'password' => '',
            'attributes' => [],
            'sub' => '',
            'userStatus' => 'UNCONFIRMED',
            'enabled' => true,
            'userCreateDate' => time(),
            'userLastModifiedDate' => time(),
        ], $user);
    }

    private function hashPassword(string $password): string
    {
        // Note: 本番環境では適切なハッシュアルゴリズムを使用すべきですが、
        // モックなのでシンプルな方法を採用しています
        return base64_encode($password);
    }
}
