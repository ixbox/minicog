<?php

namespace MiniCog\UserHandler;

interface UserHandlerInterface
{
    /**
     * ユーザー一覧を取得
     */
    public function listUsers(): array;

    /**
     * ユーザー情報を取得
     */
    public function getUser(string $username): ?array;

    /**
     * ユーザーを作成
     */
    public function createUser(string $username, string $password, array $attributes = []): array;

    /**
     * ユーザー属性を更新
     */
    public function updateUser(string $username, array $attributes): array;

    /**
     * ユーザーを削除
     */
    public function deleteUser(string $username): void;

    /**
     * ユーザーのステータスを更新
     */
    public function updateUserStatus(string $username, string $status): array;

    /**
     * パスワードの検証
     */
    public function verifyPassword(string $username, string $password): bool;

    /**
     * パスワードの更新
     */
    public function updatePassword(string $username, string $password): void;
}
