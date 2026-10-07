CREATE TABLE shipments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL UNIQUE REFERENCES orders(id),
    carrier TEXT NOT NULL,
    tracking_number TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('shipped','delivered')),
    idempotency_key TEXT NOT NULL UNIQUE,
    shipped_by INTEGER NOT NULL REFERENCES users(id),
    shipped_at TEXT NOT NULL,
    received_at TEXT,
    updated_at TEXT NOT NULL
);

ALTER TABLE refunds ADD COLUMN provider TEXT NOT NULL DEFAULT '';
ALTER TABLE refunds ADD COLUMN provider_ref TEXT;
ALTER TABLE refunds ADD COLUMN order_status_before TEXT NOT NULL DEFAULT 'completed';

UPDATE refunds SET provider = COALESCE((SELECT provider FROM payments WHERE payments.id = refunds.payment_id), '');

CREATE INDEX refunds_order_status ON refunds (order_id, status);
