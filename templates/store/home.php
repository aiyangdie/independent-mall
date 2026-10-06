<?php
$products = $products ?? [];
ob_start();
?>
<?php if (!empty($notice)): ?>
    <p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<section class="hero">
    <h1>Goods and services for lawful trade</h1>
    <p>Physical products, licensed digital goods, memberships, and professional services. Listings that promote fraud, infringement, cheats, or unofficial virtual items are blocked by policy.</p>
</section>
<?php if (!empty($categories)): ?>
    <nav class="chips" aria-label="Categories">
        <?php foreach ($categories as $c): ?>
            <a href="/c/<?= $e($c['slug']) ?>"><?= $e($c['name']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php if ($products === []): ?>
    <p>No published listings yet.</p>
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
