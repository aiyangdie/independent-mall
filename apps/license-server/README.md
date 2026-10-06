Independent license server (separate process and SQLite file). It never ships inside a Community install’s `src/` tree as a private-key holder.

This OpenSSL build exposes Ed25519 as `OPENSSL_KEYTYPE_ED25519`. If that constant is missing, the server refuses to generate keys.

```bat
php bin/license keys
php bin/license issue --id=lic_demo --days=365
php bin/license offline --id=lic_demo --token=TOKEN --instance=shop-1 --out=license.json
php -S 127.0.0.1:8091 -t public public/index.php
```

Copy `var/public.pem` to the shop as `var/license-public.pem`. Copy the signed JSON to `var/license.json`. Keep `var/private.pem` only on this host.

`POST /activate`, `POST /unbind`, and `POST /refresh` return JSON only. They do not return PHP and do not instruct the shop to `eval` anything.

Shop operators refresh from `/admin/license/refresh` or `php bin/console license:refresh --id= --token= --instance=`. Storefront page views never call this host. After expiry, the shop honors `grace_days` (default 14) using `last_refresh_at` already stored in the local document; it does not need the server to be reachable during the grace window.
