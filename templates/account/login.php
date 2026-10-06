<?php
ob_start();
?>
<section class="narrow">
    <h1>登录</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/login">
        <?= $csrf ?>
        <label>邮箱或用户名 <input name="identity" required autocomplete="username"></label>
        <label>密码 <input type="password" name="password" required autocomplete="current-password"></label>
        <button type="submit">登录</button>
    </form>
    <p><a href="/register">创建账户</a> · <a href="/forgot">忘记密码</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
