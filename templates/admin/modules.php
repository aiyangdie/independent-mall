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
    <?php $local = is_array($local_license ?? null) ? $local_license : []; ?>
    <div class="card">
        <h2>Local license file</h2>
        <?php if (!empty($local['present'])): ?>
            <p>Verified locally. License <code><?= $e((string) $local['license_id']) ?></code>
                bound to <code><?= $e((string) $local['instance_id']) ?></code>.
                Expires <?= $e((string) $local['expires_at']) ?>.
                Entitlements: <?= $e(implode(', ', $local['entitlements'] ?? [])) ?></p>
        <?php else: ?>
            <p class="muted">No verified local license document. Community checkout still works.</p>
        <?php endif; ?>
    </div>
    <form method="post" action="/admin/license" class="card">
        <?= $csrf ?>
        <h2>Offline license document</h2>
        <p class="muted">Paste a signed JSON document and the matching public key. The shop never stores a private key and does not run code from the license server.</p>
        <label>Public key PEM <textarea name="public_pem" rows="6"></textarea></label>
        <label>Signed license JSON <textarea name="license_json" rows="8" required></textarea></label>
        <button type="submit">Verify and store locally</button>
    </form>
    <form method="post" action="/admin/license/refresh" class="card">
        <?= $csrf ?>
        <h2>Scheduled refresh</h2>
        <p class="muted">Calls LICENSE_SERVER_URL only from this admin action or <code>php bin/console license:refresh</code>. Storefront requests never do. If the server is down, the local document stays in place for the grace window.</p>
        <label>License id <input name="license_id" required value="<?= $e((string) ($local['license_id'] ?? '')) ?>"></label>
        <label>Token <input name="token" required autocomplete="off"></label>
        <label>Instance id <input name="instance_id" required value="<?= $e((string) ($local['instance_id'] ?? '')) ?>"></label>
        <button type="submit">Refresh from server</button>
    </form>
    <?php if (!empty($license_server)): ?>
        <p class="muted">Configured LICENSE_SERVER_URL is not contacted from the storefront. Refresh belongs on an operator schedule.</p>
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
