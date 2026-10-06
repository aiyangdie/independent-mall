<?php
/** @var array<string, mixed> $data */
/** @var \IndependentMall\Http\Csrf $csrf */
$csrf = $data['csrf'];
$error = htmlspecialchars((string) ($data['error'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>Register</h1>
<?php if ($error !== ''): ?><p class="err"><?= $error ?></p><?php endif; ?>
<form method="post" action="/register">
    <?= $csrf->field() ?>
    <label>Email <input type="email" name="email" required></label>
    <label>Password (10+ characters) <input type="password" name="password" required minlength="10"></label>
    <button type="submit">Create account</button>
</form>
