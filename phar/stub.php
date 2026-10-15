#!/usr/bin/env php
<?php

/*
 * minicog.phar のスタブ。
 *
 * - CLI で実行されたら、この phar 自身をルーターにして PHP の組み込みサーバーを起動する
 * - 組み込みサーバー（cli-server）から呼ばれたら、phar 内の public/index.php を実行する
 */

if (PHP_SAPI === 'cli-server') {
    require 'phar://' . __FILE__ . '/public/index.php';

    return;
}

$version = '@version@';
$usage = <<<'TXT'
    Usage: php minicog.phar [options]

    Options:
      --listen <host:port>   待ち受けるアドレス（既定: localhost:9229）
      --data <file>          状態を保存する JSON ファイル（既定: ./minicog-data.json）
      --config <file>        設定 JSON ファイル（MINICOG_CONFIG と同じ）
      --templates <dir>      Twig テンプレートを上書きするディレクトリ（MINICOG_TEMPLATES_DIR と同じ）
      -h, --help             このヘルプを表示する
      --version              バージョンを表示する

    そのほかの設定は環境変数で指定します（MINICOG_USER_POOL_ID, MINICOG_BASE_URL,
    MINICOG_ISSUER, MINICOG_FIXED_CODE, MINICOG_DEBUG）。

    TXT;

$options = getopt('h', ['listen:', 'data:', 'config:', 'templates:', 'help', 'version'], $restIndex);
if ($restIndex < $argc) {
    fwrite(STDERR, "Unknown argument: {$argv[$restIndex]}\n\n$usage");
    exit(2);
}
if (isset($options['h']) || isset($options['help'])) {
    echo $usage;
    exit(0);
}
if (isset($options['version'])) {
    echo "MiniCog $version\n";
    exit(0);
}

$absolute = static function (string $path): string {
    return preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) ? $path : getcwd() . DIRECTORY_SEPARATOR . $path;
};
$env = static function (string $name): ?string {
    $value = getenv($name);

    return $value === false || $value === '' ? null : $value;
};

$listen = (string) ($options['listen'] ?? 'localhost:9229');
if (!preg_match('/^(\[[0-9a-fA-F:]+\]|[^:\s]+):(\d+)$/', $listen, $m)) {
    fwrite(STDERR, "Invalid --listen: $listen (expected host:port)\n");
    exit(2);
}
[, $host, $port] = $m;

$settings = [
    'MINICOG_DATA_FILE' => isset($options['data']) ? $absolute($options['data']) : ($env('MINICOG_DATA_FILE') ?? $absolute('minicog-data.json')),
    'MINICOG_CONFIG' => isset($options['config']) ? $absolute($options['config']) : $env('MINICOG_CONFIG'),
    'MINICOG_TEMPLATES_DIR' => isset($options['templates']) ? $absolute($options['templates']) : $env('MINICOG_TEMPLATES_DIR'),
    // issuer や OAuth の URL が待ち受けポートと食い違わないようにする
    'MINICOG_BASE_URL' => $env('MINICOG_BASE_URL')
        ?? 'http://' . (in_array($host, ['0.0.0.0', '[::]'], true) ? 'localhost' : $host) . ':' . $port,
];
foreach ($settings as $name => $value) {
    if ($value !== null) {
        putenv("$name=$value");
    }
}
foreach (['MINICOG_CONFIG' => 'is_file', 'MINICOG_TEMPLATES_DIR' => 'is_dir'] as $name => $check) {
    if ($settings[$name] !== null && !$check($settings[$name])) {
        fwrite(STDERR, "Not found: {$settings[$name]} ($name)\n");
        exit(1);
    }
}

$phar = realpath(__FILE__);
fwrite(STDERR, implode("\n", [
    "MiniCog $version",
    "  listen:   http://$listen",
    "  admin:    {$settings['MINICOG_BASE_URL']}/admin",
    "  data:     {$settings['MINICOG_DATA_FILE']}",
    '',
]));

$args = ['-S', $listen, $phar];
if (function_exists('pcntl_exec')) {
    // プロセスを置き換えるので、Ctrl-C やシグナルがそのまま組み込みサーバーに届く
    pcntl_exec(PHP_BINARY, $args);
}
passthru(implode(' ', array_map('escapeshellarg', [PHP_BINARY, ...$args])), $code);
exit($code);

__HALT_COMPILER();
