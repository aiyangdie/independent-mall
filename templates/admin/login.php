<?php
ob_start();
?>
<section class="narrow">
    <h1>管理员登录</h1>
    <?php if (!empty($error)): ?>
        <p class="alert" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login">
        <?= $csrf ?>
        <label>邮箱或用户名
            <input type="text" name="identity" autocomplete="username" required>
        </label>
        <label>密码
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">登录</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
