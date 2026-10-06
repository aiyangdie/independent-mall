<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <h1>我的账户</h1>
    <p><?= $e($user['email'] ?? '') ?></p>
    <p><a href="/account/addresses">收货地址</a> · <a href="/orders">订单</a> · <a href="/cart">购物车</a></p>
    <form method="post" action="/account/password" class="narrow">
        <?= $csrf ?>
        <h2>修改密码</h2>
        <label>当前密码 <input type="password" name="current" required></label>
        <label>新密码 <input type="password" name="next" required minlength="10"></label>
        <button type="submit">更新</button>
    </form>
    <form method="post" action="/logout"><?= $csrf ?><button>退出登录</button></form>
    <h2>最近订单</h2>
    <ul>
        <?php foreach ($orders as $o): ?>
            <li><a href="/orders/<?= $e($o['id']) ?>"><?= $e($o['public_id']) ?></a> · <?= $e($o['status']) ?> · <?= $e(Money::format((int) $o['payable_minor'], (string) $o['currency'])) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
