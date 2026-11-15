#!/usr/bin/env bash
# Поднимает НОВЫЙ, изолированный AmneziaWG (awg-quick, профиль 3.1) интерфейс на
# exit-сервере — отдельно от любых уже существующих Amnezia-докеров/туннелей
# (см. docs/infrastructure-ui.md, раздел про мультипротокольные exit-цели).
# Идемпотентен: повторный запуск с теми же параметрами просто перезаписывает
# конфиг и перезапускает интерфейс.
#
# Вызывается панелью через Ssh::runProvisionScript — параметры в $1
# (JSON-файл), НИКОГДА не подставляются в командную строку напрямую.
# Ожидаемые поля в params.json:
#   interface_name    — имя интерфейса, например "awg-panel"
#   listen_port        — UDP-порт, на котором слушает awg-quick
#   server_private_key — приватный ключ ЭТОГО сервера (сгенерирован панелью)
#   local_address       — адрес сервера в туннеле, напр. "10.90.0.1/24"
#   peer_public_key      — публичный ключ входной VPS (клиента)
#   peer_preshared_key   — опционально
#   peer_allowed_ip       — адрес клиента в туннеле, напр. "10.90.0.2/32"
#   amnezia_params        — объект параметров обфускации (1.x или 3.1, см. panel/src/Amnezia/AwgParams.php)

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[exit-amneziawg] $*"; }

detect_os
ensure_base_tools || exit 1
install_amneziawg || exit 1
WAN_IF=$(default_iface)

IFACE=$(jq -r '.interface_name' "$PARAMS_FILE")
LISTEN_PORT=$(jq -r '.listen_port' "$PARAMS_FILE")
SERVER_PRIVKEY=$(jq -r '.server_private_key' "$PARAMS_FILE")
LOCAL_ADDRESS=$(jq -r '.local_address' "$PARAMS_FILE")
PEER_PUBKEY=$(jq -r '.peer_public_key' "$PARAMS_FILE")
PEER_PSK=$(jq -r '.peer_preshared_key // empty' "$PARAMS_FILE")
PEER_ALLOWED_IP=$(jq -r '.peer_allowed_ip' "$PARAMS_FILE")

# Параметры обфускации: значения по умолчанию 1.x + всё, что прислала панель (в т.ч. 3.1:
# S3/S4, I1-I5, HeaderProtectionKey, ContentPaddingAddition...). Только известные ключи,
# значения без переводов строк — чтобы из JSON нельзя было дописать в конфиг лишнее.
AWG_KEYS='["Jc","Jmin","Jmax","S1","S2","S3","S4","H1","H2","H3","H4","I1","I2","I3","I4","I5","HeaderProtectionKey","ContentPaddingAddition","RekeyAfterTime","RekeyTimeout","RejectAfterTime","KeepaliveTimeout","MaxHandshakeAttempts","RandomTrailers","DisableCookies"]'
OBFS_LINES=$(jq -r --argjson keys "$AWG_KEYS" '
    ({Jc:4,Jmin:40,Jmax:70,S1:0,S2:0,H1:1,H2:2,H3:3,H4:4} + (.amnezia_params // {})) as $p
    | $keys[] | select($p[.] != null)
    | "\(.) = \($p[.] | tostring | gsub("[\r\n]"; ""))"' "$PARAMS_FILE")

for v in "$IFACE" "$LISTEN_PORT" "$SERVER_PRIVKEY" "$LOCAL_ADDRESS" "$PEER_PUBKEY" "$PEER_ALLOWED_IP"; do
    [ -n "$v" ] && [ "$v" != "null" ] || { log "ERROR: не все обязательные параметры заданы"; exit 1; }
done

CONF="/etc/amnezia/${IFACE}.conf"
mkdir -p /etc/amnezia
{
    echo "# Сгенерировано панелью автоматически. Ручные правки будут перезаписаны."
    echo "[Interface]"
    echo "PrivateKey = $SERVER_PRIVKEY"
    echo "Address = $LOCAL_ADDRESS"
    echo "ListenPort = $LISTEN_PORT"
    echo "$OBFS_LINES"
    echo "PostUp = iptables -I FORWARD -i %i -j ACCEPT; iptables -I FORWARD -o %i -j ACCEPT; iptables -t nat -A POSTROUTING -o $WAN_IF -j MASQUERADE"
    echo "PostDown = iptables -D FORWARD -i %i -j ACCEPT; iptables -D FORWARD -o %i -j ACCEPT; iptables -t nat -D POSTROUTING -o $WAN_IF -j MASQUERADE"
    echo ""
    echo "[Peer]"
    echo "PublicKey = $PEER_PUBKEY"
    [ -n "$PEER_PSK" ] && [ "$PEER_PSK" != "null" ] && echo "PresharedKey = $PEER_PSK"
    echo "AllowedIPs = $PEER_ALLOWED_IP"
} > "$CONF"
chmod 600 "$CONF"

# Путь к конфигу — полный: по одному имени awg-quick ищет в /etc/amnezia/amneziawg/, а не в /etc/amnezia/.
if ip link show "$IFACE" >/dev/null 2>&1; then
    log "Интерфейс $IFACE уже поднят, перезапускаю..."
    awg-quick down "$CONF" || true
fi
awg-quick up "$CONF"

# Автозапуск после перезагрузки сервера.
UNIT="/etc/systemd/system/panel-awg-${IFACE}.service"
cat > "$UNIT" <<EOF
[Unit]
Description=AmneziaWG $IFACE (panel-managed)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$(command -v awg-quick) up $CONF
ExecStop=$(command -v awg-quick) down $CONF

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable "panel-awg-${IFACE}.service" >/dev/null 2>&1 || true

log "Включаю IP-форвардинг..."
enable_ip_forward
open_port "$LISTEN_PORT/udp"

log "Готово: $IFACE поднят на порту $LISTEN_PORT"
