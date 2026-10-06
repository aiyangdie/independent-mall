Official marketing and customer-center app. It is not the shop. Community shops do not call this host on boot.

```bat
php -S 127.0.0.1:8092 -t public public/index.php
```

Demo keys for Fake purchases live in `var/` (gitignored). They are not the production license-server keys.

Customer-center download links are HMAC + server-side `jti` rows: single-use by default, expire in ten minutes, and can be revoked from `/account` without logging the full token. Owners can download their signed license JSON and the demo public PEM (not the long-lived token) for offline paste into Community `/admin/modules`. Fake purchase ensures a B2B customer (`www_u{id}`), records an issuer `commercial_orders` row via `fulfillFakeOrder`, and issues `sample-notice@1.0.0`. Fake renew calls `fulfillFakeRenew` (`fake_renewed`) and extends the paid window. Neither path talks to a card network.

Unsigned `/updates.json` `latest` must never trigger automatic file replacement. Use `signed_release` plus `php bin/console update:apply` only after verifying hashes locally.
