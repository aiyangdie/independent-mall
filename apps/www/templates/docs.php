<?php /** @var array<string, mixed> $data */ ?>
<h1>Documentation</h1>
<p>The shop documentation lives in the Community repository, not on a CDN.</p>
<ul>
    <li><code>docs/architecture.md</code> — modular monolith</li>
    <li><code>docs/ops.md</code> — install, backup, restore</li>
    <li><code>docs/licensing-design.md</code> — dual edition and entitlements</li>
    <li><code>apps/license-server/README.md</code> — signer process</li>
</ul>
<p>Update clients must verify SHA-256 (and a signature when present) before replacing files. Unsigned channels must not auto-install.</p>
