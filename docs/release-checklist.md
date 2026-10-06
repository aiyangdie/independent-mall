# Release checklist (Community Edition)

Mark each item only after it was actually done.

- [ ] `composer install` and `vendor/bin/phpunit` pass on PHP 8.2+.
- [ ] Empty-directory install via `php bin/console install` or `/install` succeeds once and refuses a second run.
- [ ] `php bin/console migrate` is a no-op on a freshly installed schema and applies when a new `database/migrations/*.sql` file is added.
- [ ] HTTP smoke: register → cart → fake pay → refund (`tests/http_commerce_smoke.php`) against a local server.
- [ ] `/healthz` shows `"license_required": false` and `"edition": "community"` with the network unplugged.
- [ ] `.env` is not in git. `APP_KEY` is not a placeholder. Admin password is not stored after install.
- [ ] Sample listings are lawful and fictional (no real customers or payment data).
- [ ] No private keys, live SMS/pay credentials, or remote PHP loaders.
- [ ] `LICENSE.md` remains UNLICENSED until the copyright holder chooses a public license.
- [ ] Backup/restore steps in `docs/ops.md` were followed on a disposable copy.
