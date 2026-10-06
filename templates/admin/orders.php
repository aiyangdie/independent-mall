<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <p><a href="/admin">后台首页</a> · <a href="/admin/payments">支付记录</a> · <a href="/admin/refunds">退款管理</a></p>
    <h1>订单管理</h1>
    <div class="table-wrap">
    <table>
        <caption>全部订单</caption>
        <thead><tr><th>订单号</th><th>状态</th><th>应付金额</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td><a href="/admin/orders/<?= $e($o['id']) ?>"><?= $e($o['public_id']) ?></a></td>
                <td><?= $e(Chinese::label($o['status'])) ?></td>
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
