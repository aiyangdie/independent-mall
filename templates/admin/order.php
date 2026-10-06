<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <p><a href="/admin/orders">All orders</a></p>
    <h1><?= $e($order['public_id'] ?? '') ?></h1>
    <p>Status <?= $e($order['status'] ?? '') ?> · <?= $e(Money::format((int) ($order['payable_minor'] ?? 0), (string) ($order['currency'] ?? 'CNY'))) ?></p>
    <pre><?= $e($order['address_snapshot'] ?? '') ?></pre>
    <ul>
        <?php foreach ($items as $item): ?>
            <li><?= $e($item['name']) ?> × <?= $e($item['qty']) ?> · <?= $e(Money::format((int) $item['unit_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
    <h2>Payments</h2>
    <ul>
        <?php foreach ($payments as $p): ?>
            <li><?= $e($p['provider']) ?> · <?= $e($p['status']) ?> · <?= $e(Money::format((int) $p['amount_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
