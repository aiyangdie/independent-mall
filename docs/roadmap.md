# Roadmap

Status is only marked done after tests or HTTP checks in that environment.

| Stage | Content | Status |
|-------|---------|--------|
| 0 | Design docs | Done (this tree) |
| 1 | Skeleton, config, migrations, admin login, health, tests | Done locally: PHPUnit 5/5, HTTP on :8090 |
| 2 | Catalog, SKU, inventory, review, uploads | Done locally: PHPUnit 9/9 including prohibited-goods and upload tests |
| 3 | Cart, address, orders, fake pay, delivery, refunds | Next |
| 4 | Storefront + admin screens, responsive pass | Next |
| 5 | Installer, deploy/upgrade docs, release checklist | Later |
| 6 | Commercial module seam + sample plugin | Later |
| 7 | License server | Later |
| 8 | Public marketing/account site | Later |

Local URLs used in development: `http://127.0.0.1:8090/` (does not bind to the legacy shop on 8080).
