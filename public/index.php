<?php

/**
 * 組み込みサーバー用のフロントコントローラー。
 *
 *   php -S localhost:9229 public/index.php
 *
 * 環境変数:
 *   MINICOG_CONFIG        設定 JSON ファイル（minicog.example.json 参照）
 *   MINICOG_DATA_FILE     状態を保存する JSON ファイル（既定: data/minicog.json）
 *   MINICOG_USER_POOL_ID  ユーザープール ID（既定: ap-northeast-1_minicog）
 *   MINICOG_BASE_URL      外部から見たベース URL（既定: http://localhost:9229）
 *   MINICOG_ISSUER        JWT の iss を上書きする
 *   MINICOG_FIXED_CODE    確認コードを固定する（例: 123456）
 *   MINICOG_TEMPLATES_DIR 同梱の Twig テンプレートを上書きするディレクトリ
 *   MINICOG_DEBUG         1 でデバッグログを出す
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\ServerRequest;
use MiniCog\Config;
use MiniCog\MiniCog;
use MiniCog\NanoLogger;
use MiniCog\Store\FileStore;

$dataFile = getenv('MINICOG_DATA_FILE') ?: __DIR__ . '/../data/minicog.json';
$minicog = new MiniCog(
    Config::fromEnv(),
    new FileStore($dataFile),
    new NanoLogger((bool) getenv('MINICOG_DEBUG')),
);

$response = $minicog->handle(ServerRequest::fromGlobals());

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}
echo $response->getBody();
