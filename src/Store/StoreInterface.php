<?php

namespace MiniCog\Store;

/**
 * ユーザープール全体の状態を 1 つの配列として保持するストア。
 *
 * 状態のトップレベルキー: users, groups, sessions, challenges, authCodes, webSessions, signingKey
 */
interface StoreInterface
{
    /**
     * 現在の状態を返す。
     */
    public function read(): array;

    /**
     * 状態を排他的に更新する。コールバックは状態を参照で受け取り、戻り値がそのまま返される。
     *
     * @template T
     * @param callable(array &$state): T $callback
     * @return T
     */
    public function update(callable $callback): mixed;
}
