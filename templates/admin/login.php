<?php
ob_start();
?>
<section class="narrow">
    <h1>Administrator sign in</h1>
    <?php if (!empty($error)): ?>
        <p class="alert" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login">
        <?= $csrf ?>
        <label>Email or username
            <input type="text" name="identity" autocomplete="username" required>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Sign in</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
