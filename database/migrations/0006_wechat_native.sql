CREATE TABLE wechat_native_orders (
    order_id INTEGER PRIMARY KEY REFERENCES orders(id),
    payment_id INTEGER NOT NULL UNIQUE REFERENCES payments(id),
    code_url TEXT,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE UNIQUE INDEX payments_provider_ref_unique
    ON payments (provider, provider_ref) WHERE provider_ref IS NOT NULL AND provider_ref <> '';
