# Community / commercial boundary

## Rule

Community Edition is a working shop. Commercial Edition is extra packages under `modules/` plus optional `apps/license-server`. A config flag inside Community Core must not unlock Commercial features.

## Community may contain

- Install, migrate, health checks
- Users, roles, sessions
- Catalog, cart, orders, fake payment
- Digital delivery, refunds requests, coupons
- Prohibited-goods rules and listing review
- Local file storage
- Adapter interfaces and fake/null adapters
- Audit log

## Community must not contain

- Calls that refuse to boot unless a license server answers
- Hidden remote disable of checkout
- Official plugin marketplace client
- Auto-update that executes downloaded PHP
- Multi-store / SaaS control plane

## Commercial packages (later)

Each package declares entitlements it needs (`multi_store`, `advanced_stats`, …). The commercial host checks a locally verified license document. Failure: the package is inactive; Community routes stay up; admin sees a repair message.

## License server

`apps/license-server` is a different Composer project and a different database. Community installs must not include the server private key.
