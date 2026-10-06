<?php /** @var array<string, mixed> $data */ ?>
<h1>Pricing</h1>
<p class="callout callout-fake" role="status">
    <strong>Fake payments only.</strong> No card network, wallet, or bank transfer is connected on this site.
    The customer center records a Fake purchase so operators can rehearse license issue, bind, unbind, revoke, download, and update inspection.
</p>
<p class="callout callout-legal" role="note">
    <strong>Not reviewed by counsel.</strong> Listed amounts are placeholders. Final prices, taxes, refunds, and contracts need a copyright holder and a lawyer before any real sale.
</p>
<ul>
    <li>Community Edition: no license fee in this repository.</li>
    <li>Sample notice module (<code>sample-notice@1.0.0</code>): 99.00 display / 9900 minor units via the Fake adapter only; issued through the license-server product catalog.</li>
    <li>Renewal demo: 49.00 display / 4900 minor units Fake; writes an issuer order with status <code>fake_renewed</code>.</li>
</ul>
<h2>Demo path (Fake only)</h2>
<ol>
    <li>Register on this site and open the customer center.</li>
    <li>Buy sample notice (Fake) → issuer customer <code>www_u{id}</code>, commercial order <code>fake_completed</code>, signed license.</li>
    <li>Bind an instance, then Renew (Fake) → issuer order <code>fake_renewed</code> and a later <code>expires_at</code>.</li>
    <li>Download signed JSON + <code>license-public.pem</code> from the customer center, then paste into Community <code>/admin/modules</code> (or use admin refresh).</li>
</ol>
<p><a class="btn" href="/register">Open a demo account</a> <a class="btn ghost" href="/docs">Issuer docs index</a> <a class="btn ghost" href="/legal/disclaimer">Read the disclaimer draft</a></p>
