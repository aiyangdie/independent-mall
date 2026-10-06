<?php
use IndependentMall\Catalog\Money;
ob_start();
?>
<section>
    <h1>Account</h1>
    <p><?= $e($user['email'] ?? '') ?></p>
    <p><a href="/account/addresses">Addresses</a> · <a href="/orders">Orders</a> · <a href="/cart">Cart</a></p>
    <form method="post" action="/account/password" class="narrow">
        <?= $csrf ?>
        <h2>Change password</h2>
        <label>Current <input type="password" name="current" required></label>
        <label>New <input type="password" name="next" required minlength="10"></label>
        <button type="submit">Update</button>
    </form>
    <form method="post" action="/logout"><?= $csrf ?><button>Sign out</button></form>
    <h2>Recent orders</h2>
    <ul>
        <?php foreach ($orders as $o): ?>
            <li><a href="/orders/<?= $e($o['id']) ?>"><?= $e($o['public_id']) ?></a> · <?= $e($o['status']) ?> · <?= $e(Money::format((int) $o['payable_minor'], (string) $o['currency'])) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
