<?php
/** @var array<string, mixed> $data */
/** @var \IndependentMall\Http\Csrf $csrf */
$csrf = $data['csrf'];
$error = htmlspecialchars((string) ($data['error'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>Sign in</h1>
<?php if ($error !== ''): ?><p class="err"><?= $error ?></p><?php endif; ?>
<form method="post" action="/login">
    <?= $csrf->field() ?>
    <label>Email <input type="email" name="email" required></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Sign in</button>
</form>
