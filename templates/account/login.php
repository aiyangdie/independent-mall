<?php
ob_start();
?>
<section class="narrow">
    <h1>Sign in</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/login">
        <?= $csrf ?>
        <label>Email or username <input name="identity" required autocomplete="username"></label>
        <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
        <button type="submit">Sign in</button>
    </form>
    <p><a href="/register">Create account</a> · <a href="/forgot">Forgot password</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
