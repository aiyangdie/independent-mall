<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'Independent Mall') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="bar">
    <a class="brand" href="/">Independent Mall</a>
    <nav>
        <a href="/">Catalog</a>
        <a href="/cart">Cart</a>
        <a href="/account">Account</a>
        <a href="/register">Register</a>
        <a href="/login">Sign in</a>
        <a href="/admin/products">Products</a>
        <a href="/admin/orders">Orders</a>
        <a href="/admin/login">Admin</a>
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
