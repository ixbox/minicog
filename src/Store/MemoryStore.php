<?php

namespace MiniCog\Store;

/**
 * プロセス内メモリに状態を保持する。テストや SDK のインプロセス利用向け。
 */
final class MemoryStore implements StoreInterface
{
    public function __construct(private array $state = [])
    {
    }

    public function read(): array
    {
        return $this->state;
    }

    public function update(callable $callback): mixed
    {
        $state = $this->state;
        $result = $callback($state);
        // 例外時は反映しない
        $this->state = $state;

        return $result;
    }
}
