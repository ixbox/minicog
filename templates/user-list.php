<h2>ユーザー一覧</h2>

<?php if (empty($users)): ?>
    <p>ユーザーが登録されていません。</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ユーザー名</th>
                <th>メール</th>
                <th>ステータス</th>
                <th>作成日時</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $username => $user): ?>
                <?php
                    $email = $user['attributes']['email'] ?? '-';
                    $status = $user['userStatus'] ?? 'UNCONFIRMED';
                    $createdAt = isset($user['userCreateDate']) ? date('Y-m-d H:i:s', $user['userCreateDate']) : '-';
                    $statusLabel = $status === 'CONFIRMED' ? '確認済み' : '未確認';
                    $encodedUsername = rawurlencode($username);
                ?>
                <tr>
                    <td><?= htmlspecialchars($username) ?></td>
                    <td><?= htmlspecialchars($email) ?></td>
                    <td><?= htmlspecialchars($statusLabel) ?></td>
                    <td><?= htmlspecialchars($createdAt) ?></td>
                    <td>
                        <?php if ($status === 'UNCONFIRMED'): ?>
                            <form method="post" action="/admin/users/<?= $encodedUsername ?>/confirm" style="display:inline">
                                <button type="submit">確認</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/admin/users/<?= $encodedUsername ?>/delete" style="display:inline" onsubmit="return confirm('削除しますか？')">
                            <button type="submit">削除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

