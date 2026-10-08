-- Автоматизация RiskScanner (история сканов вместо только "по кнопке") и
-- новый механизм port-scan detection + auto-ban для exit-серверов — по
-- итогам разбора статьи про Active Probing (2026-09-24).

CREATE TABLE IF NOT EXISTS risk_scan_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    host TEXT NOT NULL,
    score INTEGER NOT NULL,
    findings_json TEXT NOT NULL,
    scanned_at TEXT NOT NULL DEFAULT (datetime('now')),
    triggered_by TEXT NOT NULL DEFAULT 'cron' -- cron|manual
);
CREATE INDEX IF NOT EXISTS idx_risk_scan_results_server ON risk_scan_results(server_id, scanned_at);

-- Port-scan ban — отдельный от port-knocking механизм (тот прячет
-- УПРАВЛЯЮЩИЙ порт, этот — банит источник, замеченный за перебором портов
-- на самой МАШИНЕ, чтобы Active Probing даже не успел собрать полную
-- картину сервисов). На servers, не на exit_servers — это machine-level
-- iptables-механизм, а не свойство конкретного протокола/exit-конфига,
-- ровно как и knock_enabled/knock_protected_port/knock_ports уже на
-- servers (см. 006_port_knock.sql). НЕ применяется автоматически ни к
-- одному серверу — только когда админ явно включит.
ALTER TABLE servers ADD COLUMN portscan_ban_enabled INTEGER NOT NULL DEFAULT 0;
ALTER TABLE servers ADD COLUMN portscan_decoy_ports TEXT; -- JSON-массив портов-приманок
ALTER TABLE servers ADD COLUMN portscan_ban_seconds INTEGER NOT NULL DEFAULT 86400;
