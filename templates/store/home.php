<?php
ob_start();
?>
<section class="hero">
    <h1>Goods and services for lawful trade</h1>
    <p>Physical products, licensed digital goods, memberships, and professional services. Listings that promote fraud, infringement, cheats, or unofficial virtual items are blocked by policy.</p>
</section>
<section class="grid">
    <article class="card">
        <h2>Browse</h2>
        <p>Catalog, search, and product pages ship in the next stage. This install already serves health checks and administration.</p>
    </article>
    <article class="card">
        <h2>Pay locally</h2>
        <p>Payments go through adapters. The default adapter is a non-settling fake gateway for development.</p>
    </article>
    <article class="card">
        <h2>Community first</h2>
        <p>This edition starts without contacting any official license service. Paid modules, if added later, live in separate packages.</p>
    </article>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
