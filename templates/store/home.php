<?php
$products = $products ?? [];
ob_start();
?>
<?php if (!empty($notice)): ?>
    <p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<section class="hero">
    <h1>正规商品与服务交易</h1>
    <p>实体商品、正版数字商品、会员与专业服务。宣传欺诈、侵权、外挂或无资质虚拟物品的上架会被策略拦截。</p>
</section>
<?php if (!empty($categories)): ?>
    <nav class="chips" aria-label="分类">
        <?php foreach ($categories as $c): ?>
            <a href="/c/<?= $e($c['slug']) ?>"><?= $e($c['name']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php if ($products === []): ?>
    <p>暂无已上架商品。</p>
<?php else: ?>
<section class="grid">
    <?php foreach ($products as $p): ?>
        <article class="card">
            <h2><a href="/p/<?= $e($p['slug']) ?>"><?= $e($p['name']) ?></a></h2>
            <p><?= $e($p['summary']) ?></p>
            <p class="muted"><?= $e($p['category_name']) ?> · <?= $e($p['type']) ?></p>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
