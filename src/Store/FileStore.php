<?php

namespace MiniCog\Store;

/**
 * JSON ファイルに状態を保持する。flock で排他制御するので、
 * サーバーとインプロセス利用で同じファイルを共有できる。
 */
final class FileStore implements StoreInterface
{
    public function __construct(private readonly string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: $dir");
        }
    }

    public function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $handle = $this->open('r');
        try {
            flock($handle, LOCK_SH);

            return $this->decode(stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function update(callable $callback): mixed
    {
        $handle = $this->open('c+');
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock: {$this->path}");
            }

            $state = $this->decode(stream_get_contents($handle));
            $result = $callback($state);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open(string $mode)
    {
        $handle = fopen($this->path, $mode);
        if ($handle === false) {
            throw new \RuntimeException("Cannot open: {$this->path}");
        }

        return $handle;
    }

    private function decode(string|false $json): array
    {
        if ($json === false || trim($json) === '') {
            return [];
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
