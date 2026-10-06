<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<article>
    <p><a href="/orders">全部订单</a></p>
    <h1><?= $e($order['public_id']) ?></h1>
    <p>状态：<?= $e(Chinese::label($order['status'])) ?> · <?= $e(Money::format((int) $order['payable_minor'], (string) $order['currency'])) ?></p>
    <pre><?= $e($order['address_snapshot']) ?></pre>
    <ul>
        <?php foreach ($items as $item): ?>
            <li><?= $e($item['name']) ?> × <?= $e($item['qty']) ?> · <?= $e(Money::format((int) $item['unit_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($order['status'] === 'pending_payment'): ?>
        <form method="post" action="/orders/<?= $e($order['id']) ?>/pay-fake">
            <?= $csrf ?>
            <input type="hidden" name="outcome" value="succeeded">
            <button type="submit">使用演示支付通道付款</button>
        </form>
        <form method="post" action="/orders/<?= $e($order['id']) ?>/cancel"><?= $csrf ?><button>取消订单</button></form>
    <?php endif; ?>
    <?php if (in_array($order['status'], ['paid', 'completed'], true)): ?>
        <form method="post" action="/orders/<?= $e($order['id']) ?>/refund">
            <?= $csrf ?>
            <input type="hidden" name="amount_minor" value="<?= $e($order['payable_minor']) ?>">
            <label>原因 <input name="reason" value="requested"></label>
            <button type="submit">申请退款</button>
        </form>
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
