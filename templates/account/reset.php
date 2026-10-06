<?php
ob_start();
?>
<section class="narrow">
    <h1>设置新密码</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/reset">
        <?= $csrf ?>
        <input type="hidden" name="token" value="<?= $e($token ?? '') ?>">
        <label>新密码 <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <button type="submit">更新密码</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
