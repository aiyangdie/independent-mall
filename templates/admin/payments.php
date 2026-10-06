<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a> · <a href="/admin/orders">Orders</a> · <a href="/admin/refunds">Refunds</a></p>
    <h1>Payments</h1>
    <p class="muted">Fake adapter only. Live acquirers are not connected.</p>
    <div class="table-wrap">
    <table>
        <caption>Payment records</caption>
        <thead><tr><th>ID</th><th>Order</th><th>Status</th><th>Amount</th><th>Provider</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= $e($p['id']) ?></td>
                <td><a href="/admin/orders/<?= $e($p['order_id']) ?>"><?= $e($p['public_id']) ?></a></td>
                <td><?= $e($p['status']) ?></td>
                <td><?= $e(Money::format((int) $p['amount_minor'], (string) $p['currency'])) ?></td>
                <td><?= $e($p['provider']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
