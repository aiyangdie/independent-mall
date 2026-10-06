# Release checklist (Community Edition)

Mark each item only after it was actually done.

Local tree `codex/community-core`, PHP 8.5.10, 2026-10-06:

- [x] `vendor/bin/phpunit` pass on PHP 8.2+ (this machine: 8.5.10). Full empty-host `composer install` not re-run this round.
- [x] Empty-directory install via `Installer` succeeds once and refuses a second run (`tests/InstallerTest.php`). Live `php bin/console install` on an already-installed clone is expected to refuse.
- [x] `migrate` is a no-op on a freshly installed schema and applies when a new `database/migrations/*.sql` file is added (same test).
- [x] HTTP smoke: register → cart → fake pay → refund (`tests/http_commerce_smoke.php`) against `http://127.0.0.1:8090/` (this Community shop, PHP 8.5.10). Storefront smoke also passed. Latest local demo order id 6.
- [x] `/healthz` shows `"license_required": false`, `"network_required": false`, and `"edition": "community"` on loopback (`http://127.0.0.1:8090/healthz`). Storefront has no `curl_init` except admin refresh (`tests/OfflineCoreTest.php`). Physical NIC unplug was not performed.
- [x] Renaming `modules/commercial` aside still yields PHPUnit OK (one skip: Kernel signed-license sample-notice). Directory restored afterward.
- [x] `.env` is gitignored. Installer writes a real `APP_KEY` and empty `ADMIN_PASSWORD`.
- [x] Sample listings come from `DemoSeeder` (lawful/fictional).
- [x] `php bin/console release:scan` (and `ReleaseScan` tests) — no private keys, legacy brand, `eval(`, or remote `file_get_contents` in scanned trees.
- [x] `LICENSE.md` remains UNLICENSED.
- [x] Backup/restore: `php bin/console backup` / `restore --from=` implemented; covered by `testSnapshotBackupAndRestore`.
- [x] Deploy notes in `docs/deploy.md` (proxy, sessions, what is not signed off).
- [x] License issuer data inventory and purge path in `docs/license-server-data.md` (counsel still required for public privacy text).

Still not a production sign-off: no clean spare machine, no counsel, no real payment channel.
