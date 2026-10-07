<?php
$products = $products ?? [];
ob_start();
?>
<?php if (!empty($notice)): ?>
    <p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<section class="hero">
  <div><span class="eyebrow">独立 · 安全 · 自主管理</span><h1>发现值得信赖的<br>商品与专业服务</h1><p>精选实体商品、正版数字内容与专业服务，为你提供清晰、安心的购物体验。</p><a class="primary-cta" href="#products">立即选购</a></div>
  <div class="hero-visual" aria-hidden="true"><div class="visual-orbit one"></div><div class="visual-orbit two"></div><span>品质精选</span><strong>安心交易</strong><small>数据自主 · 规则透明</small></div>
</section>
<section class="trust-strip" aria-label="商城特点"><div><b>✓</b><span><strong>规则透明</strong><small>商品与退款说明清晰可查</small></span></div><div><b>◇</b><span><strong>数据自主</strong><small>核心业务可在本地独立运行</small></span></div><div><b>↺</b><span><strong>售后可追踪</strong><small>订单与退款状态全程记录</small></span></div></section>
<?php if (!empty($categories)): ?>
    <section id="categories"><div class="section-heading"><span><small>浏览分类</small><h2>找到你需要的内容</h2></span></div><nav class="category-grid" aria-label="分类">
        <?php foreach ($categories as $c): ?>
            <a href="/c/<?= $e($c['slug']) ?>"><b><?= $e($c['slug'] === 'physical-goods' ? '物' : ($c['slug'] === 'digital-goods' ? '数' : '服')) ?></b><span><strong><?= $e($c['name']) ?></strong><small>查看全部商品</small></span><i>→</i></a>
        <?php endforeach; ?>
    </nav></section>
<?php endif; ?>
<div id="products" class="section-heading"><span><small>精选商品</small><h2>为你推荐</h2></span><a href="/search">查看全部 →</a></div>
<?php if ($products === []): ?>
    <div class="empty-state"><h2>商品正在准备中</h2><p>暂时没有已上架商品，请稍后再来看看。</p></div>
<?php else: ?>
<?php require __DIR__ . '/product-grid.php'; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
