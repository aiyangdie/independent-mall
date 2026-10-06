<?php
ob_start();
?>
<section class="narrow">
    <h1>Create account</h1>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="/register">
        <?= $csrf ?>
        <label>Email <input type="email" name="email" required autocomplete="email"></label>
        <label>Username <input name="username" required autocomplete="username" pattern="[A-Za-z0-9_]{3,32}"></label>
        <label>Password <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>
            <input type="checkbox" name="accept_terms" value="1" required>
            I accept the draft shopper terms and have read the
            <a href="/legal/disclaimer">disclaimer</a>,
            <a href="/legal/refunds">refunds</a>, and
            <a href="/legal/prohibited">prohibited goods</a> notices (counsel has not reviewed these drafts).
        </label>
        <button type="submit">Register</button>
    </form>
    <p><a href="/login">Already have an account</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
