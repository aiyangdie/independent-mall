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
    <p><a href="/admin/products">Manage products</a> · <a href="/admin/orders">Orders and refunds</a></p>
    <form method="post" action="/admin/logout">
        <?= $csrf ?>
        <button type="submit">Sign out</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
