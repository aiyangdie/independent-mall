# Roadmap

Status is only marked done after tests or HTTP checks in that environment.

| Stage | Content | Status |
|-------|---------|--------|
| 0 | Design docs | Done (this tree) |
| 1 | Skeleton, config, migrations, admin login, health, tests | Done locally: PHPUnit 5/5, HTTP on :8090 |
| 2 | Catalog, SKU, inventory, review, uploads | Done locally: PHPUnit 9/9 including prohibited-goods and upload tests |
| 3 | Cart, address, orders, fake pay, delivery, refunds | Done locally: PHPUnit 18/18; HTTP register→cart→checkout→fake pay→refund on :8090 |
| 4 | Storefront + admin screens, responsive pass | In progress locally: register terms checkbox + footer legal drafts; skip/focus CSS. Real-device visual QA not signed off |
| 5 | Installer, deploy/upgrade docs, release checklist | In progress locally: empty-tree install smoke + health/catalog; deploy.md; backup/restore; release:scan. Physical spare machine and NIC unplug not signed off |
| 6 | Commercial module seam + sample plugin | In progress locally: `/admin/modules` shows product/customer from local license; FileLicenseGate; commercial dir removable (one skip) |
| 7 | License server | In progress locally: Fake renew orders (`fake_renewed`); `fulfill` / `licenses --customer=`; B2B customers; product catalog; revoke + purge |
| 8 | Public marketing/account site | In progress locally: pricing/docs Fake demo path; issuer orders on account; Fake buy/renew; tickets; single-use downloads. No production auto-updater or counsel sign-off |

Local URLs used in development: `http://127.0.0.1:8090/` (does not bind to the legacy shop on 8080).
