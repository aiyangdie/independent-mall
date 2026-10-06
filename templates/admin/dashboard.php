<?php
ob_start();
?>
<section>
    <h1>运营概览</h1>
    <p>当前管理员：<?= $e($user['email'] ?? '') ?></p>
    <dl class="stats">
        <div><dt>版本</dt><dd><?= $e($health['edition'] ?? '') ?></dd></div>
        <div><dt>是否需要授权</dt><dd><?= !empty($health['license_required']) ? '是' : '否' ?></dd></div>
        <div><dt>是否依赖网络</dt><dd><?= !empty($health['network_required']) ? '是' : '否' ?></dd></div>
        <div><dt>数据库</dt><dd><?= $e($health['database'] ?? '') ?></dd></div>
        <div><dt>PHP</dt><dd><?= $e($health['php'] ?? '') ?></dd></div>
    </dl>
    <nav class="admin-links" aria-label="后台功能"><a href="/admin/products">商品</a><a href="/admin/skus">规格与库存</a><a href="/admin/categories">分类</a><a href="/admin/orders">订单</a><a href="/admin/payments">支付记录</a><a href="/admin/refunds">退款</a><a href="/admin/customers">顾客</a><a href="/admin/modules">商业模块</a><a href="/admin/rules">禁售规则</a><a href="/admin/settings">设置</a><a href="/admin/audit">审计日志</a></nav>
    <p class="muted">社区版下单不依赖授权服务器。商业模块使用本地签名授权文件，授权失效不会影响社区版核心功能。</p>
    <form method="post" action="/admin/logout">
        <?= $csrf ?>
        <button type="submit">退出登录</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
