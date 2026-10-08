-- Client Routing Policy (Keenetic Phase 1, см. docs/infrastructure-ui.md
-- раздел 15 и 45). Транспорт (WireGuard-туннель до exit-сервера) уже
-- реализован в exit_server_peers/wg-peer-apply.sh — эта миграция добавляет
-- только control-plane: какие домены реально маршрутизируются, и куда
-- (Keenetic-роутер) эта политика синхронизируется по RCI.
--
-- Имена таблиц намеренно НЕ используют префикс "client_" — в проекте
-- Client/clients уже занято под VLESS-абонентов (App\Models\Client,
-- public/devices.php) и означает другую сущность. Используем "policy_".

CREATE TABLE IF NOT EXISTS policy_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    default_action TEXT NOT NULL DEFAULT 'direct_local', -- direct_local|proxy|block
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS policy_profile_routes (
    profile_id INTEGER NOT NULL REFERENCES policy_profiles(id) ON DELETE CASCADE,
    rule_group_id INTEGER NOT NULL REFERENCES rule_groups(id) ON DELETE CASCADE,
    PRIMARY KEY (profile_id, rule_group_id)
);

CREATE TABLE IF NOT EXISTS policy_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_id INTEGER NOT NULL REFERENCES policy_profiles(id) ON DELETE CASCADE,
    version INTEGER NOT NULL,
    policy_json TEXT NOT NULL,  -- нормализованный canonical snapshot, БЕЗ секретов
    policy_hash TEXT NOT NULL,  -- sha256 снапшота, для быстрого diff/verify
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    created_by TEXT,
    published_at TEXT,          -- NULL = черновик, не публиковался
    UNIQUE(profile_id, version)
);

CREATE TABLE IF NOT EXISTS policy_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    adapter_type TEXT NOT NULL DEFAULT 'keenetic', -- keenetic|android|windows (последние два зарезервированы, не обрабатываются)
    profile_id INTEGER REFERENCES policy_profiles(id) ON DELETE SET NULL,
    exit_server_peer_id INTEGER REFERENCES exit_server_peers(id) ON DELETE SET NULL, -- WG-транспорт (уже существующий механизм)
    wg_interface_name TEXT, -- имя WireGuard-подключения НА САМОМ Keenetic (как он его называет в своём RCI) — автоопределяется по публичному ключу при sync, это поле — ручной override на случай, если автоопределение не сработало
    rci_host TEXT,
    rci_port INTEGER NOT NULL DEFAULT 443,
    rci_scheme TEXT NOT NULL DEFAULT 'https',
    rci_username TEXT,
    rci_password_enc BLOB, -- App\Secrets, как servers.ssh_private_key_enc
    applied_policy_version INTEGER,
    sync_status TEXT NOT NULL DEFAULT 'never', -- never|syncing|ok|failed
    last_sync_at TEXT,
    last_sync_log TEXT,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_policy_profile_routes_profile ON policy_profile_routes(profile_id);
CREATE INDEX IF NOT EXISTS idx_policy_versions_profile ON policy_versions(profile_id);
CREATE INDEX IF NOT EXISTS idx_policy_devices_profile ON policy_devices(profile_id);
