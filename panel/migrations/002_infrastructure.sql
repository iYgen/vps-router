-- Визуальная модель инфраструктуры: узлы (servers), рёбра (connections),
-- наборы серверов (server_sets) и версии применённых конфигов.
-- exit_servers/rule_groups/rules НЕ переписываются — расширяются.

CREATE TABLE IF NOT EXISTS servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'generic', -- router|exit|proxy|vpn|gateway|storage|generic
    host TEXT NOT NULL DEFAULT '',
    ssh_port INTEGER NOT NULL DEFAULT 22,
    ssh_user TEXT NOT NULL DEFAULT 'root',
    ssh_private_key_enc BLOB,             -- libsodium secretbox(nonce.ciphertext), см. App\Secrets
    ssh_public_key TEXT,
    country TEXT,
    region TEXT,
    description TEXT,
    tags TEXT,                            -- JSON-массив строк
    enabled INTEGER NOT NULL DEFAULT 1,
    is_self INTEGER NOT NULL DEFAULT 0,   -- ровно одна строка = 1: сам входной VPS, на котором крутится панель
    position_x REAL NOT NULL DEFAULT 0,
    position_y REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'unknown', -- online|warning|offline|unknown|maintenance
    last_checked_at TEXT,
    last_check_result TEXT,               -- JSON: {hostname, os, kernel, uptime, ipv4, sudo}
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    target_server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    type TEXT NOT NULL, -- ssh|wireguard|amneziawg|tcp|http|socks|vless|generic
    exit_server_id INTEGER REFERENCES exit_servers(id) ON DELETE SET NULL, -- источник правды для amneziawg/wireguard
    config TEXT,        -- JSON, для типов без legacy-таблицы (ssh/tcp/http/socks/vless/generic)
    label TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(source_server_id, target_server_id, type)
);

CREATE TABLE IF NOT EXISTS server_sets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    strategy TEXT NOT NULL DEFAULT 'manual', -- manual|priority|failover
    enabled INTEGER NOT NULL DEFAULT 1,
    tags TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS server_set_members (
    server_set_id INTEGER NOT NULL REFERENCES server_sets(id) ON DELETE CASCADE,
    server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    priority INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (server_set_id, server_id)
);

CREATE TABLE IF NOT EXISTS config_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    username TEXT NOT NULL,
    description TEXT,
    singbox_config TEXT NOT NULL, -- JSON снапшот применённого конфига (для diff/rollback)
    status TEXT NOT NULL DEFAULT 'applied', -- applied|rolled_back
    apply_stdout TEXT,
    apply_stderr TEXT,
    apply_exit_code INTEGER
);

ALTER TABLE rule_groups ADD COLUMN server_set_id INTEGER REFERENCES server_sets(id) ON DELETE SET NULL;
ALTER TABLE rule_groups ADD COLUMN comment TEXT;

CREATE INDEX IF NOT EXISTS idx_connections_source ON connections(source_server_id);
CREATE INDEX IF NOT EXISTS idx_connections_target ON connections(target_server_id);
CREATE INDEX IF NOT EXISTS idx_server_set_members_set ON server_set_members(server_set_id);
CREATE INDEX IF NOT EXISTS idx_rule_groups_server_set ON rule_groups(server_set_id);

-- Автопереезд текущей топологии на граф, чтобы после апгрейда she сразу
-- отражала то, что уже реально работает: один self-узел (входной VPS) плюс
-- по узлу и amneziawg-связи на каждый существующий exit-сервер.

INSERT INTO servers (name, role, host, enabled, is_self, position_x, position_y, status)
SELECT 'Entry Router', 'router', '', 1, 1, 400, 80, 'unknown'
WHERE NOT EXISTS (SELECT 1 FROM servers WHERE is_self = 1);

INSERT INTO servers (name, role, host, description, enabled, position_x, position_y, status)
SELECT es.name, 'exit', es.endpoint_host,
       'Автоматически перенесено из exit_servers при миграции 002',
       CASE WHEN es.status = 'disabled' THEN 0 ELSE 1 END,
       200 + (es.id * 220), 320, 'unknown'
FROM exit_servers es
WHERE NOT EXISTS (SELECT 1 FROM servers s WHERE s.name = es.name AND s.role = 'exit');

INSERT INTO connections (source_server_id, target_server_id, type, exit_server_id, label)
SELECT (SELECT id FROM servers WHERE is_self = 1 LIMIT 1), s.id, 'amneziawg', es.id, es.name
FROM exit_servers es
JOIN servers s ON s.name = es.name AND s.role = 'exit'
WHERE NOT EXISTS (
    SELECT 1 FROM connections c WHERE c.exit_server_id = es.id AND c.type = 'amneziawg'
);
