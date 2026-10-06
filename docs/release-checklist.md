# Release checklist (Community Edition)

Mark each item only after it was actually done.

Local tree `codex/community-core`, PHP 8.5.10, 2026-10-06:

- [x] `vendor/bin/phpunit` pass on PHP 8.2+ (this machine: 8.5.10). Full empty-host `composer install` not re-run this round.
- [x] Empty-directory install via `Installer` succeeds once and refuses a second run (`tests/InstallerTest.php`). Live `php bin/console install` on an already-installed clone is expected to refuse.
- [x] `migrate` is a no-op on a freshly installed schema and applies when a new `database/migrations/*.sql` file is added (same test).
- [ ] HTTP smoke: register → cart → fake pay → refund (`tests/http_commerce_smoke.php`) against a local server — run when `:8090` is this Community shop.
- [x] `/healthz` unit path shows `"license_required": false` and `"edition": "community"`. Physical “network unplugged” not re-tested this round.
- [x] `.env` is gitignored. Installer writes a real `APP_KEY` and empty `ADMIN_PASSWORD`.
- [x] Sample listings come from `DemoSeeder` (lawful/fictional).
- [x] `php bin/console release:scan` (and `ReleaseScan` tests) — no private keys, legacy brand, `eval(`, or remote `file_get_contents` in scanned trees.
- [x] `LICENSE.md` remains UNLICENSED.
- [x] Backup/restore: `php bin/console backup` / `restore --from=` implemented; covered by `testSnapshotBackupAndRestore`.

Still not a production sign-off: no clean spare machine, no counsel, no real payment channel.
