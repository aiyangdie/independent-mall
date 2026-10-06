<?php
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Prohibited listing rules</h1>
    <table>
        <thead><tr><th>Pattern</th><th>Field</th><th>Action</th><th>Enabled</th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
            <tr>
                <td><?= $e($r['pattern']) ?></td>
                <td><?= $e($r['match_field']) ?></td>
                <td><?= $e($r['action']) ?></td>
                <td><?= ((int) $r['enabled'] === 1) ? 'yes' : 'no' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
