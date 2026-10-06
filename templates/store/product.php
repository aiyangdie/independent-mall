<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<article>
    <p><a href="/">返回目录</a></p>
    <h1><?= $e($product['name']) ?></h1>
    <p><?= $e($product['summary']) ?></p>
    <p><?= nl2br($e($product['description'])) ?></p>
    <?php foreach ($images as $img): ?>
        <p><img src="/media/<?= $e($img['storage_key']) ?>" alt="<?= $e($product['name']) ?>" width="320"></p>
    <?php endforeach; ?>
    <h2>规格选项</h2>
    <?php foreach ($variants as $v): ?>
        <form class="buy-row" method="post" action="/cart/add">
            <?= $csrf ?>
            <input type="hidden" name="variant_id" value="<?= $e($v['id']) ?>">
            <p><?= $e($v['title']) ?> · <?= $e($v['sku']) ?> · <?= $e(Money::format((int) $v['price_minor'], (string) $v['currency'])) ?>
                · 库存 <?= $e((int) $v['on_hand'] - (int) $v['reserved']) ?></p>
            <label>数量 <input type="number" name="qty" value="1" min="1" max="99"></label>
            <button type="submit">加入购物车</button>
        </form>
    <?php endforeach; ?>
    <h2>举报该商品</h2>
    <form method="post" action="/p/<?= $e($product['slug']) ?>/report">
        <?= $csrf ?>
        <label>原因
            <input type="text" name="reason" required>
        </label>
        <label>说明
            <input type="text" name="detail">
        </label>
        <button type="submit">提交举报</button>
    </form>
</article>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
