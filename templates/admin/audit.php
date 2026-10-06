<?php
ob_start();
$action_filter = (string) ($action_filter ?? '');
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Audit log</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="get" action="/admin/audit" role="search">
        <label>Action prefix <input name="action" value="<?= $e($action_filter) ?>" placeholder="license."></label>
        <button type="submit">Filter</button>
        <?php if ($action_filter !== ''): ?>
            <a href="/admin/audit">Clear</a>
        <?php endif; ?>
    </form>
    <p class="muted">Examples: <code>license.</code> for store/refresh, <code>module.</code> for module lifecycle.</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Time</th><th>Action</th><th>Entity</th></tr></thead>
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
