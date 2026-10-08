-- Multi-router (variant 3): associate devices and route groups with a specific
-- entry-router node, and give each router its own inbound/Reality settings.
--
-- Phase 1 is backward-compatible: everything existing is backfilled onto the
-- current self-router, so the single-router setup keeps working unchanged.
-- SingboxConfigBuilder is parameterised in a later phase; here we only add and
-- seed the columns/tables it will read.

-- Which router each device (Reality/inbound client) belongs to.
ALTER TABLE clients ADD COLUMN router_server_id INTEGER REFERENCES servers(id) ON DELETE CASCADE;

-- Which router each route group (pool) is applied on.
ALTER TABLE rule_groups ADD COLUMN router_server_id INTEGER REFERENCES servers(id) ON DELETE CASCADE;

-- Backfill existing rows onto the current self-router (NULL stays NULL on a
-- fresh install with no self server yet — harmless).
UPDATE clients
   SET router_server_id = (SELECT id FROM servers WHERE is_self = 1 LIMIT 1)
 WHERE router_server_id IS NULL;

UPDATE rule_groups
   SET router_server_id = (SELECT id FROM servers WHERE is_self = 1 LIMIT 1)
 WHERE router_server_id IS NULL;

-- Per-router settings: same key/value shape as the global server_settings, but
-- scoped to one router node. Reads fall back to the global server_settings when
-- a node has no override (see App\Models\NodeSetting), so nothing breaks for
-- settings that stay global.
CREATE TABLE IF NOT EXISTS node_settings (
    server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    key TEXT NOT NULL,
    value TEXT,
    PRIMARY KEY (server_id, key)
);

-- Seed the self-router's node settings from today's global inbound/Reality
-- values, so the builder produces an identical config for self after it starts
-- reading node-scoped settings.
INSERT OR IGNORE INTO node_settings (server_id, key, value)
SELECT (SELECT id FROM servers WHERE is_self = 1 LIMIT 1), key, value
  FROM server_settings
 WHERE (SELECT id FROM servers WHERE is_self = 1 LIMIT 1) IS NOT NULL
   AND (
        key LIKE 'reality_%'
     OR key LIKE 'inbound_%'
     OR key IN ('adblock_enabled', 'block_quic')
   );

CREATE INDEX IF NOT EXISTS idx_clients_router ON clients(router_server_id);
CREATE INDEX IF NOT EXISTS idx_rule_groups_router ON rule_groups(router_server_id);
