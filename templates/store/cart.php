<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <h1>购物车</h1>
    <?php if ($lines === []): ?>
        <div class="empty-state"><h2>购物车还是空的</h2><p>挑选喜欢的商品，开始你的购物之旅。</p><a href="/search">去选购商品 →</a></div>
    <?php else: ?>
        <ul class="order-list">
            <?php foreach ($lines as $line): ?>
                <li><a href="/p/<?= $e($line['slug']) ?>"><?= $e($line['product_name']) ?></a><span>数量 <?= $e($line['qty']) ?></span><strong><?= $e(Money::format((int) $line['line_minor'], (string) ($line['currency'] ?? 'CNY'))) ?></strong>
                    <?php if ($line['issue'] !== ''): ?><span class="alert"><?= $e(Chinese::label($line['issue'])) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p><a class="primary-cta" href="/checkout">去结算 →</a></p>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
