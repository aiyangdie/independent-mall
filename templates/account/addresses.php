<?php
ob_start();
?>
<section>
    <h1>收货地址</h1>
    <?php if (!empty($error)): ?><p class="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/account/addresses" class="card">
        <?= $csrf ?>
        <label>标签 <input name="label" value="家"></label>
        <label>收件人 <input name="recipient" required></label>
        <label>电话 <input name="phone"></label>
        <label>省市区 <input name="region"></label>
        <label>详细地址 <input name="line1" required></label>
        <label>地址补充 <input name="line2"></label>
        <label>邮编 <input name="postal_code"></label>
        <label><input type="checkbox" name="is_default" value="1"> 设为默认</label>
        <button type="submit">保存地址</button>
    </form>
    <ul>
        <?php foreach ($addresses as $a): ?>
            <li><?= $e($a['recipient']) ?>, <?= $e($a['line1']) ?>
                <form method="post" action="/account/addresses/<?= $e($a['id']) ?>/delete"><?= $csrf ?><button>删除</button></form>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
