-- Учёт трафика устройств через Clash API sing-box (bin/collect_traffic.php).
-- client_key: "c<id>" — известное устройство панели, "ip:<адрес>" — не опознано.
-- outbound: тег sing-box (direct-rf / exit-<id> / block), host — куда шло соединение.

CREATE TABLE IF NOT EXISTS traffic_minute (
    minute TEXT NOT NULL,          -- 'YYYY-MM-DD HH:MM' UTC
    client_key TEXT NOT NULL,
    outbound TEXT NOT NULL,
    host TEXT NOT NULL,
    inbound TEXT,                  -- например "vless/reality-in"
    up INTEGER NOT NULL DEFAULT 0, -- байт от устройства
    down INTEGER NOT NULL DEFAULT 0, -- байт к устройству
    PRIMARY KEY (minute, client_key, outbound, host)
);
CREATE INDEX IF NOT EXISTS idx_traffic_minute_client ON traffic_minute(client_key, minute);

-- Последние виденные счётчики активных соединений — чтобы между запусками
-- сборщика считать дельты, а не суммировать одно и то же соединение заново.
CREATE TABLE IF NOT EXISTS traffic_conn_state (
    conn_id TEXT PRIMARY KEY,
    up INTEGER NOT NULL,
    down INTEGER NOT NULL,
    seen_at INTEGER NOT NULL
);

-- Когда и откуда устройство подключалось последний раз.
CREATE TABLE IF NOT EXISTS device_presence (
    client_key TEXT PRIMARY KEY,
    source_ip TEXT,
    inbound TEXT,
    last_seen INTEGER NOT NULL
);
