<?php
ob_start();
?>
<section>
    <h1>Page not found</h1>
    <p>That listing or page is not available.</p>
    <p><a href="/">Return to the catalog</a></p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
