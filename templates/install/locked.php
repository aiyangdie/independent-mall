<?php
ob_start();
?>
<section>
    <h1>系统已安装</h1>
    <p>当前实例已存在配置文件或安装锁。安装程序不会重复运行，也不会覆盖 <code>.env</code>。</p>
    <p>升级数据库请运行 <code>php bin/console migrate</code>。前往<a href="/admin/login">管理后台登录</a>。</p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
