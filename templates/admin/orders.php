<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a> · <a href="/admin/payments">Payments</a> · <a href="/admin/refunds">Refunds</a></p>
    <h1>Orders</h1>
    <div class="table-wrap">
    <table>
        <caption>All orders</caption>
        <thead><tr><th>Public ID</th><th>Status</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td><a href="/admin/orders/<?= $e($o['id']) ?>"><?= $e($o['public_id']) ?></a></td>
                <td><?= $e($o['status']) ?></td>
                <td><?= $e(Money::format((int) $o['payable_minor'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
