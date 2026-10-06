<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <h1>Orders</h1>
    <ul>
        <?php foreach ($orders as $o): ?>
            <li><a href="/orders/<?= $e($o['id']) ?>"><?= $e($o['public_id']) ?></a> · <?= $e($o['status']) ?> · <?= $e(Money::format((int) $o['payable_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
