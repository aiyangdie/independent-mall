<?php
$products = $products ?? [];
ob_start();
?>
<section>
    <h1><?= $e($category['name'] ?? 'Category') ?></h1>
    <?php if ($products === []): ?>
        <p>No published listings in this category.</p>
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
