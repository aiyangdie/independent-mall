<?php
/** @var array<string, mixed> $data */
$title = htmlspecialchars((string) ($data['title'] ?? 'Independent Mall'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="bar">
    <a class="brand" href="/">Independent Mall</a>
    <nav>
        <a href="/editions">Editions</a>
        <a href="/pricing">Pricing</a>
        <a href="/docs">Docs</a>
        <a href="/changelog">Changelog</a>
        <a href="/contact">Contact</a>
        <?php if (!empty($data['signedIn'])): ?>
            <a href="/account">Account</a>
        <?php else: ?>
            <a href="/login">Sign in</a>
            <a href="/register">Register</a>
        <?php endif; ?>
    </nav>
</header>
<main id="main" class="wrap">
<?php include $viewFile; ?>
</main>
<footer class="bar foot">
    <a href="/legal/terms">Terms</a>
    <a href="/legal/privacy">Privacy</a>
    <a href="/legal/aup">Acceptable use</a>
    <a href="/legal/refunds">Refunds</a>
    <a href="/legal/prohibited">Prohibited goods</a>
    <a href="/legal/disclaimer">Disclaimer</a>
    <p>Draft legal text. Counsel has not reviewed these pages.</p>
</footer>
</body>
</html>
