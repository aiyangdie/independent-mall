<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a> · <a href="/admin/orders">Orders</a> · <a href="/admin/payments">Payments</a></p>
    <h1>Refunds</h1>
    <p class="muted">Approving uses the fake adapter and does not move money at a bank.</p>
    <div class="table-wrap">
    <table>
        <caption>Refund requests</caption>
        <thead><tr><th>ID</th><th>Order</th><th>Status</th><th>Amount</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($refunds as $r): ?>
            <tr>
                <td><?= $e($r['id']) ?></td>
                <td><a href="/admin/orders/<?= $e($r['order_id']) ?>"><?= $e($r['public_id']) ?></a></td>
                <td><?= $e($r['status']) ?></td>
                <td><?= $e(Money::format((int) $r['amount_minor'])) ?></td>
                <td>
                    <?php if ($r['status'] === 'requested'): ?>
                        <form method="post" action="/admin/refunds/<?= $e($r['id']) ?>/approved">
                            <?= $csrf ?>
                            <button type="submit">Approve (fake)</button>
                        </form>
                        <form method="post" action="/admin/refunds/<?= $e($r['id']) ?>/rejected">
                            <?= $csrf ?>
                            <button type="submit">Reject</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
