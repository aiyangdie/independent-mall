<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
$address = json_decode((string) $order['address_snapshot'], true) ?: [];
$shipment = $shipment ?? null;
$hasPhysical = count(array_filter($items, fn (array $item) => $item['product_type'] === 'physical')) > 0;
?>
<section>
    <p><a href="/admin/orders">返回订单列表</a></p>
    <h1><?= $e($order['public_id'] ?? '') ?></h1>
    <p>状态：<?= $e(Chinese::label($order['status'] ?? '')) ?> · 金额：<?= $e(Money::format((int) ($order['payable_minor'] ?? 0), (string) ($order['currency'] ?? 'CNY'))) ?></p>
    <?php if ($address !== []): ?>
        <h2>收货信息</h2><p><?= $e($address['recipient'] ?? '') ?> · <?= $e($address['phone'] ?? '') ?></p>
        <p><?= $e(implode(' ', array_filter([$address['region'] ?? '', $address['line1'] ?? '', $address['line2'] ?? '', $address['postal_code'] ?? '']))) ?></p>
    <?php endif; ?>
    <ul>
        <?php foreach ($items as $item): ?>
            <li><?= $e($item['name']) ?> × <?= $e($item['qty']) ?> · <?= $e(Money::format((int) $item['unit_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($shipment): ?>
        <h2>物流信息</h2>
        <p><?= $e($shipment['carrier']) ?> · 单号：<?= $e($shipment['tracking_number']) ?></p>
        <p><?= $shipment['status'] === 'delivered' ? '顾客已确认收货' : '已发货，等待顾客确认' ?> · <?= $e($shipment['shipped_at']) ?></p>
    <?php elseif ($hasPhysical && $order['status'] === 'paid'): ?>
        <h2>订单发货</h2>
        <p class="muted">本版支持整单一件包裹。请实际出库后再录入，不提供自动物流轨迹。</p>
        <form method="post" action="/admin/orders/<?= $e($order['id']) ?>/ship">
            <?= $csrf ?>
            <input type="hidden" name="idempotency_key" value="<?= $e('ship-' . $order['public_id']) ?>">
            <label>物流公司 <input name="carrier" required maxlength="80" placeholder="例如：顺丰速运"></label>
            <label>物流单号 <input name="tracking_number" required minlength="3" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9 -]{2,79}" placeholder="填写真实物流单号"></label>
            <button type="submit">确认已发货</button>
        </form>
    <?php endif; ?>
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
