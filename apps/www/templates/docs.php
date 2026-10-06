<?php /** @var array<string, mixed> $data */ ?>
<h1>Documentation</h1>
<p>Shop docs live in the Community repository. They are not loaded from a CDN on this site.</p>

<h2>Deploy and run Community</h2>
<ul>
    <li><code>docs/deploy.md</code> — TLS proxy, sessions, what is not production-signed</li>
    <li><code>docs/ops.md</code> — install, backup, restore, offline core</li>
    <li><code>docs/release-checklist.md</code> — local verification marks</li>
</ul>

<h2>Architecture and editions</h2>
<ul>
    <li><code>docs/architecture.md</code> — modular monolith</li>
    <li><code>docs/licensing-design.md</code> — dual edition and entitlements</li>
    <li><code>docs/community-commercial-boundary.md</code> — what Community must not phone home for</li>
</ul>

<h2>Commercial license issuer (separate process)</h2>
<ul>
    <li><code>apps/license-server/README.md</code> — keys, product/version catalog, issue, activate, renew, revoke, purge</li>
    <li><code>docs/license-server-data.md</code> — fields stored, retention, what is never collected</li>
</ul>

<p class="callout callout-fake" role="status">
    Update clients must verify SHA-256 and Ed25519 before replacing files. Unsigned channels must not auto-install.
    This marketing site only inspects update metadata; it never applies packages into a shop install.
</p>
