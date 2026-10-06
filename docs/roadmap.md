# Roadmap

Status is only marked done after tests or HTTP checks in that environment.

| Stage | Content | Status |
|-------|---------|--------|
| 0 | Design docs | Done (this tree) |
| 1 | Skeleton, config, migrations, admin login, health, tests | Done locally: PHPUnit 5/5, HTTP on :8090 |
| 2 | Catalog, SKU, inventory, review, uploads | Done locally: PHPUnit 9/9 including prohibited-goods and upload tests |
| 3 | Cart, address, orders, fake pay, delivery, refunds | Done locally: PHPUnit 18/18; HTTP register→cart→checkout→fake pay→refund on :8090 |
| 4 | Storefront + admin screens, responsive pass | In progress: payments/refunds/order detail and payment-result pages; remaining visual QA on a real mobile device |
| 5 | Installer, deploy/upgrade docs, release checklist | In progress locally: CLI/web installer refuses overwrite; ops/release/troubleshooting docs. Full release checklist not signed off |
| 6 | Commercial module seam + sample plugin | In progress locally: host + sample-notice; default deny without local entitlement; community tests must not import commercial classes |
| 7 | License server | In progress locally: independent signer + Ed25519 local verify, 14-day grace, unbind, issued-at skew; no private key in Community Core |
| 8 | Public marketing/account site | In progress locally: Fake buy → license → HMAC download audit; signed_release + SafeApplier backup/rollback. No production auto-updater |

Local URLs used in development: `http://127.0.0.1:8090/` (does not bind to the legacy shop on 8080).
