<?php
/**
 * @var \MiniCog\Config $config
 * @var list<array> $users
 * @var array<string, list<string>> $groupsByUser
 * @var string|null $flash
 */
$e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES);
$statusLabels = [
    'CONFIRMED' => '確認済み',
    'UNCONFIRMED' => '未確認',
    'FORCE_CHANGE_PASSWORD' => '要パスワード変更',
    'RESET_REQUIRED' => '要パスワードリセット',
];
?>
<header>
    <h1>MiniCog</h1>
    <p>ローカル開発用 Cognito 互換 IdP</p>
</header>

<?php if ($flash): ?>
    <p class="error"><?= $e($flash) ?></p>
<?php endif; ?>

<details>
    <summary>接続情報</summary>
    <table>
        <tr><th>UserPoolId</th><td><code><?= $e($config->userPoolId) ?></code></td></tr>
        <tr><th>API エンドポイント</th><td><code><?= $e($config->url('/')) ?></code></td></tr>
        <tr><th>Issuer</th><td><code><?= $e($config->issuer()) ?></code></td></tr>
        <tr><th>JWKS</th><td><code><?= $e($config->url('/' . $config->userPoolId . '/.well-known/jwks.json')) ?></code></td></tr>
        <tr><th>OIDC Discovery</th><td><code><?= $e($config->url('/' . $config->userPoolId . '/.well-known/openid-configuration')) ?></code></td></tr>
        <tr><th>クライアント</th><td><?= $config->clients === [] ? '任意の ClientId を受け付けます' : $e(implode(', ', array_column($config->clients, 'clientId'))) ?></td></tr>
    </table>
</details>

<h2>新規ユーザー作成</h2>
<form method="post" action="/admin/users">
    <p><label>ユーザー名<?= $config->usernameAttributes ? '（' . $e(implode(' / ', $config->usernameAttributes)) . '）' : '' ?><input type="text" name="username" required></label></p>
    <p><label>パスワード<input type="text" name="password" required></label></p>
    <p><label>メール（任意）<input type="email" name="email"></label></p>
    <p><label><input type="checkbox" name="confirmed" value="1" checked> 確認済み（email_verified=true）で作成する</label></p>
    <p><button type="submit">作成</button></p>
</form>

<h2>ユーザー一覧</h2>
<?php if ($users === []): ?>
    <p>ユーザーが登録されていません。</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>ユーザー名</th>
            <th>メール</th>
            <th>ステータス</th>
            <th>グループ</th>
            <th>未使用コード</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $path = '/admin/users/' . rawurlencode($user['username']); ?>
            <tr>
                <td><?= $e($user['username']) ?><br><small><?= $e($user['attributes']['sub']) ?></small></td>
                <td><?= $e($user['attributes']['email'] ?? '-') ?></td>
                <td>
                    <?= $e($statusLabels[$user['status']] ?? $user['status']) ?>
                    <?= $user['enabled'] ? '' : '<br><small>無効</small>' ?>
                </td>
                <td><?= $e(implode(', ', $groupsByUser[$user['username']] ?? []) ?: '-') ?></td>
                <td>
                    <?php foreach ($user['codes'] ?? [] as $purpose => $code): ?>
                        <code><?= $e($code['code']) ?></code> <small><?= $e($purpose) ?></small><br>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if ($user['status'] === 'UNCONFIRMED'): ?>
                        <form method="post" action="<?= $e($path) ?>/confirm" style="display:inline">
                            <button type="submit">確認</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?= $e($path) ?>/delete" style="display:inline" onsubmit="return confirm('削除しますか？')">
                        <button type="submit">削除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<footer>
    <p><small>MiniCog - Cognito-compatible IdP for local development</small></p>
</footer>
