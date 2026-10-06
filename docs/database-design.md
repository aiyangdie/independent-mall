# Database design

New schema. Not derived from any previous shop dump.

Default engine: SQLite for development. MySQL 8 equivalent types are noted. Amounts are INTEGER minor units. Timestamps are ISO-8601 UTC unless `*_local` is stated.

## Community Core

### schema_migrations
- version TEXT PK
- applied_at TEXT NOT NULL

### users
- id INTEGER PK
- email TEXT NOT NULL UNIQUE
- username TEXT NOT NULL UNIQUE
- password_hash TEXT NOT NULL
- status TEXT NOT NULL CHECK (status IN ('active','locked','pending','deleted'))
- totp_secret TEXT NULL
- created_at TEXT NOT NULL
- updated_at TEXT NOT NULL
- deleted_at TEXT NULL

### user_identities
- id INTEGER PK
- user_id INTEGER NOT NULL REFERENCES users(id)
- provider TEXT NOT NULL
- provider_subject TEXT NOT NULL
- created_at TEXT NOT NULL
- UNIQUE(provider, provider_subject)

### user_addresses
- id INTEGER PK
- user_id INTEGER NOT NULL REFERENCES users(id)
- label TEXT NOT NULL
- recipient TEXT NOT NULL
- phone TEXT NOT NULL
- region TEXT NOT NULL
- line1 TEXT NOT NULL
- line2 TEXT NOT NULL DEFAULT ''
- postal_code TEXT NOT NULL DEFAULT ''
- is_default INTEGER NOT NULL DEFAULT 0
- created_at TEXT NOT NULL

### roles, permissions, role_permissions, user_roles
Standard RBAC. Permission names are stable strings (`catalog.manage`, `order.refund`, …).

### login_attempts
- id INTEGER PK
- identity TEXT NOT NULL
- ip_hash TEXT NOT NULL
- success INTEGER NOT NULL
- created_at TEXT NOT NULL
Index on (identity, created_at)

### categories
- id INTEGER PK
- parent_id INTEGER NULL REFERENCES categories(id)
- slug TEXT NOT NULL UNIQUE
- name TEXT NOT NULL
- sort INTEGER NOT NULL DEFAULT 0
- status TEXT NOT NULL CHECK (status IN ('active','hidden'))

### products
- id INTEGER PK
- category_id INTEGER NOT NULL REFERENCES categories(id)
- type TEXT NOT NULL CHECK (type IN ('physical','digital','service'))
- name TEXT NOT NULL
- slug TEXT NOT NULL UNIQUE
- summary TEXT NOT NULL DEFAULT ''
- description TEXT NOT NULL DEFAULT ''
- status TEXT NOT NULL CHECK (status IN ('draft','in_review','active','rejected','archived'))
- review_note TEXT NOT NULL DEFAULT ''
- created_at TEXT NOT NULL
- updated_at TEXT NOT NULL

### product_variants
- id INTEGER PK
- product_id INTEGER NOT NULL REFERENCES products(id)
- sku TEXT NOT NULL UNIQUE
- title TEXT NOT NULL
- price_minor INTEGER NOT NULL CHECK (price_minor >= 0)
- currency TEXT NOT NULL DEFAULT 'CNY'
- status TEXT NOT NULL CHECK (status IN ('active','disabled'))

### inventories
- variant_id INTEGER PK REFERENCES product_variants(id)
- on_hand INTEGER NOT NULL DEFAULT 0
- reserved INTEGER NOT NULL DEFAULT 0
CHECK (on_hand >= 0 AND reserved >= 0)

### inventory_movements
- id INTEGER PK
- variant_id INTEGER NOT NULL
- delta_on_hand INTEGER NOT NULL
- delta_reserved INTEGER NOT NULL
- reason TEXT NOT NULL
- reference TEXT NOT NULL
- created_at TEXT NOT NULL
UNIQUE(reason, reference) where reference is the idempotency key for that reason

### carts, cart_items
Guest carts keyed by signed cookie id; member carts keyed by user_id.

### orders
- id INTEGER PK
- public_id TEXT NOT NULL UNIQUE
- user_id INTEGER NOT NULL
- status TEXT NOT NULL CHECK (status IN ('pending_payment','paid','fulfilling','completed','cancelled','refund_requested','refunded'))
- currency TEXT NOT NULL
- goods_minor INTEGER NOT NULL
- discount_minor INTEGER NOT NULL DEFAULT 0
- payable_minor INTEGER NOT NULL
- address_snapshot TEXT NOT NULL
- created_at TEXT NOT NULL
- updated_at TEXT NOT NULL

### order_items
Line snapshots: name, sku, qty, unit_minor, type.

### payments
- id INTEGER PK
- order_id INTEGER NOT NULL
- provider TEXT NOT NULL
- provider_ref TEXT NULL
- amount_minor INTEGER NOT NULL
- status TEXT NOT NULL CHECK (status IN ('created','succeeded','failed','cancelled'))
- idempotency_key TEXT NOT NULL UNIQUE

### payment_attempts
Raw callback payloads stored **redacted** (no PAN, no full secrets).

### refunds
Independent state machine: requested → approved/rejected → succeeded/failed.

### digital_deliveries
Tokenized download or license payload references; tokens hashed at rest.

### coupons, coupon_redemptions
Code unique; redemption unique (coupon_id, user_id, order_id).

### prohibited_product_rules
Pattern, match_field, action (`block_publish` / `hold_review`), enabled flag.

### product_reports
User reports against a product.

### audit_logs
actor_id, action, entity, entity_id, meta JSON (no secrets), ip_hash, created_at

### system_settings
key UNIQUE, value TEXT, updated_at

### jobs, failed_jobs
Optional local queue for mail and webhooks.

### legal_acceptances
user_id, document_key, version, accepted_at

## License-server schema (separate database, later)

customers, commercial_orders, catalog_products, product_versions, licenses, license_entitlements, license_domains, activations, releases, downloads, support_tickets, license_audit_logs.

Licenses store the canonical JSON body and a detached signature. Private keys never live in Community Core.
