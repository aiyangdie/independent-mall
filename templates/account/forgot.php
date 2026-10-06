<?php
ob_start();
?>
<section class="narrow">
    <h1>重置密码</h1>
    <?php if (!empty($notice)): ?><p><?= $e($notice) ?></p><?php endif; ?>
    <form method="post" action="/forgot">
        <?= $csrf ?>
        <label>邮箱 <input type="email" name="email" required></label>
        <button type="submit">发送重置链接</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
