<?php
/** @var array<string, mixed> $data */
/** @var \IndependentMall\Http\Csrf $csrf */
$csrf = $data['csrf'];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$flash = $e((string) ($data['flash'] ?? ''));
?>
<h1>Customer center</h1>
<?php if ($flash !== ''): ?><p class="ok"><?= $flash ?></p><?php endif; ?>
<form method="post" action="/logout"><?= $csrf->field() ?><button type="submit">Sign out</button></form>
<p><a href="/account/updates">Check updates (verify only, do not apply)</a></p>

<h2>Fake purchase</h2>
<p>Records a completed Fake order and issues a signed license from the catalog product <code>sample-notice@1.0.0</code>. This is not a real charge.</p>
<form method="post" action="/account/buy"><?= $csrf->field() ?><button type="submit">Buy sample notice (Fake)</button></form>

<h2>Site Fake orders</h2>
<ul>
<?php foreach ($data['orders'] as $row): ?>
    <li>#<?= (int) $row['id'] ?> <?= $e((string) $row['sku']) ?> <?= $e((string) $row['status']) ?> via <?= $e((string) $row['adapter']) ?></li>
<?php endforeach; ?>
</ul>

<h2>Issuer commercial orders</h2>
<p>Fake bookkeeping on the license issuer (currency <code>XXX</code>). Not a card charge.
<?php if (($data['issuer_customer_id'] ?? '') !== ''): ?>
    Customer <code><?= $e((string) $data['issuer_customer_id']) ?></code>.
<?php endif; ?>
</p>
<?php if (empty($data['issuer_orders'])): ?>
<p>No issuer orders yet. Use Fake purchase above.</p>
<?php else: ?>
<ul>
<?php foreach ($data['issuer_orders'] as $row): ?>
    <li>
        <code><?= $e((string) $row['id']) ?></code>
        · <?= $e((string) $row['product_code']) ?>@<?= $e((string) $row['product_version']) ?>
        · license <code><?= $e((string) $row['license_id']) ?></code>
        · <?= (int) $row['amount_minor'] ?> <?= $e((string) $row['currency']) ?>
        · <?= $e((string) $row['status']) ?> via <?= $e((string) $row['adapter']) ?>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<h2>Licenses</h2>
<ul>
<?php foreach ($data['licenses'] as $row): ?>
    <li><code><?= $e((string) $row['license_id']) ?></code>
        status <strong><?= $e((string) ($row['status'] ?? 'unknown')) ?></strong>
        <?php if (($row['product_code'] ?? '') !== ''): ?>
            · product <code><?= $e((string) $row['product_code']) ?>@<?= $e((string) ($row['product_version'] ?? '')) ?></code>
        <?php endif; ?>
        <?php if (($row['customer_id'] ?? '') !== ''): ?>
            · customer <code><?= $e((string) $row['customer_id']) ?></code>
        <?php endif; ?>
        <?php if (!empty($row['revoked'])): ?>
            (commercial modules off; Community checkout unaffected)
        <?php elseif (!empty($row['bound'])): ?>
            · bound to <code><?= $e((string) $row['instance_id']) ?></code>
        <?php else: ?>
            · not bound to an instance
        <?php endif; ?>
        <?php if (($row['expires_at'] ?? '') !== ''): ?>
            · expires <code><?= $e((string) $row['expires_at']) ?></code>
        <?php endif; ?>
        · <a href="/account/license.json?id=<?= $e((string) $row['license_id']) ?>">Download signed JSON</a>
        </li>
<?php endforeach; ?>
</ul>
<p>Demo vendor public key for Community trust-key install / fingerprint check:
    <a href="/account/license-public.pem">license-public.pem</a>
    (install once under <code>/admin/license/trust-key</code>; do not paste it with every license JSON. Does not include the long-lived license token).</p>
<form method="post" action="/account/activate">
    <?= $csrf->field() ?>
    <label>License id <input name="license_id" required></label>
    <label>Token <input name="token" required></label>
    <label>Activation nonce <input name="nonce" required></label>
    <label>Instance id <input name="instance_id" required></label>
    <button type="submit">Bind instance</button>
</form>
<form method="post" action="/account/unbind">
    <?= $csrf->field() ?>
    <label>License id <input name="license_id" required></label>
    <label>Token <input name="token" required></label>
    <button type="submit">Unbind</button>
</form>
<form method="post" action="/account/renew">
    <?= $csrf->field() ?>
    <p>Fake renewal only — records site + issuer Fake orders (<code>fake_renewed</code>) and extends the signed license. Not a real charge.</p>
    <label>License id <input name="license_id" required></label>
    <label>Token <input name="token" required></label>
    <label>Days <input name="days" type="number" min="1" max="3660" value="365" required></label>
    <button type="submit">Renew (Fake)</button>
</form>
<form method="post" action="/account/revoke">
    <?= $csrf->field() ?>
    <label>License id <input name="license_id" required></label>
    <label>Token <input name="token" required></label>
    <button type="submit">Mark revoked (demo)</button>
</form>

<h2>Downloads (single-use, 10-minute links)</h2>
<p>Each link works once until you open the account page again for a fresh token. Revoke unused links below. Full tokens are not written to the security log.</p>
<ul>
<?php foreach ($data['files'] as $id => $token): ?>
    <li><a href="/account/download?token=<?= $e((string) $token) ?>"><?= $e((string) $id) ?></a></li>
<?php endforeach; ?>
</ul>
<?php if (!empty($data['active_tokens'])): ?>
<ul>
<?php foreach ($data['active_tokens'] as $tok): ?>
    <li><code><?= $e((string) $tok['file_id']) ?></code>
        uses <?= (int) $tok['use_count'] ?>/<?= (int) $tok['max_uses'] ?>
        · expires <?= $e(gmdate('c', (int) $tok['expires_at'])) ?>
        <form method="post" action="/account/download/revoke" style="display:inline">
            <?= $csrf->field() ?>
            <input type="hidden" name="jti" value="<?= $e((string) $tok['jti']) ?>">
            <button type="submit">Revoke</button>
        </form>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<h2>Download audit</h2>
<ul>
<?php foreach ($data['downloads'] as $row): ?>
    <li><?= $e((string) $row['created_at']) ?> <?= $e((string) $row['file_id']) ?> <?= ((int) $row['ok'] === 1) ? 'allowed' : 'denied' ?></li>
<?php endforeach; ?>
</ul>

<h2>Tickets</h2>
<p>Statuses: <code>open</code> → <code>answered</code> (demo staff reply) → <code>closed</code>. No production mailbox.</p>
<form method="post" action="/account/ticket">
    <?= $csrf->field() ?>
    <label>Title <input name="title" required></label>
    <label>Body <textarea name="body" required></textarea></label>
    <button type="submit">Open ticket</button>
</form>
<ul>
<?php foreach ($data['tickets'] as $row): ?>
    <li>
        #<?= (int) $row['id'] ?>
        <strong><?= $e((string) $row['title']) ?></strong>
        status <code><?= $e((string) ($row['status'] ?? 'open')) ?></code>
        <?php if ((string) ($row['staff_reply'] ?? '') !== ''): ?>
            · reply: <?= $e((string) $row['staff_reply']) ?>
        <?php endif; ?>
        <?php if (($row['status'] ?? 'open') !== 'closed'): ?>
            <form method="post" action="/account/ticket/answer" style="margin-top:.5rem">
                <?= $csrf->field() ?>
                <input type="hidden" name="ticket_id" value="<?= (int) $row['id'] ?>">
                <label>Demo staff reply <input name="reply" required></label>
                <button type="submit">Answer (Fake staff)</button>
            </form>
            <form method="post" action="/account/ticket/close" style="display:inline">
                <?= $csrf->field() ?>
                <input type="hidden" name="ticket_id" value="<?= (int) $row['id'] ?>">
                <button type="submit">Close</button>
            </form>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>

<h2>Security log</h2>
<ul>
<?php foreach ($data['security'] as $row): ?>
    <li><?= $e((string) $row['created_at']) ?> <?= $e((string) $row['action']) ?></li>
<?php endforeach; ?>
</ul>
