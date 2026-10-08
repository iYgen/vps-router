-- Долгосрочная статистика устройств (traffic_minute хранится только 48 ч):
-- по суткам — навсегда (строка на устройство в день), по сайтам за сутки — 60 дней.
CREATE TABLE IF NOT EXISTS traffic_daily (
    day TEXT NOT NULL,              -- 'YYYY-MM-DD' UTC
    client_key TEXT NOT NULL,
    up INTEGER NOT NULL DEFAULT 0,
    down INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (day, client_key)
);

CREATE TABLE IF NOT EXISTS traffic_hosts_daily (
    day TEXT NOT NULL,
    client_key TEXT NOT NULL,
    host TEXT NOT NULL,
    outbound TEXT NOT NULL,
    up INTEGER NOT NULL DEFAULT 0,
    down INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (day, client_key, host, outbound)
);

-- То, что уже накоплено поминутно до этой миграции, переносим в сутки.
INSERT OR IGNORE INTO traffic_daily (day, client_key, up, down)
    SELECT substr(minute, 1, 10), client_key, SUM(up), SUM(down) FROM traffic_minute GROUP BY substr(minute, 1, 10), client_key;
INSERT OR IGNORE INTO traffic_hosts_daily (day, client_key, host, outbound, up, down)
    SELECT substr(minute, 1, 10), client_key, host, outbound, SUM(up), SUM(down) FROM traffic_minute GROUP BY substr(minute, 1, 10), client_key, host, outbound;

-- Трафик самих серверов по счётчикам основного сетевого интерфейса
-- (App\ServerTraffic) — то, что считает хостер для лимита тарифа.
CREATE TABLE IF NOT EXISTS server_traffic_daily (
    server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    day TEXT NOT NULL,
    rx INTEGER NOT NULL DEFAULT 0,  -- входящий на сервер
    tx INTEGER NOT NULL DEFAULT 0,  -- исходящий с сервера
    PRIMARY KEY (server_id, day)
);

CREATE TABLE IF NOT EXISTS server_traffic_state (
    server_id INTEGER PRIMARY KEY REFERENCES servers(id) ON DELETE CASCADE,
    iface TEXT,
    last_rx INTEGER NOT NULL,
    last_tx INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

-- Лимит тарифа (необязательно): ГБ за расчётный период, день начала периода,
-- как хостер считает (sum — вход+выход, out — только исходящий, max — большее
-- из двух) и ручная сверка «израсходовано по данным хостера» на дату.
ALTER TABLE servers ADD COLUMN traffic_limit_gb REAL;
ALTER TABLE servers ADD COLUMN traffic_reset_day INTEGER NOT NULL DEFAULT 1;
ALTER TABLE servers ADD COLUMN traffic_count_mode TEXT NOT NULL DEFAULT 'sum';
ALTER TABLE servers ADD COLUMN traffic_sync_gb REAL;           -- сколько показывал хостер
ALTER TABLE servers ADD COLUMN traffic_sync_measured_gb REAL;  -- сколько на тот момент насчитала панель
ALTER TABLE servers ADD COLUMN traffic_sync_at TEXT;           -- когда сверяли (UTC)
