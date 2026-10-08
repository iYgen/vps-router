-- Плановая перезагрузка серверов (по расписанию). Один ряд на сервер.
-- freq: 'daily' | 'weekly'; time_utc 'HH:MM' (UTC); dow 0..6 (вс..сб) для weekly.
CREATE TABLE IF NOT EXISTS scheduled_reboots (
    server_id INTEGER PRIMARY KEY REFERENCES servers(id) ON DELETE CASCADE,
    enabled INTEGER NOT NULL DEFAULT 0,
    freq TEXT NOT NULL DEFAULT 'weekly',
    time_utc TEXT NOT NULL DEFAULT '04:00',
    dow INTEGER NOT NULL DEFAULT 0,
    last_run_at TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
