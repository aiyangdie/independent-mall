# Deploy Community Edition

For day-to-day operations see [ops.md](ops.md). This page is the production-oriented path.

## Requirements

- PHP 8.2+ with `pdo`, `openssl`, `mbstring`, and either `pdo_sqlite` or `pdo_mysql`
- Composer
- A reverse proxy that terminates TLS (Caddy, nginx, or IIS)

## Install

```bat
composer install --no-dev
php bin/console doctor
php bin/console install --email=ops@example.invalid --password=LONG_PASSWORD --seed
```

Or open `/install` once when `.env` is absent. A second install is refused.

## Proxy and sessions

- Point the vhost document root at `public/`.
- Set `APP_URL` to the public `https://` origin.
- Set `SESSION_SECURE=1`.
- Keep `APP_DEBUG=0` and `LICENSE_SERVER_URL=` empty on Community hosts.
- Do not expose `var/`, `.env`, `bin/`, `tests/`, or `vendor/` as URL paths.

## After go-live

1. `php bin/console backup` on a schedule (or copy `var/data`, `var/uploads`, `.env`, `var/installed.json`).
2. Apply schema changes with `php bin/console migrate` only after a backup.
3. Run `php bin/console release:scan` before shipping a tree you built yourself.
4. Real payment, SMTP, and OAuth stay off until you replace Fake/Null adapters and record vendors in `docs/legal/third-parties.md`.

## What is not signed off here

- A clean spare-machine install from a release zip
- Physical NIC unplug (Community core is proven offline via code and loopback `/healthz`)
- Counsel review of legal drafts
- A real payment channel
