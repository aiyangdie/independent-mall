<?php
ob_start();
?>
<section>
    <h1>Already installed</h1>
    <p>This instance has a configuration file or install lock. The installer will not run again or overwrite <code>.env</code>.</p>
    <p>Use <code>php bin/console migrate</code> to apply new schema versions. Sign in at <a href="/admin/login">admin login</a>.</p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
