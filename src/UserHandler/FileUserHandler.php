<?php

namespace MiniCog\UserHandler;

class FileUserHandler implements UserHandlerInterface
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;

        // ファイルが存在しない場合は空のJSONを作成
        if (!file_exists($this->filePath)) {
            $dir = dirname($this->filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->filePath, json_encode([]));
        }
    }

    public function getUser(string $username): ?array
    {
        $users = $this->readUsers();
        return $users[$username] ?? null;
    }

    public function listUsers(): array
    {
        return $this->readUsers();
    }

    public function createUser(string $username, string $password, array $attributes = []): array
    {
        return $this->withLock(function () use ($username, $password, $attributes) {
            $users = $this->readUsers();

            if (isset($users[$username])) {
                throw new \InvalidArgumentException("User `$username` already exists");
            }

            $users[$username] = [
                'username' => $username,
                'password' => $this->hashPassword($password),
                'attributes' => $attributes,
                'sub' => uniqid('user-'),
                'userStatus' => 'UNCONFIRMED',
                'enabled' => true,
                'userCreateDate' => time(),
                'userLastModifiedDate' => time(),
            ];

            $this->writeUsers($users);

            return $users[$username];
        });
    }

    public function updateUser(string $username, array $attributes): array
    {
        return $this->withLock(function () use ($username, $attributes) {
            $users = $this->readUsers();

            if (!isset($users[$username])) {
                throw new \InvalidArgumentException("User `$username` does not exist");
            }

            $users[$username]['attributes'] = array_merge(
                $users[$username]['attributes'] ?? [],
                $attributes
            );
            $users[$username]['userLastModifiedDate'] = time();

            $this->writeUsers($users);

            return $users[$username];
        });
    }

    public function deleteUser(string $username): void
    {
        $this->withLock(function () use ($username) {
            $users = $this->readUsers();
            unset($users[$username]);
            $this->writeUsers($users);
        });
    }

    public function updateUserStatus(string $username, string $status): array
    {
        return $this->withLock(function () use ($username, $status) {
            $users = $this->readUsers();

            if (!isset($users[$username])) {
                throw new \InvalidArgumentException("User `$username` does not exist");
            }

            $users[$username]['userStatus'] = $status;
            $users[$username]['userLastModifiedDate'] = time();

            $this->writeUsers($users);

            return $users[$username];
        });
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
        $this->withLock(function () use ($username, $password) {
            $users = $this->readUsers();

            if (!isset($users[$username])) {
                throw new \InvalidArgumentException("User `$username` does not exist");
            }

            $users[$username]['password'] = $this->hashPassword($password);
            $users[$username]['userLastModifiedDate'] = time();

            $this->writeUsers($users);
        });
    }

    private function readUsers(): array
    {
        $content = file_get_contents($this->filePath);
        return json_decode($content, true) ?: [];
    }

    private function writeUsers(array $users): void
    {
        file_put_contents($this->filePath, json_encode($users, JSON_PRETTY_PRINT));
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $handle = fopen($this->filePath, 'r+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$this->filePath}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Cannot acquire lock on file: {$this->filePath}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function hashPassword(string $password): string
    {
        return base64_encode($password);
    }
}


