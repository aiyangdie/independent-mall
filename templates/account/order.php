<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<article>
    <p><a href="/orders">All orders</a></p>
    <h1><?= $e($order['public_id']) ?></h1>
    <p>Status: <?= $e($order['status']) ?> · <?= $e(Money::format((int) $order['payable_minor'], (string) $order['currency'])) ?></p>
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
            <button type="submit">Pay with fake gateway</button>
        </form>
        <form method="post" action="/orders/<?= $e($order['id']) ?>/cancel"><?= $csrf ?><button>Cancel order</button></form>
    <?php endif; ?>
    <?php if (in_array($order['status'], ['paid', 'completed'], true)): ?>
        <form method="post" action="/orders/<?= $e($order['id']) ?>/refund">
            <?= $csrf ?>
            <input type="hidden" name="amount_minor" value="<?= $e($order['payable_minor']) ?>">
            <label>Reason <input name="reason" value="requested"></label>
            <button type="submit">Request refund</button>
        </form>
    <?php endif; ?>
    <?php if (!empty($flash_tokens)): ?>
        <p>Download links (shown once):</p>
        <ul>
            <?php foreach ($flash_tokens as $tok): ?>
                <li><a href="/deliveries/<?= $e($tok) ?>">Download delivery</a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
