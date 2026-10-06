<?php
ob_start();
?>
<section class="narrow">
    <h1>Reset password</h1>
    <?php if (!empty($notice)): ?><p><?= $e($notice) ?></p><?php endif; ?>
    <form method="post" action="/forgot">
        <?= $csrf ?>
        <label>Email <input type="email" name="email" required></label>
        <button type="submit">Send reset</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
