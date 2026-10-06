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
    <li><code><?= $e((string) $row['license_id']) ?></code></li>
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

<h2>Downloads (10-minute HMAC links)</h2>
<ul>
<?php foreach ($data['files'] as $id => $token): ?>
    <li><a href="/account/download?token=<?= $e((string) $token) ?>"><?= $e((string) $id) ?></a></li>
<?php endforeach; ?>
</ul>

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
