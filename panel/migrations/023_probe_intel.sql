-- Активная защита (Probe Intelligence): наблюдение доступности exit-узлов локально
-- (вантедж панели) и извне (check-host) во времени — ранняя детекция
-- блокировки Active Blocking System — и агрегаты входящих зондирований (кто стучится на Reality-порт).
-- Данные панель собирает САМА по исходящему SSH (pull): на exit нет приёмника.

CREATE TABLE IF NOT EXISTS probe_samples (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id          INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    checked_at         TEXT NOT NULL DEFAULT (datetime('now')),
    port               INTEGER NOT NULL,
    rf_tcp_ok          INTEGER NOT NULL DEFAULT 0,  -- TCP с вантеджа панели (вход)
    rf_tls_ok          INTEGER NOT NULL DEFAULT 0,  -- завершился ли TLS-хендшейк локально
    rf_latency_ms      INTEGER,
    abroad_ok          INTEGER,                     -- NULL = внешняя проверка не делалась
    abroad_nodes_ok    INTEGER,
    abroad_nodes_total INTEGER,
    verdict            TEXT NOT NULL                -- ok | rf_degraded | rf_blocked | down | unknown
);
CREATE INDEX IF NOT EXISTS idx_probe_samples_server ON probe_samples(server_id, id DESC);

CREATE TABLE IF NOT EXISTS probe_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id   INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    observed_at TEXT NOT NULL DEFAULT (datetime('now')),
    src_ip      TEXT NOT NULL,
    src_country TEXT,
    src_asn     TEXT,
    dst_port    INTEGER,
    hits        INTEGER NOT NULL DEFAULT 1,
    note        TEXT
);
CREATE INDEX IF NOT EXISTS idx_probe_events_server ON probe_events(server_id, id DESC);

-- Состояние опционального логирования зондирований на самом exit (LOG-only
-- nftables). Включается пользователем по кнопке, снимается одной командой.
CREATE TABLE IF NOT EXISTS probe_log_state (
    server_id    INTEGER PRIMARY KEY REFERENCES servers(id) ON DELETE CASCADE,
    enabled      INTEGER NOT NULL DEFAULT 0,
    installed_at TEXT,
    last_pull_at TEXT
);
