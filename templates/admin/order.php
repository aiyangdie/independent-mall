<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <p><a href="/admin/orders">返回订单列表</a></p>
    <h1><?= $e($order['public_id'] ?? '') ?></h1>
    <p>状态：<?= $e(Chinese::label($order['status'] ?? '')) ?> · 金额：<?= $e(Money::format((int) ($order['payable_minor'] ?? 0), (string) ($order['currency'] ?? 'CNY'))) ?></p>
    <pre><?= $e($order['address_snapshot'] ?? '') ?></pre>
    <ul>
        <?php foreach ($items as $item): ?>
            <li><?= $e($item['name']) ?> × <?= $e($item['qty']) ?> · <?= $e(Money::format((int) $item['unit_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
    <h2>支付记录</h2>
    <ul>
        <?php foreach ($payments as $p): ?>
            <li><?= $e(Chinese::label($p['provider'])) ?> · <?= $e(Chinese::label($p['status'])) ?> · <?= $e(Money::format((int) $p['amount_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
