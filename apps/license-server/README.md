Independent license server (separate process and SQLite file). It never ships inside a Community install’s `src/` tree as a private-key holder.

This OpenSSL build exposes Ed25519 as `OPENSSL_KEYTYPE_ED25519`. If that constant is missing, the server refuses to generate keys.

```bat
cd apps\license-server
composer install --no-dev
php bin/license keys
php bin/license issue --id=lic_demo --days=365
php bin/license offline --id=lic_demo --token=TOKEN --nonce=NONCE --instance=shop-1 --out=license.json
php bin/license revoke --id=lic_demo --token=TOKEN --out=revoked.json
php bin/license purge --id=lic_demo --token=TOKEN --confirm
php bin/license status --id=lic_demo
php -S 127.0.0.1:8091 -t public public/index.php
```

If Composer was already run at the Community tree root, `bootstrap.php` reuses that `vendor/autoload.php`. A dedicated `composer.json` here is for deploying the issuer alone.

Copy `var/public.pem` to the shop as `var/license-public.pem`. Copy the signed JSON to `var/license.json`. Keep `var/private.pem` only on this host.

`POST /activate` requires `{license_id, token, instance_id, nonce}`. The nonce is single-use and is rotated on unbind (`activation_nonce` in the JSON). `POST /refresh` uses the long-lived token after bind; if the license is revoked it returns a signed document with `"revoked": true` so the shop can store it and turn off commercial modules only. `POST /revoke` with `{license_id, token}` marks the row revoked and returns the same kind of signed document. `POST /purge` deletes the server row and its audit (after token check). CLI `status --id=` prints a JSON summary without token or nonce material. Field list, retention, and what is never collected: [docs/license-server-data.md](../../docs/license-server-data.md). Responses are JSON only. They do not return PHP and do not instruct the shop to execute remote code.

Shop operators refresh from `/admin/license/refresh` or `php bin/console license:refresh --id= --token= --instance=`. Storefront page views never call this host. After expiry, the shop honors `grace_days` (default 14) using `last_refresh_at` already stored in the local document; it does not need the server to be reachable during the grace window.
