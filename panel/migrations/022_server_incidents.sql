-- Инциденты недоступности серверов: период offline→online + снятые по SSH логи
-- за окно простоя (чтобы потом понять, почему узел «падал»). Пишутся из
-- bin/health_check_servers.php через App\ServerIncidents, показываются в
-- инспекторе сервера (вкладка «Журнал простоев»).
CREATE TABLE IF NOT EXISTS server_incidents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id   INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    down_at     TEXT NOT NULL,              -- когда ушёл в offline/warning (UTC)
    up_at       TEXT,                       -- когда вернулся в online (UTC); NULL = ещё лежит
    down_detail TEXT,                       -- причина перехода (raw_error проверки)
    logs        TEXT,                       -- логи за окно простоя (journalctl и пр.)
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_server_incidents_server ON server_incidents(server_id, id DESC);
