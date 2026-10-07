<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <h1>我的订单</h1>
    <?php if ($orders === []): ?><div class="empty-state"><h2>还没有订单</h2><p>选购商品后，可在这里查看订单进度。</p><a href="/search">去选购商品 →</a></div><?php endif; ?>
    <ul class="order-list">
        <?php foreach ($orders as $o): ?>
            <li><a href="/orders/<?= $e($o['id']) ?>"><?= $e($o['public_id']) ?></a><span class="status-label"><?= $e(Chinese::label($o['status'])) ?></span><strong><?= $e(Money::format((int) $o['payable_minor'], (string) ($o['currency'] ?? 'CNY'))) ?></strong></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
