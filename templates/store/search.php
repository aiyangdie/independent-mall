<?php
$products = $products ?? [];
$q = $q ?? '';
ob_start();
?>
<section>
    <h1>Search</h1>
    <p>Query: <?= $e($q) ?></p>
    <?php if ($products === []): ?>
        <p>No published listings matched.</p>
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
