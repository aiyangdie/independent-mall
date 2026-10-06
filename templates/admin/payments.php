<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <p><a href="/admin">后台首页</a> · <a href="/admin/orders">订单</a> · <a href="/admin/refunds">退款</a></p>
    <h1>支付记录</h1>
    <p class="notice">当前为演示支付通道，不会发生真实扣款。接入生产商户前请勿对外收款。</p>
    <div class="table-wrap">
    <table>
        <caption>全部支付记录</caption>
        <thead><tr><th>编号</th><th>订单</th><th>状态</th><th>金额</th><th>支付通道</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= $e($p['id']) ?></td>
                <td><a href="/admin/orders/<?= $e($p['order_id']) ?>"><?= $e($p['public_id']) ?></a></td>
                <td><?= $e(Chinese::label($p['status'])) ?></td>
                <td><?= $e(Money::format((int) $p['amount_minor'], (string) $p['currency'])) ?></td>
                <td><?= $e(Chinese::label($p['provider'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
