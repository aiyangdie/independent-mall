<?php
$checks = $checks ?? [];
ob_start();
?>
<section>
    <h1>Install Community Edition</h1>
    <p>No license server is required. This wizard writes a local <code>.env</code> once and will not overwrite an existing install.</p>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <h2>Environment</h2>
    <ul>
        <?php foreach ($checks as $row): ?>
            <li><?= !empty($row['ok']) ? 'OK' : 'FAIL' ?> — <?= $e($row['label']) ?> (<?= $e($row['detail']) ?>)</li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/install" class="card">
        <?= $csrf ?>
        <label>Shop name <input name="shop_name" value="Independent Mall" required maxlength="80"></label>
        <label>Public URL <input name="app_url" value="http://127.0.0.1:8090" required></label>
        <label>Database URL <input name="database_url" value="sqlite:///%ROOT%/var/data/app.sqlite" required></label>
        <label>Admin email <input name="admin_email" type="email" required></label>
        <label>Admin username <input name="admin_username" value="admin" required pattern="[a-zA-Z0-9_]{3,32}"></label>
        <label>Admin password <input name="admin_password" type="password" required minlength="10" autocomplete="new-password"></label>
        <label><input type="checkbox" name="seed" value="1"> Seed lawful sample listings (mug and worksheet)</label>
        <button type="submit">Install</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
