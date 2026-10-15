<?php

/*
 * minicog.phar をビルドする。
 *
 *   composer build-phar                 # build/minicog.phar を作る
 *   php -d phar.readonly=0 bin/build-phar.php [出力先]
 *
 * 一時ディレクトリに必要なファイルをコピーし、開発用の依存を除いて composer install してから固める。
 */

if (!Phar::canWrite()) {
    fwrite(STDERR, "phar.readonly が有効です。php -d phar.readonly=0 bin/build-phar.php で実行してください。\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $argv[1] ?? $root . '/build/minicog.phar';
$stage = sys_get_temp_dir() . '/minicog-phar-' . bin2hex(random_bytes(4));

$run = static function (string $command, string $cwd): string {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Command failed: $command\n$stderr");
    }

    return trim($stdout);
};

$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        mkdir($to, 0755, true);
        foreach (scandir($from) as $name) {
            if ($name !== '.' && $name !== '..') {
                $copy("$from/$name", "$to/$name");
            }
        }
    } else {
        copy($from, $to);
    }
};

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $remove("$path/$name");
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

try {
    $version = 'dev';
    try {
        $version = $run('git describe --tags --always --dirty', $root) ?: 'dev';
    } catch (RuntimeException) {
        // git が無い環境では dev のまま
    }

    foreach (['src', 'templates', 'public', 'composer.json', 'composer.lock'] as $path) {
        $copy("$root/$path", "$stage/$path");
    }

    // composer のスクリプトから呼ばれたときは COMPOSER_BINARY に composer のパスが入っている
    $composer = getenv('COMPOSER_BINARY') ?: 'composer';
    $composerCommand = str_ends_with($composer, '.phar') || is_file($composer)
        ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composer)
        : $composer;
    fwrite(STDERR, "Installing dependencies (--no-dev)...\n");
    $run("$composerCommand install --no-dev --classmap-authoritative --no-interaction --no-progress --no-scripts --quiet", $stage);

    @mkdir(dirname($output), 0755, true);
    $remove($output);

    $phar = new Phar($output, 0, 'minicog.phar');
    $phar->startBuffering();
    $phar->buildFromIterator(new CallbackFilterIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)),
        static fn (SplFileInfo $file) => preg_match('#^(src|templates|public|vendor)/#', substr($file->getPathname(), strlen($stage) + 1)) === 1,
    ), $stage);
    $phar->setStub(str_replace('@version@', $version, file_get_contents("$root/phar/stub.php")));
    $phar->stopBuffering();
    chmod($output, 0755);

    fwrite(STDERR, sprintf("Built %s (%s, %.1f MB)\n", $output, $version, filesize($output) / 1024 / 1024));
} finally {
    $remove($stage);
}
