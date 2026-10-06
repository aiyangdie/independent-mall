# Operations

Community Edition runs without a license server. Keep `LICENSE_SERVER_URL` empty.

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

This edition has no required queue worker. Fake mail and fake payments run in the request. Cron is optional.

## Signed file updates (demo)

The shop never downloads or executes an update during a storefront request.

```bat
php bin/console update:apply --install=PATH --artifact=FILE --manifest=manifest.json --public=update-public.pem --current=0.1.0
```

`SafeApplier` verifies Ed25519 and SHA-256, copies the install directory, writes `NOTES.txt`, and restores the copy if the write callback fails. Unsigned manifests and silent major-version jumps are refused. PHP payloads are refused. This is a local demonstration, not a production CDN or auto-updater.

