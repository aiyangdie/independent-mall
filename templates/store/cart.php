<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <h1>Cart</h1>
    <?php if ($lines === []): ?>
        <p>Your cart is empty.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($lines as $line): ?>
                <li><?= $e($line['product_name']) ?> × <?= $e($line['qty']) ?> · <?= $e(Money::format((int) $line['line_minor'])) ?>
                    <?php if ($line['issue'] !== ''): ?><strong><?= $e($line['issue']) ?></strong><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p><a href="/checkout">Checkout</a></p>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
