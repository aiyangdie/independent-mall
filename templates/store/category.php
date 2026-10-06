<?php
$products = $products ?? [];
ob_start();
?>
<section>
    <h1><?= $e($category['name'] ?? '分类') ?></h1>
    <?php if ($products === []): ?>
        <p>该分类下暂无已上架商品。</p>
    <?php else: ?>
        <ul>
            <?php foreach ($products as $p): ?>
                <li><a href="/p/<?= $e($p['slug']) ?>"><?= $e($p['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
