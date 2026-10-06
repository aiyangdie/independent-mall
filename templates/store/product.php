<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<article>
    <p><a href="/">Back to catalog</a></p>
    <h1><?= $e($product['name']) ?></h1>
    <p><?= $e($product['summary']) ?></p>
    <p><?= nl2br($e($product['description'])) ?></p>
    <?php foreach ($images as $img): ?>
        <p><img src="/media/<?= $e($img['storage_key']) ?>" alt="" width="320"></p>
    <?php endforeach; ?>
    <h2>Options</h2>
    <ul>
        <?php foreach ($variants as $v): ?>
            <li><?= $e($v['title']) ?> · <?= $e($v['sku']) ?> · <?= $e(Money::format((int) $v['price_minor'], (string) $v['currency'])) ?>
                · in stock <?= $e((int) $v['on_hand'] - (int) $v['reserved']) ?></li>
        <?php endforeach; ?>
    </ul>
    <h2>Report this listing</h2>
    <form method="post" action="/p/<?= $e($product['slug']) ?>/report">
        <?= $csrf ?>
        <label>Reason
            <input type="text" name="reason" required>
        </label>
        <label>Details
            <input type="text" name="detail">
        </label>
        <button type="submit">Submit report</button>
    </form>
</article>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
