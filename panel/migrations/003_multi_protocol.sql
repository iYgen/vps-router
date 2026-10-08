-- Расширяет exit_servers до "любая exit-цель", а не только AmneziaWG.
-- WG-поля (wg_peer_pubkey и т.д.) остаются как есть для protocol='amneziawg' —
-- ничего не мигрируется, полная обратная совместимость с существующими строками.
-- Server Sets / Routes / resolveExitServerId продолжают работать по exit_server_id
-- без изменений — им всё равно, какой у цели протокол.

ALTER TABLE exit_servers ADD COLUMN protocol TEXT NOT NULL DEFAULT 'amneziawg';
-- amneziawg | wireguard | vless | shadowsocks

ALTER TABLE exit_servers ADD COLUMN protocol_params TEXT;
-- JSON, форма зависит от protocol (см. docs/infrastructure-ui.md):
--   wireguard:    {peer_pubkey, local_privkey, local_address, mtu}
--   vless:        {uuid, flow, transport, reality|ws|grpc}
--   shadowsocks:  {method, password}
--   amneziawg:    не используется (все поля уже в wg_*-колонках)

ALTER TABLE exit_servers ADD COLUMN provision_status TEXT NOT NULL DEFAULT 'not_provisioned';
-- not_provisioned | provisioning | provisioned | failed

ALTER TABLE exit_servers ADD COLUMN last_provision_at TEXT;
ALTER TABLE exit_servers ADD COLUMN last_provision_log TEXT;
