#!/usr/bin/env bash
# Поднимает обычный (без обфускации AmneziaWG) WireGuard-сервер на
# exit-сервере — отдельный интерфейс/порт, изолированный от любых уже
# существующих WG-туннелей на этом сервере.
#
# Вызывается панелью через Ssh::runProvisionScript — параметры в $1
# (JSON-файл). входной VPS для этого протокола НЕ поднимает внешний интерфейс —
# sing-box терминирует WG сам (нативный outbound), поэтому здесь настраивается
# только серверная (exit) сторона.
# Ожидаемые поля в params.json:
#   interface_name, listen_port, server_private_key, local_address,
#   peer_public_key, peer_preshared_key (опц.), peer_allowed_ip

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[exit-wireguard] $*"; }

detect_os
ensure_base_tools || exit 1
install_wireguard_tools || { log "ERROR: не удалось установить wireguard-tools автоматически"; exit 1; }
WAN_IF=$(default_iface)

IFACE=$(jq -r '.interface_name' "$PARAMS_FILE")
LISTEN_PORT=$(jq -r '.listen_port' "$PARAMS_FILE")
SERVER_PRIVKEY=$(jq -r '.server_private_key' "$PARAMS_FILE")
LOCAL_ADDRESS=$(jq -r '.local_address' "$PARAMS_FILE")
PEER_PUBKEY=$(jq -r '.peer_public_key' "$PARAMS_FILE")
PEER_PSK=$(jq -r '.peer_preshared_key // empty' "$PARAMS_FILE")
PEER_ALLOWED_IP=$(jq -r '.peer_allowed_ip' "$PARAMS_FILE")

for v in "$IFACE" "$LISTEN_PORT" "$SERVER_PRIVKEY" "$LOCAL_ADDRESS" "$PEER_PUBKEY" "$PEER_ALLOWED_IP"; do
    [ -n "$v" ] && [ "$v" != "null" ] || { log "ERROR: не все обязательные параметры заданы"; exit 1; }
done

CONF="/etc/wireguard/${IFACE}.conf"
mkdir -p /etc/wireguard
{
    echo "# Сгенерировано панелью автоматически. Ручные правки будут перезаписаны."
    echo "[Interface]"
    echo "PrivateKey = $SERVER_PRIVKEY"
    echo "Address = $LOCAL_ADDRESS"
    echo "ListenPort = $LISTEN_PORT"
    echo "PostUp = iptables -I FORWARD -i %i -j ACCEPT; iptables -I FORWARD -o %i -j ACCEPT; iptables -t nat -A POSTROUTING -o $WAN_IF -j MASQUERADE"
    echo "PostDown = iptables -D FORWARD -i %i -j ACCEPT; iptables -D FORWARD -o %i -j ACCEPT; iptables -t nat -D POSTROUTING -o $WAN_IF -j MASQUERADE"
    echo ""
    echo "[Peer]"
    echo "PublicKey = $PEER_PUBKEY"
    [ -n "$PEER_PSK" ] && [ "$PEER_PSK" != "null" ] && echo "PresharedKey = $PEER_PSK"
    echo "AllowedIPs = $PEER_ALLOWED_IP"
} > "$CONF"
chmod 600 "$CONF"

if ip link show "$IFACE" >/dev/null 2>&1; then
    log "Интерфейс $IFACE уже поднят, перезапускаю..."
    wg-quick down "$IFACE" || true
fi
wg-quick up "$IFACE"
systemctl enable "wg-quick@${IFACE}" >/dev/null 2>&1 || true

enable_ip_forward
open_port "$LISTEN_PORT/udp"

log "Готово: $IFACE поднят на порту $LISTEN_PORT"
