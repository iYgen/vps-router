-- Схема БД панели управления маршрутизацией (SQLite)

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Единственная строка с общими настройками Reality-инбаунда и сервера.
CREATE TABLE IF NOT EXISTS server_settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS exit_servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    endpoint_host TEXT NOT NULL,
    endpoint_port INTEGER NOT NULL DEFAULT 51820,
    wg_peer_pubkey TEXT NOT NULL,      -- публичный ключ WG на стороне exit-сервера
    wg_peer_psk TEXT,                  -- preshared key (опционально)
    wg_local_privkey TEXT NOT NULL,    -- приватный ключ входной VPS для этого туннеля
    wg_local_address TEXT NOT NULL,    -- например 10.90.0.2/32
    interface_name TEXT NOT NULL UNIQUE, -- например awg-ex1
    amnezia_params TEXT,               -- JSON с Jc/Jmin/Jmax/S1/S2/H1-4 для обфускации AmneziaWG
    status TEXT NOT NULL DEFAULT 'active', -- active|standby|disabled
    last_health_ok_at TEXT,
    last_health_check_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS rule_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    exit_server_id INTEGER REFERENCES exit_servers(id) ON DELETE SET NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL REFERENCES rule_groups(id) ON DELETE CASCADE,
    type TEXT NOT NULL, -- domain_suffix|domain_full|domain_keyword|ip_cidr|geosite
    value TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    uuid TEXT NOT NULL UNIQUE,
    revoked INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL DEFAULT (datetime('now')),
    username TEXT NOT NULL,
    action TEXT NOT NULL,
    details TEXT
);

CREATE INDEX IF NOT EXISTS idx_rules_group ON rules(group_id);
CREATE INDEX IF NOT EXISTS idx_rule_groups_exit ON rule_groups(exit_server_id);
