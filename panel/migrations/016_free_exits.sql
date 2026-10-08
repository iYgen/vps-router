-- Free exit servers (integration with free-vpn-subscriptions): exit_servers can
-- now originate from a public tested subscription instead of a user-owned VPS.
-- Such rows carry source='free', an optional country code, and store the ready
-- sing-box outbound inside protocol_params (_raw) — see SingboxConfigBuilder.
-- No SSH / no provisioning (provision_status='external').
--
-- Safe ALTERs only (no table rebuild): new columns with defaults.
ALTER TABLE exit_servers ADD COLUMN source TEXT NOT NULL DEFAULT 'own';
ALTER TABLE exit_servers ADD COLUMN country TEXT;

CREATE INDEX IF NOT EXISTS idx_exit_servers_source ON exit_servers(source);
