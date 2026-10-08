-- Балансировка нагрузки между равнозначными exit-серверами (протокол
-- wireguard): pool_label группирует взаимозаменяемые exit_servers, а
-- exit_server_load хранит последний замер CPU/RAM/трафика для выбора
-- наименее загруженного при добавлении нового устройства.
-- NULL pool_label = сервер вне пула, поведение не меняется.

ALTER TABLE exit_servers ADD COLUMN pool_label TEXT;
CREATE INDEX IF NOT EXISTS idx_exit_servers_pool ON exit_servers(pool_label, protocol, status);

CREATE TABLE IF NOT EXISTS exit_server_load (
    exit_server_id INTEGER PRIMARY KEY REFERENCES exit_servers(id) ON DELETE CASCADE,
    cpu_load_1min REAL,
    mem_used_percent INTEGER,
    net_bytes_total INTEGER,
    net_bytes_per_sec REAL,
    sample_at TEXT NOT NULL DEFAULT (datetime('now')),
    raw_error TEXT
);
