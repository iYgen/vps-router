-- Handshake watch: per-minute counters of inbound connection attempts / failed
-- handshakes per client source IP (parsed from the local sing-box journal),
-- detected "waves" of failures and an optional packet capture per wave.

CREATE TABLE IF NOT EXISTS hs_minutes (
    minute  TEXT NOT NULL,            -- 'Y-m-d H:i' UTC
    src_ip  TEXT NOT NULL,
    total   INTEGER NOT NULL DEFAULT 0, -- inbound connection attempts
    failed  INTEGER NOT NULL DEFAULT 0, -- failed handshakes (invalid / TLS handshake errors)
    PRIMARY KEY (minute, src_ip)
);
CREATE INDEX IF NOT EXISTS idx_hs_minutes_ip ON hs_minutes(src_ip, minute);

CREATE TABLE IF NOT EXISTS hs_waves (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    src_ip          TEXT NOT NULL,
    isp             TEXT,
    country         TEXT,
    started_at      TEXT NOT NULL,     -- UTC, first bad minute
    last_bad_at     TEXT NOT NULL,     -- UTC, last bad minute seen
    ended_at        TEXT,              -- UTC, NULL while open
    peak_per_min    INTEGER NOT NULL DEFAULT 0,
    failed_total    INTEGER NOT NULL DEFAULT 0,
    attempts_total  INTEGER NOT NULL DEFAULT 0,
    clients         TEXT,              -- JSON {"c15": 120, ...} seen from this IP during the wave
    capture_file    TEXT,              -- basename in the captures dir
    capture_status  TEXT,              -- requested | done | failed | skipped
    capture_summary TEXT               -- JSON, see HandshakeWatch::analyzeCapture()
);
CREATE INDEX IF NOT EXISTS idx_hs_waves_open ON hs_waves(ended_at, src_ip);

CREATE TABLE IF NOT EXISTS hs_ip_info (
    ip         TEXT PRIMARY KEY,
    isp        TEXT,
    country    TEXT,
    fetched_at TEXT NOT NULL DEFAULT (datetime('now'))
);
