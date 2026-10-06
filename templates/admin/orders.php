<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Orders</h1>
    <table>
        <thead><tr><th>Public ID</th><th>Status</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr><td><?= $e($o['public_id']) ?></td><td><?= $e($o['status']) ?></td><td><?= $e(Money::format((int) $o['payable_minor'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <h2>Refunds</h2>
    <?php foreach ($refunds as $r): ?>
        <p>#<?= $e($r['id']) ?> · <?= $e($r['status']) ?> · <?= $e(Money::format((int) $r['amount_minor'])) ?>
            <?php if ($r['status'] === 'requested'): ?>
                <form method="post" action="/admin/refunds/<?= $e($r['id']) ?>/approved"><?= $csrf ?><button>Approve (fake)</button></form>
                <form method="post" action="/admin/refunds/<?= $e($r['id']) ?>/rejected"><?= $csrf ?><button>Reject</button></form>
            <?php endif; ?>
        </p>
    <?php endforeach; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
