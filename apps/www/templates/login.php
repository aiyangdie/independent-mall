<?php
/** @var array<string, mixed> $data */
/** @var \IndependentMall\Http\Csrf $csrf */
$csrf = $data['csrf'];
$error = htmlspecialchars(\IndependentMall\Support\Chinese::message($data['error'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>登录客户中心</h1>
<?php if ($error !== ''): ?><p class="err"><?= $error ?></p><?php endif; ?>
<form method="post" action="/login">
    <?= $csrf->field() ?>
    <label>邮箱 <input type="email" name="email" required autocomplete="email"></label>
    <label>密码 <input type="password" name="password" required autocomplete="current-password"></label>
    <button type="submit">登录</button>
</form>
