-- Список заблокированных IP на exit-узле (DROP на Reality-порт в изолированной
-- таблице nftables vpsr_probe). Применяется тем же exit-probe-log.sh по SSH.
-- Свой входной IP/SSH панель в этот список не пускает (защита от само-отреза).
CREATE TABLE IF NOT EXISTS probe_blocks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id  INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    ip         TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_probe_blocks_uniq ON probe_blocks(server_id, ip);
