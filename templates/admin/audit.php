<?php
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Audit log</h1>
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
