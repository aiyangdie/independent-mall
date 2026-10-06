<?php
$settings = $settings ?? ['shop_name' => '独立商城', 'support_email' => ''];
ob_start();
?>
<section>
    <p><a href="/admin">返回后台首页</a></p>
    <h1>店铺设置</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/settings" class="card narrow">
        <?= $csrf ?>
        <label>店铺名称 <input name="shop_name" value="<?= $e($settings['shop_name'] ?? '') ?>" required maxlength="80"></label>
        <label>客服邮箱 <input name="support_email" type="email" value="<?= $e($settings['support_email'] ?? '') ?>" placeholder="可选"></label>
        <button type="submit">保存设置</button>
    </form>
    <p class="muted">社区版不会连接授权服务器，这些信息仅保存在本机数据库中。</p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
