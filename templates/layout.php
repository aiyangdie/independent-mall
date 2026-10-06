<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $shop_name = $shop_name ?? '独立商城'; ?>
    <title><?= $e($title ?? $shop_name) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#main">跳到主要内容</a>
<header class="bar">
    <a class="brand" href="/"><?= $e($shop_name) ?></a>
    <nav aria-label="主导航">
        <a href="/">商品目录</a>
        <form class="search" action="/search" method="get" role="search">
            <label class="visually-hidden" for="q">搜索商品</label>
            <input id="q" name="q" type="search" value="<?= $e($q ?? '') ?>" placeholder="搜索…">
            <button type="submit">搜索</button>
        </form>
        <a href="/cart">购物车</a>
        <a href="/account">我的账户</a>
        <a href="/register">注册</a>
        <a href="/login">登录</a>
    </nav>
    <nav aria-label="运营入口">
        <a href="/admin">管理后台</a>
        <a href="/admin/products">商品管理</a>
        <a href="/admin/orders">订单管理</a>
    </nav>
</header>
<main id="main">
<?= $content ?? '' ?>
</main>
<footer class="bar muted">
    <nav aria-label="法律信息">
        <a href="/legal/refunds">退款说明</a>
        <a href="/legal/prohibited">禁售商品</a>
        <a href="/legal/disclaimer">免责声明</a>
    </nav>
    <p>社区版。无需授权服务器即可运行。工作名称，版权主体待定。法律页面为草案，未经律师审核。</p>
</footer>
</body>
</html>
