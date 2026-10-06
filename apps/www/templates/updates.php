<?php
/** @var array<string, mixed> $data */
$r = $data['report'];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>Check updates</h1>
<p>This page verifies metadata only. It does not replace files, run PHP, or fetch a remote installer.</p>
<ul>
    <li>Unsigned channel: <?= $e((string) $r['unsigned']) ?></li>
    <li>Signed channel: <?= $e((string) $r['signed']) ?></li>
    <li>Version: <?= $e((string) $r['version']) ?></li>
    <li>SHA-256: <code><?= $e((string) $r['sha256']) ?></code></li>
    <li>Applied: <?= !empty($r['applied']) ? 'yes' : 'no' ?></li>
</ul>
<p>To apply on a machine you control, copy the artifact and run <code>php bin/console update:apply</code> after verifying the hash yourself.</p>
<p><a href="/account">Back to account</a></p>
