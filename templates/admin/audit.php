<?php
ob_start();
$action_filter = (string) ($action_filter ?? '');
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>审计日志</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="get" action="/admin/audit" role="search">
        <label>操作前缀 <input name="action" value="<?= $e($action_filter) ?>" placeholder="license."></label>
        <button type="submit">筛选</button>
        <?php if ($action_filter !== ''): ?>
            <a href="/admin/audit">清除筛选</a>
        <?php endif; ?>
    </form>
    <p class="muted">示例：<code>license.</code> 查看授权存储/刷新，<code>module.</code> 查看模块生命周期。</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>编号</th><th>时间</th><th>操作</th><th>对象</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= $e($row['id']) ?></td>
                <td><?= $e($row['created_at']) ?></td>
                <td><?= $e($row['action']) ?></td>
                <td><?= $e($row['entity']) ?> <?= $e($row['entity_id']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
