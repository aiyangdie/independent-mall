<?php
/** @var array<string, mixed> $data */
/** @var \IndependentMall\Http\Csrf $csrf */
$csrf = $data['csrf'];
$error = htmlspecialchars(\IndependentMall\Support\Chinese::message($data['error'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>注册客户账户</h1>
<?php if ($error !== ''): ?><p class="err"><?= $error ?></p><?php endif; ?>
<form method="post" action="/register">
    <?= $csrf->field() ?>
    <label>邮箱 <input type="email" name="email" required autocomplete="email"></label>
    <label>密码（至少 10 个字符）<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <label><input type="checkbox" name="accept_terms" value="1" required> 我已阅读并同意<a href="/legal/terms">服务条款</a>、<a href="/legal/privacy">隐私政策</a>和<a href="/legal/disclaimer">免责声明</a></label>
    <button type="submit">创建账户</button>
</form>
