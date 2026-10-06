CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER REFERENCES categories(id),
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL CHECK (status IN ('active','hidden'))
);

CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    type TEXT NOT NULL CHECK (type IN ('physical','digital','service')),
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    summary TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL CHECK (status IN ('draft','in_review','active','rejected','archived')),
    review_note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE product_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sku TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    price_minor INTEGER NOT NULL CHECK (price_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CNY',
    status TEXT NOT NULL CHECK (status IN ('active','disabled'))
);

CREATE TABLE inventories (
    variant_id INTEGER PRIMARY KEY REFERENCES product_variants(id) ON DELETE CASCADE,
    on_hand INTEGER NOT NULL DEFAULT 0 CHECK (on_hand >= 0),
    reserved INTEGER NOT NULL DEFAULT 0 CHECK (reserved >= 0)
);

CREATE TABLE inventory_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES product_variants(id),
    delta_on_hand INTEGER NOT NULL,
    delta_reserved INTEGER NOT NULL,
    reason TEXT NOT NULL,
    reference TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (reason, reference)
);

CREATE TABLE product_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    storage_key TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE prohibited_product_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pattern TEXT NOT NULL,
    match_field TEXT NOT NULL CHECK (match_field IN ('name','summary','description','any')),
    action TEXT NOT NULL CHECK (action IN ('block_publish','hold_review')),
    enabled INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE product_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id),
    reason TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL CHECK (status IN ('open','reviewed','dismissed')),
    created_at TEXT NOT NULL
);

INSERT INTO categories (slug, name, sort, status) VALUES
    ('physical-goods', 'Physical goods', 1, 'active'),
    ('digital-goods', 'Licensed digital goods', 2, 'active'),
    ('services', 'Professional services', 3, 'active');

INSERT INTO prohibited_product_rules (pattern, match_field, action, enabled) VALUES
    ('刷赞', 'any', 'block_publish', 1),
    ('代刷', 'any', 'block_publish', 1),
    ('刷量', 'any', 'block_publish', 1),
    ('刷钻', 'any', 'block_publish', 1),
    ('外挂', 'any', 'block_publish', 1),
    ('账号交易', 'any', 'block_publish', 1),
    ('Q币', 'any', 'hold_review', 1),
    ('游戏账号', 'any', 'hold_review', 1);
