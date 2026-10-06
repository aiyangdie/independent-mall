<?php
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>SKUs and inventory</h1>
    <div class="table-wrap">
    <table>
        <thead><tr><th>SKU</th><th>Product</th><th>Price</th><th>On hand</th><th>Reserved</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($skus as $s): ?>
            <tr>
                <td><?= $e($s['sku']) ?></td>
                <td><a href="/p/<?= $e($s['slug']) ?>"><?= $e($s['product_name']) ?></a></td>
                <td><?= $e($s['price_minor']) ?> <?= $e($s['currency']) ?></td>
                <td><?= $e($s['on_hand']) ?></td>
                <td><?= $e($s['reserved']) ?></td>
                <td><?= $e($s['status']) ?> / <?= $e($s['product_status']) ?></td>
                <td>
                    <form method="post" action="/admin/skus/<?= $e($s['id']) ?>/stock">
                        <?= $csrf ?>
                        <label>Delta <input name="delta" type="number" value="1"></label>
                        <label>Reference <input name="reference" placeholder="idempotency key"></label>
                        <button type="submit">Adjust</button>
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
