-- Independent Mall foundation schema (SQLite).
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('active','locked','pending','deleted')),
    totp_secret TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT
);

CREATE TABLE roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL
);

CREATE TABLE permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE role_permissions (
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identity TEXT NOT NULL,
    ip_hash TEXT NOT NULL,
    success INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX login_attempts_identity_time ON login_attempts (identity, created_at);

CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id INTEGER,
    action TEXT NOT NULL,
    entity TEXT NOT NULL DEFAULT '',
    entity_id TEXT NOT NULL DEFAULT '',
    meta TEXT NOT NULL DEFAULT '{}',
    ip_hash TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE system_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO roles (name, label) VALUES ('admin', 'Administrator');
INSERT INTO roles (name, label) VALUES ('customer', 'Customer');
INSERT INTO permissions (name) VALUES ('admin.access');
INSERT INTO permissions (name) VALUES ('catalog.manage');
INSERT INTO permissions (name) VALUES ('order.manage');
INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id FROM roles r JOIN permissions p
    WHERE r.name = 'admin' AND p.name IN ('admin.access','catalog.manage','order.manage');
