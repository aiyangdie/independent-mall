<?php
$products = $products ?? [];
$q = $q ?? '';
ob_start();
?>
<section>
    <h1>搜索</h1>
    <p>关键词：<?= $e($q) ?></p>
    <?php if ($products === []): ?>
        <p>没有匹配的已上架商品。</p>
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
