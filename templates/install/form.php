<?php
$checks = $checks ?? [];
ob_start();
?>
<section>
    <h1>安装社区版</h1>
    <p>无需授权服务器。本向导只会创建一次本地 <code>.env</code>，不会覆盖已有安装。</p>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <h2>环境检查</h2>
    <ul>
        <?php foreach ($checks as $row): ?>
            <li><?= !empty($row['ok']) ? '通过' : '失败' ?> — <?= $e($row['label']) ?>（<?= $e($row['detail']) ?>）</li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/install" class="card">
        <?= $csrf ?>
        <label>店铺名称 <input name="shop_name" value="独立商城" required maxlength="80"></label>
        <label>公网地址 <input name="app_url" value="http://127.0.0.1:8090" required></label>
        <label>数据库 URL <input name="database_url" value="sqlite:///%ROOT%/var/data/app.sqlite" required></label>
        <label>管理员邮箱 <input name="admin_email" type="email" required></label>
        <label>管理员用户名 <input name="admin_username" value="admin" required pattern="[a-zA-Z0-9_]{3,32}"></label>
        <label>管理员密码 <input name="admin_password" type="password" required minlength="10" autocomplete="new-password"></label>
        <label><input type="checkbox" name="seed" value="1"> 写入正规演示商品（陶瓷杯与练习讲义）</label>
        <button type="submit">开始安装</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
