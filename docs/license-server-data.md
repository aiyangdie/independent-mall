# License server data (minimization)

This document lists what the Independent Mall license issuer stores. It is not a privacy policy for shoppers in a merchant’s Community shop. Counsel must still approve any public privacy notice.

## Fields stored

| Field | Purpose | Form | Retention |
|-------|---------|------|-----------|
| `id` | License identifier | Opaque string chosen at issue | Until purge |
| `token_hash` | Authenticate refresh / unbind / revoke / renew / purge | SHA-256 of the one-time-shown token; plaintext never stored | Until purge |
| `activation_nonce_hash` | One-time bind | SHA-256 of nonce; cleared after activate; rotated on unbind | Until purge |
| `entitlements` | Which commercial features the license grants | JSON string list (e.g. `storefront_notice`) | Until purge |
| `product_code` | Catalog product used at issue (optional) | Lowercase slug | Until purge |
| `product_version` | Catalog version used at issue (optional) | Semver-like string | Until purge |
| `expires_at` | End of paid window | UTC ISO-8601 | Until purge |
| `grace_days` | Offline grace after expiry | Integer (default 14) | Until purge |
| `instance_id` | Bound shop instance | Operator-chosen id (hostname hash + install UUID recommended); not a consumer fingerprint | Cleared on unbind; row kept until purge |
| `revoked` | Operator kill-switch for commercial modules | Boolean | Until purge |
| `created_at` | Issue time | UTC ISO-8601 | Until purge |
| `products` / `product_versions` | Operator catalog of sellable commercial SKUs | Code, name, entitlements, version notes | Kept until operator deletes DB; not removed by license purge |
| `license_audit` | Issue / activate / unbind / refresh / renew / revoke actions | `license_id`, action name, small JSON meta, timestamp | Deleted with purge |

## Not collected

- No shopper emails, names, or payment cards on the license server.
- No device advertising ids, GPS, contact books, or browser fingerprints.
- No Community storefront page views.
- No PHP payloads for shops to execute.
- Private signing keys live only under `apps/license-server/var/` (gitignored), never in Community `src/`.

## Deletion

1. Prefer revoke first so the next admin refresh can store `"revoked": true` and turn commercial modules off without deleting merchant order data.
2. Then purge when the commercial relationship must be erased:

```bat
php bin/license purge --id=lic_demo --token=TOKEN --confirm
```

Or `POST /purge` with `{license_id, token}`. This deletes the license row and its audit rows. It does not reach into Community shop databases or `var/license.json` on the merchant host; operators delete or replace that local file themselves.

## Shop-side copy

Community shops may keep a signed `var/license.json`. That file is verified locally and is not a license-server database. Deleting the server row does not auto-delete merchant copies; a revoked signed document or manual file removal is required on the shop.
