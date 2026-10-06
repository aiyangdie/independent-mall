# Operations

Community Edition runs without a license server. Keep `LICENSE_SERVER_URL` empty. Production proxy steps: [deploy.md](deploy.md).

## Offline core

Catalog, cart, Fake pay, and `/healthz` use only the local database and local files. `curl_init` exists solely in `src/License/CurlTransport.php`, which is constructed from `POST /admin/license/refresh` and `php bin/console license:refresh`.

How this was checked on the development host (loopback, not a NIC unplug):

1. `GET /healthz` returns `"license_required": false` and `"network_required": false`, plus a `license` object (`mode` `absent`|`offline_file`, `valid`, optional `product_code` / `entitlements` / `revoked`). Never tokens, nonces, or private keys.
2. `tests/OfflineCoreTest.php` asserts no other `src/` file calls `curl_init`.
3. PHPUnit boots the kernel with `sqlite:///:memory:` and still serves `/` and `/healthz`.
4. Cross-app offline paste is covered by `tests/CrossAppLicenseE2ETest.php`.

A physical cable pull was not performed. Equivalence: those paths never open an outbound socket. Human NIC/spare-machine checklist: [human-acceptance.md](human-acceptance.md).

## Offline license paste (commercial modules)

1. On OfficialSite (`apps/www`), Fake-buy `sample-notice`, download `license.json` and `license-public.pem` from the account center.
2. On Community `/admin/modules`, paste both into the offline license form (POST `/admin/license`).
3. Migrate then enable `sample-notice`. Failure (bad signature, expired, revoked, missing entitlement) leaves core shop unchanged.
4. Automated: `tests/CrossAppLicenseE2ETest.php`.

## Install

Empty host:

```bat
composer install --no-dev
php bin/console doctor
php bin/console install --email=ops@example.invalid --password=LONG_PASSWORD --seed
php -S 127.0.0.1:8090 -t public public/index.php
```

Or open `/install` in a browser when `.env` is absent. A second install is refused.

Existing trees that already have a real `APP_KEY` in `.env` are treated as installed.

## HTTPS and sessions

Terminate TLS at the reverse proxy. Set `SESSION_SECURE=1` and `APP_URL` to the `https://` origin. Do not expose `APP_DEBUG=1` on the public network.

## Schema upgrade

```bat
php bin/console migrate
```

Migrations are incremental. Take a file copy of `var/data/*.sqlite` (or a MySQL dump) before running them. To roll back, restore that backup; there are no automatic down-migrations.

## Backup and restore

1. Stop writers (or enable SQLite backup with the database file closed).
2. `php bin/console backup` copies `.env`, `var/data/`, `var/uploads/`, and `var/installed.json` into `var/backups/snap-*` (gitignored).
3. Restore on the same or a new host: `php bin/console restore --from=var/backups/snap-...` then `php bin/console migrate` and `php bin/console health`.
4. Restore paths must stay under `var/backups/` (path traversal is refused).

Manual copy of those same four locations is still valid if you do not use the CLI.

## Scheduled work

This edition has no required queue worker. Fake mail and fake payments run in the request.

Optional commercial license refresh (not used by Community storefront):

```bat
php bin/console license:refresh --id=lic_demo --token=TOKEN --instance=shop-1
```

`--url` defaults to `LICENSE_SERVER_URL`. Leave that empty on Community installs. `--id` and `--instance` must match the local `var/license.json` when that file already verifies. If the call fails, `var/license.json` is left unchanged so the 14-day grace still applies.

## Signed file updates (demo)

The shop never downloads or executes an update during a storefront request.

```bat
php bin/console update:apply --install=PATH --artifact=FILE --manifest=manifest.json --public=update-public.pem --current=0.1.0
```

`SafeApplier` verifies Ed25519 and SHA-256, copies the install directory, writes `NOTES.txt`, and restores the copy if the write callback fails. Unsigned manifests and silent major-version jumps are refused. PHP payloads are refused. This is a local demonstration, not a production CDN or auto-updater.

