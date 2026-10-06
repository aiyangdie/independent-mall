ALTER TABLE users ADD COLUMN session_epoch INTEGER NOT NULL DEFAULT 1;

CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE user_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    recipient TEXT NOT NULL,
    phone TEXT NOT NULL,
    region TEXT NOT NULL,
    line1 TEXT NOT NULL,
    line2 TEXT NOT NULL DEFAULT '',
    postal_code TEXT NOT NULL DEFAULT '',
    is_default INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE carts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE cart_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cart_id INTEGER NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
    variant_id INTEGER NOT NULL REFERENCES product_variants(id),
    qty INTEGER NOT NULL CHECK (qty > 0),
    UNIQUE (cart_id, variant_id)
);

CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    status TEXT NOT NULL CHECK (status IN ('pending_payment','paid','fulfilling','completed','cancelled','refund_requested','refunded')),
    currency TEXT NOT NULL,
    goods_minor INTEGER NOT NULL,
    discount_minor INTEGER NOT NULL DEFAULT 0,
    shipping_minor INTEGER NOT NULL DEFAULT 0,
    tax_minor INTEGER NOT NULL DEFAULT 0,
    payable_minor INTEGER NOT NULL,
    address_snapshot TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, idempotency_key)
);

CREATE TABLE order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NOT NULL,
    product_type TEXT NOT NULL,
    name TEXT NOT NULL,
    sku TEXT NOT NULL,
    qty INTEGER NOT NULL,
    unit_minor INTEGER NOT NULL,
    currency TEXT NOT NULL
);

CREATE TABLE payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id),
    provider TEXT NOT NULL,
    provider_ref TEXT,
    amount_minor INTEGER NOT NULL,
    currency TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('created','succeeded','failed','cancelled')),
    idempotency_key TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE payment_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id INTEGER NOT NULL REFERENCES payments(id),
    payload_redacted TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE refunds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id),
    payment_id INTEGER REFERENCES payments(id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
    status TEXT NOT NULL CHECK (status IN ('requested','approved','rejected','succeeded','failed')),
    reason TEXT NOT NULL,
    idempotency_key TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE digital_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    body TEXT NOT NULL
);

CREATE TABLE digital_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_item_id INTEGER NOT NULL REFERENCES order_items(id),
    asset_id INTEGER NOT NULL REFERENCES digital_assets(id),
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    max_downloads INTEGER NOT NULL,
    download_count INTEGER NOT NULL DEFAULT 0,
    revoked INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE TABLE legal_acceptances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    document_key TEXT NOT NULL,
    version TEXT NOT NULL,
    accepted_at TEXT NOT NULL
);
