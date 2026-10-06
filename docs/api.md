# HTTP surface (Community Edition)

All state-changing POST bodies require `_csrf`. JSON health check is unauthenticated.

| Method | Path | Who |
|--------|------|-----|
| GET/POST | `/install` | empty host only; 409 after install |
| GET | `/healthz` | public |
| GET | `/` | public catalog |
| GET | `/search?q=` | public search of published listings |
| GET | `/c/{slug}` | public category |
| GET | `/p/{slug}` | public product |
| POST | `/p/{slug}/report` | public |
| GET/POST | `/register` `/login` `/forgot` `/reset` | public |
| POST | `/logout` | session |
| GET | `/account` `/account/addresses` `/orders` `/orders/{id}` `/cart` `/checkout` | customer |
| POST | `/cart/add` `/checkout` `/orders/{id}/pay-fake` `/orders/{id}/cancel` `/orders/{id}/refund` | customer |
| GET | `/orders/{id}/result` | customer payment result |
| GET | `/deliveries/{token}` | bearer of hashed token |
| GET/POST | `/admin/login` `/admin/products` `/admin/skus` `/admin/categories` `/admin/orders` `/admin/payments` `/admin/refunds` `/admin/customers` `/admin/modules` `/admin/rules` `/admin/settings` `/admin/audit` | administrator |
| POST | `/admin/license` | administrator; stores a locally verified signed document |

Fake payment notify is invoked from `/orders/{id}/pay-fake` using server-side amount and HMAC. Shoppers cannot supply a new total.

Digital download tokens are hashed at rest (`HMAC-SHA256` with `APP_KEY`). The plaintext token is shown once after fake payment success and is not written to logs.

Official site (`apps/www`, local `:8092`) is a separate process: `/` `/editions` `/pricing` `/docs` `/changelog` `/contact` `/legal/*` `/updates.json` `/register` `/login` `/account` plus Fake buy, bind, unbind, tickets, HMAC downloads, and download audit. Shop CLI `update:apply` verifies a local signed artifact; it does not fetch the official site.
