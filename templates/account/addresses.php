<?php
ob_start();
?>
<section>
    <h1>Addresses</h1>
    <?php if (!empty($error)): ?><p class="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/account/addresses" class="card">
        <?= $csrf ?>
        <label>Label <input name="label" value="Home"></label>
        <label>Recipient <input name="recipient" required></label>
        <label>Phone <input name="phone"></label>
        <label>Region <input name="region"></label>
        <label>Line 1 <input name="line1" required></label>
        <label>Line 2 <input name="line2"></label>
        <label>Postal code <input name="postal_code"></label>
        <label><input type="checkbox" name="is_default" value="1"> Default</label>
        <button type="submit">Save address</button>
    </form>
    <ul>
        <?php foreach ($addresses as $a): ?>
            <li><?= $e($a['recipient']) ?>, <?= $e($a['line1']) ?>
                <form method="post" action="/account/addresses/<?= $e($a['id']) ?>/delete"><?= $csrf ?><button>Delete</button></form>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
