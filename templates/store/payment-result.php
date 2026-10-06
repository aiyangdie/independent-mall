<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <h1>Payment result</h1>
    <p>Order <?= $e($order['public_id'] ?? '') ?> is now <strong><?= $e($order['status'] ?? '') ?></strong>.</p>
    <p>Amount <?= $e(Money::format((int) ($order['payable_minor'] ?? 0), (string) ($order['currency'] ?? 'CNY'))) ?> (fake gateway, not a live charge).</p>
    <?php if (!empty($payments)): ?>
        <div class="table-wrap">
        <table>
            <caption>Payment attempts</caption>
            <thead><tr><th>Provider</th><th>Status</th><th>Amount</th><th>Reference</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= $e($p['provider']) ?></td>
                    <td><?= $e($p['status']) ?></td>
                    <td><?= $e(Money::format((int) $p['amount_minor'], (string) $p['currency'])) ?></td>
                    <td><?= $e($p['provider_ref'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    <?php if (!empty($flash_tokens)): ?>
        <p>Download links (shown once):</p>
        <ul>
            <?php foreach ($flash_tokens as $tok): ?>
                <li><a href="/deliveries/<?= $e($tok) ?>">Download delivery</a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p><a href="/orders/<?= $e($order['id'] ?? '') ?>">View order</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
