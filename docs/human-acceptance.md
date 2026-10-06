# Human acceptance checklists

AI and PHPUnit cannot sign these off. Do **not** mark them done in `docs/roadmap.md` until a human completes the steps in a real environment. None of these require production keys or counsel approval to *run*; they require eyes, hardware, or legal review to *accept*.

## Stage 4 — storefront visual QA (real device)

Environment: Community shop on `http://127.0.0.1:8090/` (or your deploy URL), phone + desktop browsers.

- [ ] Narrow phone viewport (~360px): home, product, cart, checkout, account — no horizontal scroll on primary flows.
- [ ] Keyboard only: tab through register, login, cart add, checkout; visible focus rings; forms submittable without a mouse.
- [ ] `prefers-reduced-motion: reduce` — no distracting motion on hero/nav (CSS anchors exist; confirm visually).
- [ ] Contrast: primary text/buttons readable on light background in both Chrome and Safari/Firefox.
- [ ] Footer legal draft links open and show counsel-not-reviewed notices.

Record date, browser/OS, and who checked. Leave unchecked until done.

## Stage 5 — ops / offline hardware

- [ ] Fresh install on a **physical spare machine** (or clean VM that is not the dev laptop), then `GET /healthz` shows `license_required=false`, `network_required=false`.
- [ ] With Community running, **unplug NIC / disable Wi‑Fi** and confirm: login, browse `/p/ceramic-mug`, place Fake order still works (no license server calls required).
- [ ] Backup then restore via `php bin/console backup` / `restore --from=` on that host; shop serves catalog after restore.

## Stage 7/8 — keys and counsel (blocked on humans)

- [ ] Production Ed25519 keypair generated offline; private key never enters git or Community hosts.
- [ ] Counsel reviews `docs/legal/*` drafts before public marketing claims.
- [ ] Real payment adapter chosen and merchant credentials stored only in ignored `.env` (Fake remains default until then).

## Cross-app demo (already automated)

Automated coverage lives in `tests/CrossAppLicenseE2ETest.php` (OfficialSite Fake buy → license JSON/PEM download → Community `/admin/license` import → migrate/enable `sample-notice`, plus tamper/wrong-key/expired/revoked/no-entitlement and commerce-still-works). Optional human walkthrough:

1. Buy Fake SKU on `apps/www` account center.
2. Download `license.json` + `license-public.pem`.
3. Paste into Community `/admin/modules` offline form.
4. Migrate and enable `sample-notice`; confirm storefront notice; disable or remove `modules/commercial` and confirm checkout still works.
