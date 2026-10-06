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
    <?php foreach ($variants as $v): ?>
        <form method="post" action="/cart/add">
            <?= $csrf ?>
            <input type="hidden" name="variant_id" value="<?= $e($v['id']) ?>">
            <p><?= $e($v['title']) ?> · <?= $e($v['sku']) ?> · <?= $e(Money::format((int) $v['price_minor'], (string) $v['currency'])) ?>
                · in stock <?= $e((int) $v['on_hand'] - (int) $v['reserved']) ?>
                <label>Qty <input type="number" name="qty" value="1" min="1" max="99"></label>
                <button type="submit">Add to cart</button>
            </p>
        </form>
    <?php endforeach; ?>
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
