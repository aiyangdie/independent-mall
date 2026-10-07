<?php
use IndependentMall\Catalog\Money;
use IndependentMall\Support\Chinese;
ob_start();
?>
<section>
    <h1>结算</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <ul class="order-list">
        <?php foreach ($lines as $line): ?>
            <li><span><?= $e($line['product_name']) ?></span><span>数量 <?= $e($line['qty']) ?></span><strong><?= $e(Money::format((int) $line['line_minor'], (string) ($line['currency'] ?? 'CNY'))) ?></strong>
                <?php if ($line['issue'] !== ''): ?><span class="alert"><?= $e(Chinese::label($line['issue'])) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/checkout" class="card">
        <?= $csrf ?>
        <input type="hidden" name="idempotency_key" value="<?= $e(bin2hex(random_bytes(16))) ?>">
        <label>收货地址
            <select name="address_id">
                <option value="">无（仅数字商品或服务）</option>
                <?php foreach ($addresses as $a): ?>
                    <option value="<?= $e($a['id']) ?>"><?= $e($a['recipient']) ?> — <?= $e($a['line1']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p><a href="/account/addresses">管理收货地址</a></p>
        <button type="submit">提交订单</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
