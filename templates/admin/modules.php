<?php
ob_start();
?>
<section>
    <p><a href="/admin">Dashboard</a></p>
    <h1>Modules</h1>
    <p class="muted">Commercial packages are optional. Community checkout does not need them or a license server.</p>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <?php if (!empty($faults)): ?>
        <ul class="alert" role="status">
            <?php foreach ($faults as $f): ?>
                <li><?= $e($f) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($modules === []): ?>
        <p>No optional modules were discovered under <code>modules/*/module.php</code>.</p>
    <?php endif; ?>
    <?php foreach ($modules as $m): ?>
        <article class="card">
            <h2><?= $e($m['label']) ?> <span class="muted">(<?= $e($m['id']) ?>)</span></h2>
            <p>Migrated: <?= !empty($m['migrated']) ? 'yes' : 'no' ?> · Enabled: <?= !empty($m['enabled']) ? 'yes' : 'no' ?> · Local entitlement: <?= !empty($m['licensed']) ? 'yes' : 'no' ?></p>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/migrate"><?= $csrf ?><button type="submit">Migrate</button></form>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/enable"><?= $csrf ?><button type="submit">Enable</button></form>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/disable"><?= $csrf ?><button type="submit">Disable</button></form>
            <form method="post" action="/admin/modules/<?= $e($m['id']) ?>/uninstall"><?= $csrf ?><button type="submit">Uninstall</button></form>
        </article>
    <?php endforeach; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
