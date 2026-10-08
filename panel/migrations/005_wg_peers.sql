-- Дополнительные WireGuard-пиры на exit-серверах с protocol='wireguard' —
-- для подключения домашних устройств (Keenetic и т.п.) НАПРЯМУЮ к
-- exit-серверу, в обход входной VPS. exit_servers/wg_* колонки по-прежнему
-- хранят единственного "первого" пира (сам входной VPS) — эта таблица не трогает
-- существующую схему, только добавляет вторых/третьих пиров того же
-- WireGuard-интерфейса. См. App\Models\ExitServerPeer, App\Provisioner,
-- deploy/provision/wg-peer-apply.sh.

CREATE TABLE IF NOT EXISTS exit_server_peers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exit_server_id INTEGER NOT NULL REFERENCES exit_servers(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    public_key TEXT NOT NULL,
    private_key TEXT NOT NULL,
    tunnel_address TEXT NOT NULL, -- например 10.90.101.3/32
    revoked INTEGER NOT NULL DEFAULT 0,
    applied_at TEXT,
    apply_log TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_exit_server_peers_exit ON exit_server_peers(exit_server_id);
