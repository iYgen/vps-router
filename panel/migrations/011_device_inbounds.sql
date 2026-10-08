-- Несколько протоколов подключения устройств к входной VPS (не только VLESS+Reality):
-- у каждого устройства свои учётные данные под каждый протокол, а какой из
-- протоколов включён на сервере — задаётся в «Настройки» (server_settings,
-- ключи inbound_*). См. App\DeviceInbounds.
--   password      — Trojan / Hysteria2
--   ss_psk        — пользовательский ключ Shadowsocks-2022 (base64, 16 байт)
--   wg_private_key/wg_public_key — WireGuard и AmneziaWG (одна пара на оба)
-- Адрес устройства в туннеле вычисляется из id (см. DeviceInbounds::tunnelAddress).

ALTER TABLE clients ADD COLUMN password TEXT;
ALTER TABLE clients ADD COLUMN ss_psk TEXT;
ALTER TABLE clients ADD COLUMN wg_private_key TEXT;
ALTER TABLE clients ADD COLUMN wg_public_key TEXT;
