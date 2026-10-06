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
<p>Records a completed Fake order and issues a signed license. This is not a real charge.</p>
<form method="post" action="/account/buy"><?= $csrf->field() ?><button type="submit">Buy sample notice (Fake)</button></form>

<h2>Orders</h2>
<ul>
<?php foreach ($data['orders'] as $row): ?>
    <li>#<?= (int) $row['id'] ?> <?= $e((string) $row['sku']) ?> <?= $e((string) $row['status']) ?> via <?= $e((string) $row['adapter']) ?></li>
<?php endforeach; ?>
</ul>

<h2>Licenses</h2>
<ul>
<?php foreach ($data['licenses'] as $row): ?>
    <li><code><?= $e((string) $row['license_id']) ?></code>
        status <strong><?= $e((string) ($row['status'] ?? 'unknown')) ?></strong>
        <?php if (!empty($row['revoked'])): ?>
            (commercial modules off; Community checkout unaffected)
        <?php elseif (!empty($row['bound'])): ?>
            · bound to <code><?= $e((string) $row['instance_id']) ?></code>
        <?php else: ?>
            · not bound to an instance
        <?php endif; ?></li>
<?php endforeach; ?>
</ul>
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
<form method="post" action="/account/ticket">
    <?= $csrf->field() ?>
    <label>Title <input name="title" required></label>
    <label>Body <textarea name="body" required></textarea></label>
    <button type="submit">Open ticket</button>
</form>
<ul>
<?php foreach ($data['tickets'] as $row): ?>
    <li><?= $e((string) $row['title']) ?></li>
<?php endforeach; ?>
</ul>

<h2>Security log</h2>
<ul>
<?php foreach ($data['security'] as $row): ?>
    <li><?= $e((string) $row['created_at']) ?> <?= $e((string) $row['action']) ?></li>
<?php endforeach; ?>
</ul>
