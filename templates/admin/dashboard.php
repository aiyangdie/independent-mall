<?php
ob_start();
?>
<section>
    <h1>Operations</h1>
    <p>Signed in as <?= $e($user['email'] ?? '') ?>.</p>
    <dl class="stats">
        <div><dt>Edition</dt><dd><?= $e($health['edition'] ?? '') ?></dd></div>
        <div><dt>License gate</dt><dd><?= !empty($health['license_required']) ? 'yes' : 'no' ?></dd></div>
        <div><dt>Database</dt><dd><?= $e($health['database'] ?? '') ?></dd></div>
        <div><dt>PHP</dt><dd><?= $e($health['php'] ?? '') ?></dd></div>
    </dl>
    <p><a href="/admin/products">Products</a> · <a href="/admin/skus">SKUs</a> · <a href="/admin/categories">Categories</a> · <a href="/admin/orders">Orders</a> · <a href="/admin/payments">Payments</a> · <a href="/admin/refunds">Refunds</a> · <a href="/admin/customers">Customers</a> · <a href="/admin/rules">Prohibited rules</a> · <a href="/admin/settings">Settings</a> · <a href="/admin/audit">Audit</a></p>
    <form method="post" action="/admin/logout">
        <?= $csrf ?>
        <button type="submit">Sign out</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
