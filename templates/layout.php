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
<header class="site-header">
  <div class="header-main">
    <a class="brand" href="/"><span class="brand-mark">独</span><?= $e($shop_name) ?></a>
    <form class="search" action="/search" method="get" role="search">
            <label class="visually-hidden" for="q">搜索商品</label>
            <input id="q" name="q" type="search" value="<?= $e($q ?? '') ?>" placeholder="搜索商品、数字内容或服务">
            <button type="submit">搜索</button>
    </form>
    <nav class="account-nav" aria-label="账户导航">
        <a href="/cart">购物车</a><a href="/account">我的账户</a><a href="/login">登录</a><a class="register-link" href="/register">免费注册</a>
    </nav>
  </div>
  <div class="header-sub">
    <nav aria-label="主导航">
        <a class="active" href="/">商城首页</a><a href="/#categories">商品分类</a><a href="/legal/prohibited">平台规则</a>
    </nav>
    <a class="operator-link" href="/admin">商家管理后台 →</a>
  </div>
</header>
<main id="main">
<?= $content ?? '' ?>
</main>
<footer class="site-footer muted">
    <div><strong><?= $e($shop_name) ?></strong><p>面向正规商品、数字内容和专业服务的独立商城。</p></div>
    <nav aria-label="法律信息">
        <a href="/legal/refunds">退款说明</a>
        <a href="/legal/prohibited">禁售商品</a>
        <a href="/legal/disclaimer">免责声明</a>
    </nav>
    <p>社区版无需授权服务器。法律页面为待审核草案，正式经营前请咨询专业律师。</p>
</footer>
</body>
</html>
