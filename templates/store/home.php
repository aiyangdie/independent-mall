<?php
$products = $products ?? [];
ob_start();
?>
<?php if (!empty($notice)): ?>
    <p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<section class="hero">
  <div><span class="eyebrow">独立 · 安全 · 自主管理</span><h1>发现值得信赖的<br>商品与专业服务</h1><p>精选实体商品、正版数字内容与专业服务，为你提供清晰、安心的购物体验。</p><a class="primary-cta" href="#products">立即选购</a></div>
  <div class="hero-visual" aria-hidden="true"><span>正品</span><strong>安心交易</strong><small>本地数据 · 自主运营</small></div>
</section>
<?php if (!empty($categories)): ?>
    <section id="categories"><div class="section-heading"><span><small>浏览分类</small><h2>找到你需要的内容</h2></span></div><nav class="category-grid" aria-label="分类">
        <?php foreach ($categories as $c): ?>
            <a href="/c/<?= $e($c['slug']) ?>"><b><?= $e($c['slug'] === 'physical-goods' ? '物' : ($c['slug'] === 'digital-goods' ? '数' : '服')) ?></b><span><strong><?= $e($c['name']) ?></strong><small>查看全部商品</small></span><i>→</i></a>
        <?php endforeach; ?>
    </nav></section>
<?php endif; ?>
<div id="products" class="section-heading"><span><small>精选商品</small><h2>为你推荐</h2></span><a href="/search?q=">查看全部 →</a></div>
<?php if ($products === []): ?>
    <div class="empty-state"><h2>商品正在准备中</h2><p>暂时没有已上架商品，请稍后再来看看。</p></div>
<?php else: ?>
<section class="grid">
    <?php foreach ($products as $p): ?>
        <article class="card">
            <a class="product-cover" href="/p/<?= $e($p['slug']) ?>"><span><?= $e(mb_substr($p['name'], 0, 1)) ?></span></a>
            <div class="product-body"><small><?= $e(\IndependentMall\Support\Chinese::label($p['type'])) ?></small><h2><a href="/p/<?= $e($p['slug']) ?>"><?= $e($p['name']) ?></a></h2><p><?= $e($p['summary']) ?></p><footer><span><?= $e($p['category_name']) ?></span><a href="/p/<?= $e($p['slug']) ?>">查看详情 →</a></footer></div>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
