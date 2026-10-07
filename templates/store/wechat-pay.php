<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section class="payment-panel">
    <p><a href="/orders/<?= $e($order['id']) ?>">返回订单详情</a></p>
    <h1>微信扫码支付</h1>
    <p>订单 <?= $e($order['public_id']) ?></p>
    <p class="price"><?= $e(Money::format((int) $order['payable_minor'], $order['currency'])) ?></p>
    <img class="payment-qr" src="<?= $e($qr_src) ?>" alt="请使用微信扫描此订单付款二维码" width="280" height="280">
    <p>请使用微信扫码付款。支付是否成功，以微信回调或查询结果为准。</p>
    <form method="post" action="/orders/<?= $e($order['id']) ?>/wechat-sync">
        <?= $csrf ?><button type="submit">已付款，查询支付结果</button>
    </form>
    <p class="muted">当前接入扫码支付（Native），不包含手机网页内直接调起微信的 H5/JSAPI 支付。</p>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
