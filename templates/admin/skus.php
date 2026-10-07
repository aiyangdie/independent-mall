<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>商品规格与库存</h1>
    <div class="table-wrap">
    <table>
        <thead><tr><th>规格编码</th><th>商品</th><th>售价</th><th>现有库存</th><th>已预留</th><th>状态</th><th>调整库存</th></tr></thead>
        <tbody>
        <?php foreach ($skus as $s): ?>
            <tr>
                <td><?= $e($s['sku']) ?></td>
                <td><a href="/p/<?= $e($s['slug']) ?>"><?= $e($s['product_name']) ?></a></td>
                <td><?= $e(Money::format((int) $s['price_minor'], (string) $s['currency'])) ?></td>
                <td><?= $e($s['on_hand']) ?></td>
                <td><?= $e($s['reserved']) ?></td>
                <td><?= $e(\IndependentMall\Support\Chinese::label($s['status'])) ?> / <?= $e(\IndependentMall\Support\Chinese::label($s['product_status'])) ?></td>
                <td>
                    <form method="post" action="/admin/skus/<?= $e($s['id']) ?>/stock">
                        <?= $csrf ?>
                        <label>增减数量 <input name="delta" type="number" value="1"></label>
                        <label>业务流水号 <input name="reference" placeholder="用于防止重复调整" required></label>
                        <button type="submit">确认调整</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
