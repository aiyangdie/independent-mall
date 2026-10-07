<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
$address = json_decode((string) $order['address_snapshot'], true) ?: [];
$shipment = $shipment ?? null;
$refunds = $refunds ?? [];
$refundable_minor = $refundable_minor ?? (int) $order['payable_minor'];
?>
<article>
    <p><a href="/orders">全部订单</a></p>
    <h1><?= $e($order['public_id']) ?></h1>
    <p>状态：<?= $e(Chinese::label($order['status'])) ?> · <?= $e(Money::format((int) $order['payable_minor'], (string) $order['currency'])) ?></p>
    <?php if ($address !== []): ?>
    <section class="card"><h2>收货信息</h2>
        <p><?= $e($address['recipient'] ?? '') ?> · <?= $e($address['phone'] ?? '') ?></p>
        <p><?= $e(implode(' ', array_filter([$address['region'] ?? '', $address['line1'] ?? '', $address['line2'] ?? '', $address['postal_code'] ?? '']))) ?></p>
    </section>
    <?php endif; ?>
    <ul>
        <?php foreach ($items as $item): ?>
            <li><?= $e($item['name']) ?> × <?= $e($item['qty']) ?> · <?= $e(Money::format((int) $item['unit_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($order['status'] === 'pending_payment'): ?>
        <section class="card">
            <h2>选择付款方式</h2>
            <?php if (!empty($wechat_payment_available)): ?>
                <form method="post" action="/orders/<?= $e($order['id']) ?>/pay-wechat">
                    <?= $csrf ?>
                    <button type="submit">微信扫码支付</button>
                </form>
                <p class="muted">使用微信支付官方接口；需在公网 HTTPS 与商户资料配置完成后才能真实扣款。</p>
            <?php endif; ?>
            <?php if (!empty($demo_payment_available)): ?>
                <form method="post" action="/orders/<?= $e($order['id']) ?>/pay-fake">
                    <?= $csrf ?>
                    <input type="hidden" name="outcome" value="succeeded">
                    <button type="submit">使用演示支付通道付款</button>
                </form>
                <p class="muted">演示通道不会真实扣款，仅供本地联调。</p>
            <?php endif; ?>
            <?php if (empty($wechat_payment_available) && empty($demo_payment_available)): ?>
                <p class="alert" role="alert">商家尚未开通可用支付方式。请稍后再试，或联系客服。</p>
            <?php endif; ?>
        </section>
        <form method="post" action="/orders/<?= $e($order['id']) ?>/cancel"><?= $csrf ?><button>取消订单</button></form>
    <?php endif; ?>
    <?php if ($shipment): ?>
        <section class="card"><h2>物流信息</h2>
            <p><?= $e($shipment['carrier']) ?> · 单号：<?= $e($shipment['tracking_number']) ?></p>
            <p><?= $shipment['status'] === 'delivered' ? '已确认收货' : '商家已发货，等待收货' ?> · 发货时间：<?= $e($shipment['shipped_at']) ?></p>
            <p class="muted">物流信息由商家填写，此处不提供实时轨迹或自动签收。</p>
            <?php if ($order['status'] === 'fulfilling' && $shipment['status'] === 'shipped'): ?>
                <form method="post" action="/orders/<?= $e($order['id']) ?>/receive"><?= $csrf ?><button type="submit">确认已收到商品</button></form>
            <?php endif; ?>
        </section>
    <?php elseif ($order['status'] === 'paid'): ?>
        <p class="notice">付款已确认，等待商家发货。</p>
    <?php endif; ?>
    <?php if (in_array($order['status'], ['paid', 'fulfilling', 'completed', 'refund_requested'], true) && $refundable_minor > 0): ?>
        <form method="post" action="/orders/<?= $e($order['id']) ?>/refund">
            <?= $csrf ?>
            <input type="hidden" name="amount_minor" value="<?= $e($refundable_minor) ?>">
            <input type="hidden" name="idempotency_key" value="<?= $e('refund-' . $order['public_id'] . '-' . bin2hex(random_bytes(8))) ?>">
            <p>本次申请金额：<?= $e(Money::format($refundable_minor, (string) $order['currency'])) ?></p>
            <label>退款原因 <input name="reason" required maxlength="1000" placeholder="请说明退款原因"></label>
            <button type="submit">申请退款</button>
        </form>
    <?php endif; ?>
    <?php if ($refunds !== []): ?>
        <h2>退款进度</h2><ul>
        <?php foreach ($refunds as $refund): ?>
            <li><?= $e(Money::format((int) $refund['amount_minor'], (string) $order['currency'])) ?> · <?= $e(Chinese::label($refund['status'])) ?><?= $refund['provider'] === 'fake' ? '（演示记录，不发生真实出款）' : '' ?> · <?= $e($refund['reason']) ?></li>
        <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($deliveries !== []): ?>
        <h2>数字交付物</h2><ul>
        <?php foreach ($deliveries as $delivery): ?>
            <li><?= $e($delivery['label']) ?> · 已下载 <?= $e($delivery['download_count']) ?>/<?= $e($delivery['max_downloads']) ?> 次
            <?php if (in_array($order['status'], ['paid', 'fulfilling', 'completed'], true) && !(int) $delivery['revoked'] && (int) $delivery['download_count'] < (int) $delivery['max_downloads'] && $delivery['expires_at'] >= gmdate('c')): ?>
                <form method="post" action="/orders/<?= $e($order['id']) ?>/deliveries/<?= $e($delivery['id']) ?>/link"><?= $csrf ?><button type="submit">获取下载链接</button></form>
            <?php else: ?><span class="muted">暂不可下载</span><?php endif; ?>
            </li>
        <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!empty($flash_tokens)): ?>
        <p>下载链接（仅显示一次）：</p>
        <ul>
            <?php foreach ($flash_tokens as $tok): ?>
                <li><a href="/deliveries/<?= $e($tok) ?>">下载交付物</a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
