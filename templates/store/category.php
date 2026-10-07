<?php
$products = $products ?? [];
ob_start();
?>
<section>
    <div class="page-heading"><span class="eyebrow">商品分类</span><h1><?= $e($category['name'] ?? '分类') ?></h1><p class="muted">共 <?= $e(count($products)) ?> 件已上架商品</p></div>
    <?php if ($products === []): ?>
        <div class="empty-state"><h2>商品正在准备中</h2><p>该分类下暂无已上架商品。</p><a href="/search">浏览其他商品</a></div>
    <?php else: ?>
        <?php require __DIR__ . '/product-grid.php'; ?>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
