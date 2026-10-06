<?php
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Customers</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Email</th><th>Username</th><th>Roles</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
            <tr>
                <td><?= $e($a['id']) ?></td>
                <td><?= $e($a['email']) ?></td>
                <td><?= $e($a['username']) ?></td>
                <td><?= $e($a['roles'] ?? '') ?></td>
                <td><?= $e($a['status']) ?></td>
                <td>
                    <?php if (($a['status'] ?? '') === 'active'): ?>
                        <form method="post" action="/admin/customers/<?= $e($a['id']) ?>/locked"><?= $csrf ?><button type="submit">Lock</button></form>
                    <?php else: ?>
                        <form method="post" action="/admin/customers/<?= $e($a['id']) ?>/active"><?= $csrf ?><button type="submit">Unlock</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
