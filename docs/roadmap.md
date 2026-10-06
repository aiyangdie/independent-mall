# Roadmap

Status is only marked done after tests or HTTP checks in that environment.

| Stage | Content | Status |
|-------|---------|--------|
| 0 | Design docs | Done (this tree) |
| 1 | Skeleton, config, migrations, admin login, health, tests | Done locally: PHPUnit 5/5, HTTP on :8090 |
| 2 | Catalog, SKU, inventory, review, uploads | Done locally: PHPUnit 9/9 including prohibited-goods and upload tests |
| 3 | Cart, address, orders, fake pay, delivery, refunds | Done locally: PHPUnit 18/18; HTTP register→cart→checkout→fake pay→refund on :8090 |
| 4 | Storefront + admin screens, responsive pass | In progress locally: skip/focus/reduced-motion CSS anchors + contrast tokens in LayoutCssTest. Real-device visual QA not signed off |
| 5 | Installer, deploy/upgrade docs, release checklist | In progress locally: deploy.md + ops; doctor/install lock, backup/restore, release:scan, HTTP smoke, healthz network_required=false. Spare-machine install and NIC unplug not signed off |
| 6 | Commercial module seam + sample plugin | In progress locally: Kernel FileLicenseGate + `/admin/license`; PHPUnit still OK with `modules/commercial` removed (one skip). `src/` must not import commercial classes |
| 7 | License server | In progress locally: revoke + purge; admin audit filter `license.`; license-server-data.md |
| 8 | Public marketing/account site | In progress locally: pricing Fake-only + counsel callouts; account license status; inspect-updates; legal drafts. No production auto-updater or counsel sign-off |

Local URLs used in development: `http://127.0.0.1:8090/` (does not bind to the legacy shop on 8080).
