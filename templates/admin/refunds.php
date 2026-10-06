<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <p><a href="/admin">后台首页</a> · <a href="/admin/orders">订单</a> · <a href="/admin/payments">支付记录</a></p>
    <h1>退款管理</h1>
    <p class="notice">当前批准退款只会更新演示记录，不会向银行或支付平台发起真实退款。</p>
    <div class="table-wrap">
    <table>
        <caption>退款申请</caption>
        <thead><tr><th>编号</th><th>订单</th><th>状态</th><th>金额</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($refunds as $r): ?>
            <tr>
                <td><?= $e($r['id']) ?></td>
                <td><a href="/admin/orders/<?= $e($r['order_id']) ?>"><?= $e($r['public_id']) ?></a></td>
                <td><?= $e(Chinese::label($r['status'])) ?></td>
                <td><?= $e(Money::format((int) $r['amount_minor'])) ?></td>
                <td>
                    <?php if ($r['status'] === 'requested'): ?>
                        <form method="post" action="/admin/refunds/<?= $e($r['id']) ?>/approved">
                            <?= $csrf ?>
                            <button type="submit">批准（演示）</button>
                        </form>
                        <form method="post" action="/admin/refunds/<?= $e($r['id']) ?>/rejected">
                            <?= $csrf ?>
                            <button class="danger" type="submit">驳回</button>
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
