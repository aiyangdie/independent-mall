<?php
/** @var array<string, mixed> $data */
$title = htmlspecialchars((string) ($data['title'] ?? '独立商城'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<a class="skip" href="#main">跳到主要内容</a>
<header class="bar">
    <a class="brand" href="/">独立商城</a>
    <nav aria-label="主导航">
        <a href="/editions">版本对比</a>
        <a href="/pricing">价格</a>
        <a href="/docs">文档</a>
        <a href="/changelog">更新日志</a>
        <a href="/contact">联系我们</a>
        <?php if (!empty($data['signedIn'])): ?>
            <a href="/account">客户中心</a>
        <?php else: ?>
            <a href="/login">登录</a>
            <a href="/register">注册</a>
        <?php endif; ?>
    </nav>
</header>
<main id="main" class="wrap">
<?php include $viewFile; ?>
</main>
<footer class="bar foot">
    <a href="/legal/terms">服务条款</a>
    <a href="/legal/privacy">隐私政策</a>
    <a href="/legal/aup">可接受使用政策</a>
    <a href="/legal/refunds">退款政策</a>
    <a href="/legal/prohibited">禁售商品</a>
    <a href="/legal/disclaimer">免责声明</a>
    <p>法律文本仍是草案，正式营业前须由律师审核。</p>
</footer>
</body>
</html>
