# Troubleshooting

## Installer says the instance is already installed

`.env` already has a non-placeholder `APP_KEY`, or `var/installed.json` exists. The installer will not overwrite them. Use `php bin/console migrate` for schema updates.

## 500 on first request

Confirm `var/data`, `var/sessions`, and `var/uploads` are writable. Run `php bin/console doctor`.

## Cannot sign in as admin

The installer does not keep `ADMIN_PASSWORD` in `.env`. Use the password you typed during install, or create another admin after a database restore.

## Health is degraded

`/healthz` `"database": "error"` means PDO cannot open `DATABASE_URL`. Check the SQLite path or MySQL credentials. `"network_required": false` means the Community storefront is not supposed to call a license server. If checkout fails only when WAN is down, look at DNS or a reverse proxy — not at Independent Mall core.

## Fake payment only

`PAYMENT_ADAPTER=fake` never charges a card network. A succeeded fake pay is not evidence of a live merchant account.

## CSS or images missing

Static files are served from `public/assets` on the same origin. There is no CDN requirement.
