<?php
/** @var array<string, mixed> $data */
$kind = (string) ($data['kind'] ?? 'terms');
$copy = [
    'terms' => 'Commercial terms, refunds, and license grants are drafts. A lawyer for the copyright holder must approve them before any paid sale.',
    'privacy' => 'This demo site stores emails and password hashes locally. There is no third-party analytics pixel in these templates.',
    'aup' => 'Prohibited: traffic boosting, unofficial virtual currency, account trading, cheats, and other illegal goods. Operators must keep their own deny-lists.',
][$kind] ?? '';
?>
<h1><?= htmlspecialchars((string) $data['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
<p><?= htmlspecialchars($copy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<p>Not legal advice. Not reviewed by counsel.</p>
