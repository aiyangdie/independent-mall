<?php
$products = $products ?? [];
$q = $q ?? '';
ob_start();
?>
<section>
    <div class="page-heading"><span class="eyebrow">商品目录</span><h1><?= trim($q) === '' ? '全部商品' : '搜索结果' ?></h1>
    <p class="muted"><?= trim($q) === '' ? '浏览全部已上架的商品、数字内容和专业服务。' : '关键词：' . $e($q) ?> · 共 <?= $e(count($products)) ?> 件商品</p></div>
    <?php if ($products === []): ?>
        <div class="empty-state"><h2><?= trim($q) === '' ? '商品正在准备中' : '没有找到相关商品' ?></h2><p>试试其他关键词，或稍后再来看看。</p><a href="/search">浏览全部商品</a></div>
    <?php else: ?>
        <?php require __DIR__ . '/product-grid.php'; ?>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
