<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <h1>结算</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <ul>
        <?php foreach ($lines as $line): ?>
            <li><?= $e($line['product_name']) ?> × <?= $e($line['qty']) ?> · <?= $e(Money::format((int) $line['line_minor'])) ?>
                <?php if ($line['issue'] !== ''): ?><strong><?= $e($line['issue']) ?></strong><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/checkout">
        <?= $csrf ?>
        <label>幂等键 <input name="idempotency_key" required value="<?= $e(bin2hex(random_bytes(8))) ?>"></label>
        <label>收货地址
            <select name="address_id">
                <option value="">无（仅数字商品或服务）</option>
                <?php foreach ($addresses as $a): ?>
                    <option value="<?= $e($a['id']) ?>"><?= $e($a['recipient']) ?> — <?= $e($a['line1']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">提交订单</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
