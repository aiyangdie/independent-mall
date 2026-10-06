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
    <?php
    $local = is_array($local_license ?? null) ? $local_license : [];
    $trust = is_array($local['trust'] ?? null) ? $local['trust'] : [];
    ?>
    <div class="card">
        <h2>Vendor trust public key</h2>
        <?php if (!empty($trust['configured'])): ?>
            <p>Trust key is configured (<?= $e((string) ($trust['source'] ?? '')) ?>).
                Fingerprint <code><?= $e((string) ($trust['fingerprint'] ?? '')) ?></code>.
                License imports verify against this key only and cannot replace it.</p>
            <?php if (!empty($trust['packaged_locked'])): ?>
                <p class="muted">A packaged <code>config/license-public.pem</code> is present; local trust-key upload is disabled.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">No vendor public key yet. Install the official public key before importing a license document.</p>
        <?php endif; ?>
    </div>
    <?php if (empty($trust['packaged_locked'])): ?>
    <form method="post" action="/admin/license/trust-key" class="card">
        <?= $csrf ?>
        <h2>Install or rotate trust public key</h2>
        <p class="muted">This is the trust anchor. It is separate from license documents. Rotating an existing key requires pasting the current fingerprint below.</p>
        <label>Vendor public key PEM <textarea name="public_pem" rows="6" required></textarea></label>
        <label>Current fingerprint (required to rotate) <input name="confirm_fingerprint" autocomplete="off" value=""></label>
        <button type="submit">Store trust public key</button>
    </form>
    <?php endif; ?>
    <div class="card">
        <h2>Local license file</h2>
        <?php if (!empty($local['present'])): ?>
            <p>Verified locally<?= !empty($local['valid']) ? '' : ' (signature ok but not currently valid for entitlements)' ?>.
                License <code><?= $e((string) $local['license_id']) ?></code>
                bound to <code><?= $e((string) $local['instance_id']) ?></code>.
                Expires <?= $e((string) $local['expires_at']) ?>.
                <?php if (($local['product_code'] ?? '') !== ''): ?>
                    Product <code><?= $e((string) $local['product_code']) ?>@<?= $e((string) ($local['product_version'] ?? '')) ?></code>.
                <?php endif; ?>
                <?php if (($local['customer_id'] ?? '') !== ''): ?>
                    Issuer customer <code><?= $e((string) $local['customer_id']) ?></code>.
                <?php endif; ?>
                Entitlements: <?= $e(implode(', ', $local['entitlements'] ?? [])) ?>.
                Status: <?= !empty($local['revoked']) ? 'revoked (commercial modules stay off; checkout still works)' : (!empty($local['valid']) ? 'active' : 'inactive') ?></p>
        <?php else: ?>
            <p class="muted">No verified local license document. Community checkout still works.</p>
        <?php endif; ?>
    </div>
    <form method="post" action="/admin/license" class="card">
        <?= $csrf ?>
        <h2>Offline license document</h2>
        <p class="muted">Paste only a signed JSON document. Verification uses the configured vendor public key. A failed import leaves any existing license unchanged.</p>
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
