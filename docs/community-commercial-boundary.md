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

## Commercial packages

Each package lives under `modules/<name>/` with a `module.php` entry that returns an `IndependentMall\Modules\Module`. Community Core discovers those files; it does not Composer-autoload commercial classes.

Entitlements (for example `storefront_notice`) are checked with a **local** license document. `Kernel` uses `FileLicenseGate::fromRoot()`: missing files become `AbsentLicense` (deny paid entitlements). A signed `var/license.json` plus public PEM, pasted at `/admin/license`, is enough to enable `sample-notice`. Tests may also use an allow-list. There is no license private key in Community Core or in the sample plugin. Storefront requests do not contact a license server.

Failure mode: the package stays inactive; Community routes stay up; `/admin/modules` shows a repair message. Deleting `modules/commercial` must not break `vendor/bin/phpunit`.

## License server

`apps/license-server` is a different Composer project and a different database. Community installs must not include the server private key.
