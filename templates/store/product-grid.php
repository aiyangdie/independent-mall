<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
?>
<div class="grid product-grid">
    <?php foreach ($products as $p): ?>
        <article class="card product-card">
            <a class="product-cover" href="/p/<?= $e($p['slug']) ?>" tabindex="-1" aria-hidden="true">
                <?php if (!empty($p['image_key'])): ?>
                    <img src="/media/<?= $e($p['image_key']) ?>" alt="" loading="lazy" width="480" height="320">
                <?php else: ?>
                    <span><?= $e(mb_substr((string) $p['name'], 0, 1)) ?></span>
                <?php endif; ?>
            </a>
            <div class="product-body">
                <small><?= $e(Chinese::label($p['type'])) ?></small>
                <h2><a href="/p/<?= $e($p['slug']) ?>"><?= $e($p['name']) ?></a></h2>
                <p><?= $e($p['summary']) ?></p>
                <div class="price-row">
                    <strong><?= isset($p['price_minor']) ? $e(Money::format((int) $p['price_minor'], (string) ($p['currency'] ?? 'CNY'))) : '价格待定' ?></strong>
                    <?php if (isset($p['price_minor'])): ?><span>起</span><?php endif; ?>
                </div>
                <footer><span><?= $e($p['category_name']) ?></span><a href="/p/<?= $e($p['slug']) ?>" aria-label="<?= $e('查看' . $p['name'] . '详情') ?>">查看详情 →</a></footer>
            </div>
        </article>
    <?php endforeach; ?>
</div>
