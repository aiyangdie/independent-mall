<?php
ob_start();
?>
<section class="narrow">
    <h1>创建账户</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/register">
        <?= $csrf ?>
        <label>邮箱 <input type="email" name="email" required autocomplete="email"></label>
        <label>用户名 <input name="username" required autocomplete="username" pattern="[A-Za-z0-9_]{3,32}"></label>
        <label>密码 <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>
            <input type="checkbox" name="accept_terms" value="1" required>
            我接受店铺购物条款草案，并已阅读
            <a href="/legal/disclaimer">免责声明</a>、
            <a href="/legal/refunds">退款说明</a> 与
            <a href="/legal/prohibited">禁售商品</a>
            （上述草案未经律师审核）。
        </label>
        <button type="submit">注册</button>
    </form>
    <p><a href="/login">已有账户？去登录</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
