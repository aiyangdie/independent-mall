<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $shop_name = $shop_name ?? 'Independent Mall'; ?>
    <title><?= $e($title ?? $shop_name) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="bar">
    <a class="brand" href="/"><?= $e($shop_name) ?></a>
    <nav aria-label="Primary">
        <a href="/">Catalog</a>
        <form class="search" action="/search" method="get" role="search">
            <label class="visually-hidden" for="q">Search listings</label>
            <input id="q" name="q" type="search" value="<?= $e($q ?? '') ?>">
            <button type="submit">Search</button>
        </form>
        <a href="/cart">Cart</a>
        <a href="/account">Account</a>
        <a href="/register">Register</a>
        <a href="/login">Sign in</a>
    </nav>
    <nav aria-label="Operations">
        <a href="/admin">Admin</a>
        <a href="/admin/products">Products</a>
        <a href="/admin/orders">Orders</a>
    </nav>
</header>
<main id="main">
<?= $content ?? '' ?>
</main>
<footer class="bar muted">
    <p>Community Edition. No license server required. Working name only; copyright holder TBD.</p>
</footer>
</body>
</html>
