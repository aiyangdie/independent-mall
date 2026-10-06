# Architecture

## Runtime chosen on this machine

| Item | Choice | Why |
|------|--------|-----|
| Language | PHP 8.2+ (verified locally with PHP 8.5.10 at `C:\tools\php85\php.exe`) | Default CLI is 8.5; 7.4 remains only for unrelated legacy work. 8.2+ is still in the supported PHP line and gives typed properties, readonly classes, and `password_hash` Argon2id. |
| Database | PDO. Default SQLite for local/dev/tests; MySQL 8 via `pdo_mysql` optional | Local MySQL root is password-protected; SQLite lets install and CI run without secrets. Production should use MySQL/MariaDB. |
| Web | PHP built-in server for development; `public/` document root | No extra web server required to smoke-test. |
| Templates | Server-rendered PHP views, no CDN | Keeps first paint working offline; CSS/JS are first-party. |
| Crypto | `password_hash` Argon2id; HMAC sessions; OpenSSL for later license signatures | `ext-sodium` is not enabled on this PHP 8.5 build. License signing (Commercial) will use OpenSSL Ed25519 when present, otherwise fail closed rather than invent a weak scheme. |
| Tests | PHPUnit 11 | Composer-installed, version-pinned. |
| Style | Modular monolith in one process | No microservices in v1. |

Composer is used only for autoload and PHPUnit. No UI kit, no remote CSS CDN.

## Process layout

```text
public/          HTTP front controller
src/             Community Core application code
modules/         Optional packages (commercial plugins later)
apps/            Separate processes (license server later)
database/        SQL migrations
templates/       Views
docs/            Design and legal drafts
```

HTTP enters `public/index.php` → `Kernel` loads config, PDO, session, CSRF, router. Domain modules (`Auth`, `Catalog`, `Checkout`, `Payment`, `Fulfillment`, `Mail`, `Modules`) expose small interfaces. Optional add-ons are loaded only from `modules/*/module.php`. Storefront HTTP for accounts/cart/orders lives in `Http/ShopFront`. Adapters: `NullMailer` / `ArrayMailer`, `FakePaymentGateway`. Real payment, SMTP, and OAuth stay off until the deployer supplies credentials.

See `docs/order-state-machine.md` and `docs/api.md`.

## Money and inventory

Money is stored as integer **minor units** (fen for CNY). PHP `float` is forbidden for prices. Inventory movements are append-only; on-hand quantity is projected from movements inside a transaction.

## External services

Every network dependency sits behind an interface (`Mailer`, `PaymentGateway`, `ObjectStorage`, `OAuthProvider`). Community defaults: `NullMailer`, `FakePaymentGateway`, `LocalStorage`. Missing credentials means the adapter stays off.

## Community vs commercial

Community Core never calls a license server on storefront requests. Commercial modules may verify a locally stored signed license (`var/license.json` + public key). If the license server is down, Community features keep working; already-issued documents honor a 14-day grace window after expiry unless revoked or tampered.
