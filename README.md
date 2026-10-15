# MiniCog

[![CI](https://github.com/ixbox/minicog/actions/workflows/ci.yml/badge.svg)](https://github.com/ixbox/minicog/actions/workflows/ci.yml)

ローカル開発用の Amazon Cognito（User Pools）互換 IdP です。
同じコードを 2 通りの形で使えます。

- **サーバーとして起動する**: `php -S` で起動し、AWS SDK（PHP / JS / Python）、AWS CLI、Amplify、OIDC クライアントからエンドポイントを差し替えて使う。
- **サーバーを立てずに使う**: AWS SDK for PHP の `http_handler` に差し込み、プロセス内で Cognito API を処理する。Laravel のテストなどで使う。

JWT は RS256 で署名し、クレームは Cognito と同じ形式です。JWKS と OpenID Connect の discovery も配信するので、バックエンドの JWT 検証もそのまま通ります。

## サーバーとして起動する

```sh
composer install
composer serve            # = php -S localhost:9229 public/index.php
```

- 管理画面: <http://localhost:9229/admin>（[管理画面](#管理画面)）
- 状態（ユーザー、署名鍵、セッション）の保存先は `data/minicog.json` です。
  - このファイルを消すと初期化されます。
- 確認コードと一時パスワードは標準エラーのログにも出ます。

### phar で起動する

phar はリポジトリのチェックアウトも `composer install` も要らない、1 ファイルの実行形式です。
PHP 8.2 以上（openssl 拡張あり）があれば動きます。
自分でビルドするほか、GitHub Actions の CI の実行結果から成果物（`minicog.phar`）としてダウンロードできます。

```sh
composer build-phar       # build/minicog.phar を作る

php minicog.phar          # または ./minicog.phar
php minicog.phar --listen 0.0.0.0:9229 --data ./minicog-data.json --config ./minicog.json
php minicog.phar --help
```

| オプション | 既定値 | 説明 |
|---|---|---|
| `--listen <host:port>` | `localhost:9229` | 待ち受けるアドレス |
| `--data <file>` | `./minicog-data.json` | 状態の保存先。phar の中には書き込めないので、実行したディレクトリに作る |
| `--config <file>` | なし | 設定 JSON ファイル（`MINICOG_CONFIG` と同じ） |
| `--templates <dir>` | なし | テンプレートを上書きするディレクトリ（`MINICOG_TEMPLATES_DIR` と同じ） |
| `--version` | | ビルドしたコミット（`git describe`）を表示する |

- それ以外の設定は、[環境変数](#設定)で指定します。
- `MINICOG_BASE_URL` を指定しない場合は、`--listen` のアドレスから自動で決まります。
  - そのため、ポートを変えても issuer と OAuth の URL が食い違いません。
  - `0.0.0.0` で待ち受ける場合は `localhost` になります。
- 中では PHP の組み込みサーバーを、phar 自身をルーターにして起動しています。

### Docker で起動する

```sh
docker compose up -d      # 同梱の compose.yaml を使う
# または
docker build -t minicog .
docker run -p 9229:9229 -v minicog-data:/data minicog
```

- 設定は [compose.yaml](compose.yaml) の `environment` で変更します。コメントアウトしてある項目を有効にしてください。
- 状態は `/data/minicog.json` に保存されます。ボリュームをマウントすれば、再起動してもユーザーと署名鍵が残ります。
- 設定ファイルを使う場合は、ファイルをマウントして `MINICOG_CONFIG` で指定します。
- 確認コードと一時パスワードは `docker logs` で確認できます。

自分のアプリの Compose に組み込む場合の例です（minicog リポジトリを `./minicog` に置いた場合）。

```yaml
services:
  minicog:
    build: ./minicog
    ports: ["9229:9229"]
    volumes:
      - minicog-data:/data
      - ./minicog.json:/config/minicog.json:ro
    environment:
      MINICOG_CONFIG: /config/minicog.json
      MINICOG_BASE_URL: http://localhost:9229
volumes:
  minicog-data:
```

`MINICOG_BASE_URL` は issuer と OAuth エンドポイントの URL になります。
ブラウザからは `localhost:9229`、他のコンテナからは `minicog:9229` でアクセスする構成では、ホスト名の違いで issuer が一致しなくなります。
その場合は、バックエンドの JWT 検証で issuer を `MINICOG_BASE_URL` 側に合わせ、JWKS だけ `http://minicog:9229/...` から取得してください。

### 設定

環境変数で設定します。
細かい設定は `MINICOG_CONFIG` で JSON ファイルを指定します（[minicog.example.json](minicog.example.json)）。

| 環境変数 | 既定値 | 説明 |
|---|---|---|
| `MINICOG_CONFIG` | なし | 設定 JSON ファイル |
| `MINICOG_DATA_FILE` | `data/minicog.json` | 状態の保存先 |
| `MINICOG_USER_POOL_ID` | `ap-northeast-1_minicog` | ユーザープール ID。一致しない `UserPoolId` は `ResourceNotFoundException` になる |
| `MINICOG_BASE_URL` | `http://localhost:9229` | issuer と OAuth エンドポイントの URL の元になる |
| `MINICOG_ISSUER` | `{baseUrl}/{userPoolId}` | JWT の `iss` を上書きする |
| `MINICOG_FIXED_CODE` | ランダム 6 桁 | 確認コードを固定する（E2E テスト向け） |
| `MINICOG_TEMPLATES_DIR` | なし | 同梱の Twig テンプレートを上書きするディレクトリ（[画面の差し替え](#画面の差し替え)） |
| `MINICOG_DEBUG` | なし | `1` にすると API 呼び出しをログに出す |

`clients` を設定しない場合は、どの `ClientId` も秘密鍵なしのクライアントとして受け付けます。
`clientSecret` を設定したクライアントでは `SECRET_HASH` が必須です。

### AWS CLI から使う

接続先の切り替え方は 2 通りあります。

**プロファイルを作る（おすすめ）**

`~/.aws/config` に MiniCog 用のプロファイルを追加します。

```ini
[profile minicog]
region = ap-northeast-1
services = minicog

[services minicog]
cognito_identity_provider =
  endpoint_url = http://localhost:9229
```

`~/.aws/credentials` にはダミーの認証情報を入れます。
MiniCog は署名を検証しないので、値は何でも構いません。

```ini
[minicog]
aws_access_key_id = local
aws_secret_access_key = local
```

このプロファイルは `cognito-idp` だけを MiniCog に向けます。
`services` のキーは `cognito-idp` ではなく `cognito_identity_provider` です。
キーを間違えると、エラーにならずに本物の AWS へ送信されます。
認証情報をダミーにしておけば、その場合も `UnrecognizedClientException` で失敗するだけで済みます。

```sh
export AWS_PROFILE=minicog
```

**環境変数で指定する**

```sh
export AWS_ENDPOINT_URL_COGNITO_IDENTITY_PROVIDER=http://localhost:9229
export AWS_REGION=ap-northeast-1 AWS_ACCESS_KEY_ID=local AWS_SECRET_ACCESS_KEY=local
```

`AWS_ENDPOINT_URL` を使うと、全サービスの接続先がまとめて切り替わります。

#### よく使うコマンド

```sh
POOL=ap-northeast-1_minicog   # MINICOG_USER_POOL_ID を変えた場合はその値

# すぐにサインインできるユーザーを作る
aws cognito-idp admin-create-user --user-pool-id $POOL --username alice \
  --user-attributes Name=email,Value=alice@example.com Name=email_verified,Value=true \
  --message-action SUPPRESS
aws cognito-idp admin-set-user-password --user-pool-id $POOL --username alice \
  --password password123 --permanent

# サインインしてトークンを取得する
aws cognito-idp initiate-auth --client-id web --auth-flow USER_PASSWORD_AUTH \
  --auth-parameters USERNAME=alice,PASSWORD=password123

# アクセストークンを変数に取り、本人の情報を取得する
ACCESS=$(aws cognito-idp initiate-auth --client-id web --auth-flow USER_PASSWORD_AUTH \
  --auth-parameters USERNAME=alice,PASSWORD=password123 \
  --query AuthenticationResult.AccessToken --output text)
aws cognito-idp get-user --access-token "$ACCESS"

# リフレッシュトークンで更新する
REFRESH=$(aws cognito-idp initiate-auth --client-id web --auth-flow USER_PASSWORD_AUTH \
  --auth-parameters USERNAME=alice,PASSWORD=password123 \
  --query AuthenticationResult.RefreshToken --output text)
aws cognito-idp initiate-auth --client-id web --auth-flow REFRESH_TOKEN_AUTH \
  --auth-parameters REFRESH_TOKEN="$REFRESH"
```

```sh
# サインアップして確認する（コードはサーバーのログと管理画面に出る）
aws cognito-idp sign-up --client-id web --username bob --password password123 \
  --user-attributes Name=email,Value=bob@example.com
aws cognito-idp confirm-sign-up --client-id web --username bob --confirmation-code 123456

# 一時パスワードでユーザーを作り、初回サインインで新しいパスワードを設定する
aws cognito-idp admin-create-user --user-pool-id $POOL --username carol \
  --temporary-password 'Temp1234!' --message-action SUPPRESS
SESSION=$(aws cognito-idp initiate-auth --client-id web --auth-flow USER_PASSWORD_AUTH \
  --auth-parameters 'USERNAME=carol,PASSWORD=Temp1234!' --query Session --output text)
aws cognito-idp respond-to-auth-challenge --client-id web \
  --challenge-name NEW_PASSWORD_REQUIRED --session "$SESSION" \
  --challenge-responses USERNAME=carol,NEW_PASSWORD=newpassword1
```

```sh
# グループとユーザーの検索
aws cognito-idp create-group --user-pool-id $POOL --group-name admins
aws cognito-idp admin-add-user-to-group --user-pool-id $POOL --username alice --group-name admins
aws cognito-idp list-users --user-pool-id $POOL --filter 'email ^= "alice"'
aws cognito-idp list-users-in-group --user-pool-id $POOL --group-name admins
aws cognito-idp admin-delete-user --user-pool-id $POOL --username bob
```

- `clients` を設定していない場合、`--client-id` には任意の文字列を指定できます。
- `clientSecret` を設定したクライアントでは、`SECRET_HASH`（`base64(HMAC-SHA256(secret, username + clientId))`）を `--auth-parameters` に加える必要があります。
- 対応していないオペレーションを呼ぶと `UnknownOperationException` が返ります。

### 各クライアントからの接続

```js
// AWS SDK for JavaScript v3
new CognitoIdentityProviderClient({
  region: "ap-northeast-1",
  endpoint: "http://localhost:9229",
  credentials: { accessKeyId: "local", secretAccessKey: "local" },
});
```

```js
// Amplify v6
Amplify.configure({
  Auth: { Cognito: { userPoolId: "ap-northeast-1_minicog", userPoolClientId: "web", userPoolEndpoint: "http://localhost:9229" } },
});
// SRP は未対応なので USER_PASSWORD_AUTH を指定する
await signIn({ username, password, options: { authFlowType: "USER_PASSWORD_AUTH" } });
```

### JWT の検証

- JWKS: `http://localhost:9229/{userPoolId}/.well-known/jwks.json`
- Discovery: `http://localhost:9229/{userPoolId}/.well-known/openid-configuration`

`aws-jwt-verify` の `CognitoJwtVerifier` は issuer を `https://cognito-idp...amazonaws.com/...` に固定しています。
そのため MiniCog のトークンには `JwtRsaVerifier` を使ってください。
また `aws-jwt-verify` は https からしか JWKS を取得しません。
JWKS を自分で取得して `cacheJwks()` で渡してください。

```js
const verifier = JwtRsaVerifier.create({
  issuer: "http://localhost:9229/ap-northeast-1_minicog",
  jwksUri: "http://localhost:9229/ap-northeast-1_minicog/.well-known/jwks.json",
  audience: null,
});
verifier.cacheJwks(await (await fetch(verifier.jwksUri)).json());
```

## サーバーを立てずに使う（AWS SDK for PHP）

```php
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use MiniCog\MiniCog;

$minicog = MiniCog::create([
    'userPoolId' => 'ap-northeast-1_example',
    'fixedCode' => '123456',
    // 'dataFile' => storage_path('minicog.json'),  // 省略時はメモリ上
]);
$minicog->createConfirmedUser('alice', 'password123', ['email' => 'alice@example.com']);

$client = new CognitoIdentityProviderClient([
    'region' => 'ap-northeast-1',
    'version' => 'latest',
    'credentials' => false,
    'http_handler' => $minicog->httpHandler(),
]);
```

SDK はリクエストのシリアライズとレスポンスの解析を本物と同じ経路で行います。
エラーは `CognitoIdentityProviderException` として投げられ、`getAwsErrorCode()` で `NotAuthorizedException` などの例外名を取れます。
`dataFile` を `php -S` のサーバーと同じファイルにすれば、ユーザーと署名鍵を共有できます。

Laravel の場合は、サービスプロバイダでクライアントの生成を差し替えます。

```php
// AppServiceProvider::register()
if ($this->app->environment('local', 'testing')) {
    $this->app->singleton(MiniCog::class, fn () => MiniCog::create([
        'userPoolId' => config('services.cognito.user_pool_id'),
    ]));
    $this->app->singleton(CognitoIdentityProviderClient::class, fn ($app) => new CognitoIdentityProviderClient([
        'region' => config('services.cognito.region'),
        'version' => 'latest',
        'credentials' => false,
        'http_handler' => $app->make(MiniCog::class)->httpHandler(),
    ]));
}
```

## PSR-15 のハンドラとして使う

`MiniCog` は PSR-15 の `RequestHandlerInterface` を実装しています。
PSR-7 のサーバーリクエストを渡せば、任意の PSR-15 ランナーやミドルウェアと組み合わせられます。

```php
$minicog = MiniCog::create(['userPoolId' => 'ap-northeast-1_example']);
$response = $minicog->handle($serverRequest);   // ResponseInterface
```

- `handle()` は `ServerRequestInterface` だけでなく、素の `RequestInterface` も受け付けます（引数の反変）。
  - AWS SDK の `http_handler` から来るリクエストがこれにあたります。
- 中は次の順に PSR-15 のミドルウェアを通っています。どれも `MiniCog\Http\Middleware` にあり、単体でも使えます。
  1. `CorsMiddleware`: CORS ヘッダーの付与と、プリフライトへの 204
  2. `ErrorHandlerMiddleware`: 想定外の例外をログに出し、500 の JSON にする
  3. `ParseRequestMiddleware`: 未解析のクエリ・Cookie・フォームを解析する
- 既存のアプリにミドルウェアとして差し込む形には対応していません。
  - MiniCog のルートは `/`、`/login`、`/logout` などルート直下にあり、アプリ側のルートとぶつかるためです。

## 対応している API

| 分類 | オペレーション |
|---|---|
| 認証 | `InitiateAuth`（`USER_PASSWORD_AUTH`, `REFRESH_TOKEN_AUTH`）、`AdminInitiateAuth`（`ADMIN_USER_PASSWORD_AUTH`, `ADMIN_NO_SRP_AUTH`, `REFRESH_TOKEN_AUTH`）、`RespondToAuthChallenge` / `AdminRespondToAuthChallenge`（`NEW_PASSWORD_REQUIRED`） |
| サインアップ | `SignUp`, `ConfirmSignUp`, `ResendConfirmationCode`, `ForgotPassword`, `ConfirmForgotPassword` |
| 本人操作 | `GetUser`, `UpdateUserAttributes`, `GetUserAttributeVerificationCode`, `VerifyUserAttribute`, `DeleteUserAttributes`, `ChangePassword`, `DeleteUser`, `GlobalSignOut`, `RevokeToken` |
| Admin（ユーザー） | `AdminCreateUser`, `AdminGetUser`, `AdminDeleteUser`, `AdminConfirmSignUp`, `AdminSetUserPassword`, `AdminResetUserPassword`, `AdminUpdateUserAttributes`, `AdminDeleteUserAttributes`, `AdminDisableUser`, `AdminEnableUser`, `AdminUserGlobalSignOut`, `ListUsers`（`Filter` と `PaginationToken` に対応） |
| Admin（グループ） | `CreateGroup`, `GetGroup`, `DeleteGroup`, `ListGroups`, `AdminAddUserToGroup`, `AdminRemoveUserFromGroup`, `AdminListGroupsForUser`, `ListUsersInGroup` |
| プール | `DescribeUserPool`, `DescribeUserPoolClient` |

所属グループはトークンの `cognito:groups` クレームに入ります。
`GlobalSignOut` と `RevokeToken` を呼ぶと、同じセッションから発行したアクセストークンも無効になります。

### OAuth 2.0 / OIDC（マネージドログインの最小実装）

| エンドポイント | 内容 |
|---|---|
| `GET /oauth2/authorize` | ログインフォーム。`response_type=code`（PKCE 対応）と `token` に対応。初回ログイン時の新パスワード設定もできる |
| `POST /oauth2/token` | `authorization_code`, `refresh_token`, `client_credentials`。クライアント認証は Basic と POST の両方 |
| `GET /oauth2/userInfo` | |
| `POST /oauth2/revoke` | |
| `GET /logout` | `logout_uri` へのリダイレクト、または `redirect_uri` を付けての再ログイン |

### 管理画面

`/admin` で開きます。サーバーとして起動したとき（`php -S`、phar、Docker）だけ使えます。

| 画面 | できること |
|---|---|
| ユーザー | 一覧（ステータス、グループ、未使用の確認コード）、作成。詳細画面で、属性の追加・編集・削除、パスワードの設定（恒久 / 一時）、有効・無効の切り替え、確認、全セッションのサインアウト、グループへの追加、削除 |
| グループ | 一覧、作成（説明、優先度）、メンバーの追加・削除、削除 |
| トークン | 選んだユーザーのトークンをパスワードなしで発行する（クライアントとスコープを指定）。JWT を貼り付けて、デコードと署名・期限の検証をする |
| 設定 | ユーザープール、パスワードポリシー、エンドポイント、クライアントの設定の表示。データの初期化（署名鍵を作り直すかどうかを選べる） |

- 画面遷移と操作は htmx（`hx-boost`）で非同期に行います。
- 見た目は Pico CSS です。htmx と Pico は `assets/` に同梱しているので、オフラインや phar でも CDN なしで動きます。
- 日時はブラウザのタイムゾーンで表示します。

### 画面の差し替え

管理画面とログイン画面は Twig で描画しています。
テンプレートは `templates/` にあります。

| ファイル | 画面 |
|---|---|
| `layout.html.twig` | 共通レイアウト（`title`、`head`、`body_attributes`、`body` ブロック） |
| `login.html.twig` | ログイン画面 |
| `error.html.twig` | エラー画面 |
| `signed_out.html.twig` | サインアウト完了 |
| `admin/*.html.twig` | 管理画面（`_` で始まるものは部品や htmx のフラグメント） |

ログイン画面を自分のアプリの見た目に合わせたい場合は、`MINICOG_TEMPLATES_DIR`（インプロセスで使う場合は `templatesDir`）にディレクトリを指定します。
そこに同じ名前のファイルを置くと、同梱のテンプレートより優先されます。
置かなかったテンプレートは同梱のものが使われます。
同梱の `layout.html.twig` を継承することもできます。
各テンプレートが受け取る変数は、ファイル先頭のコメントに書いてあります。
全テンプレートで `config`（`MiniCog\Config`）を参照できます。

### 未対応

- `USER_SRP_AUTH`（SRP 認証）、MFA、カスタム認証チャレンジ、Lambda トリガー
- 複数のユーザープール
- ソーシャル IdP や SAML のフェデレーション

## テスト

```sh
composer test
```

GitHub Actions（[.github/workflows/ci.yml](.github/workflows/ci.yml)）では、main への push と pull request のたびに次を実行します。

- PHP 8.2 / 8.3 / 8.4 / 8.5 でのテスト
- PHP 8.2 での phar のビルドと、起動して AWS CLI でサインアップからサインインまで通すスモークテスト（phar は成果物として保存）
- Docker イメージのビルドと、ヘルスチェック・管理画面・OIDC discovery のスモークテスト
