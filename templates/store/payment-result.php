<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <h1>支付结果</h1>
    <p>订单 <?= $e($order['public_id'] ?? '') ?> 当前状态为 <strong><?= $e(Chinese::label($order['status'] ?? 'unknown')) ?></strong>。</p>
    <p>金额 <?= $e(Money::format((int) ($order['payable_minor'] ?? 0), (string) ($order['currency'] ?? 'CNY'))) ?>。</p>
    <?php if (($payment_provider ?? 'fake') === 'fake'): ?>
        <p class="notice">演示支付通道，不会真实扣款。</p>
    <?php else: ?>
        <p class="notice">支付渠道：<?= $e(Chinese::label($payment_provider)) ?>。支付状态以服务器验证后的支付平台通知为准。</p>
    <?php endif; ?>
    <?php if (!empty($payments)): ?>
        <div class="table-wrap">
        <table>
            <caption>支付尝试记录</caption>
            <thead><tr><th>渠道</th><th>状态</th><th>金额</th><th>参考号</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= $e(Chinese::label($p['provider'])) ?></td>
                    <td><?= $e(Chinese::label($p['status'])) ?></td>
                    <td><?= $e(Money::format((int) $p['amount_minor'], (string) $p['currency'])) ?></td>
                    <td><?= $e($p['provider_ref'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    <?php if (!empty($flash_tokens)): ?>
        <p>下载链接（仅显示一次）：</p>
        <ul>
            <?php foreach ($flash_tokens as $tok): ?>
                <li><a href="/deliveries/<?= $e($tok) ?>">下载交付物</a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p><a href="/orders/<?= $e($order['id'] ?? '') ?>">查看订单</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
