<?php
$settings = $settings ?? ['shop_name' => 'Independent Mall', 'support_email' => ''];
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Shop settings</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/settings" class="card narrow">
        <?= $csrf ?>
        <label>Shop name <input name="shop_name" value="<?= $e($settings['shop_name'] ?? '') ?>" required maxlength="80"></label>
        <label>Support email <input name="support_email" type="email" value="<?= $e($settings['support_email'] ?? '') ?>"></label>
        <button type="submit">Save</button>
    </form>
    <p class="muted">Community Edition does not contact a license server. These values stay in local settings.</p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
