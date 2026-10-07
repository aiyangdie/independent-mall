<?php ob_start(); ?>
<section class="payment-panel">
    <h1><?= $e($title) ?></h1>
    <p class="error" role="alert"><?= $e($error ?? '服务暂不可用，请稍后重试。') ?></p>
    <p><a class="button secondary" href="/orders">查看我的订单</a> · <a href="/">返回商城首页</a></p>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
