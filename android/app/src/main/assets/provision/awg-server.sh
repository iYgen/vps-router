# AmneziaWG-сервер, которым управляет Android-приложение VPS Router (автономный режим —
# когда панель недоступна или её нет). Отдельный интерфейс (по умолчанию awg-vpsr),
# не трогает другие туннели/докеры Amnezia на сервере.
#
# Перед этим файлом приклеивается _lib.sh из vps_router (detect_os, install_amneziawg, ...).
#
# Параметры (JSON, $1):
#   iface   — имя интерфейса (awg-vpsr)
#   port    — UDP-порт
#   conf    — полный текст конфига awg-quick; __WAN_IF__ заменяется на интерфейс в интернет
#   remove  — true: остановить и удалить интерфейс
#
# Идемпотентно: конфиг перезаписывается целиком; если интерфейс уже поднят —
# пиры применяются через `awg syncconf` без разрыва активных клиентов.

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[awg-server] $*"; }

detect_os
ensure_base_tools || exit 1

IFACE=$(jq -r '.iface // "awg-vpsr"' "$PARAMS_FILE")
PORT=$(jq -r '.port' "$PARAMS_FILE")
REMOVE=$(jq -r '.remove // false' "$PARAMS_FILE")
[[ "$IFACE" =~ ^[a-zA-Z0-9_-]{1,15}$ ]] || { log "ERROR: bad iface '$IFACE'"; exit 1; }

CONF="/etc/amnezia/${IFACE}.conf"
UNIT="vpsr-awg-${IFACE}.service"

if [ "$REMOVE" = "true" ]; then
    systemctl disable --now "$UNIT" >/dev/null 2>&1 || true
    if ip link show "$IFACE" >/dev/null 2>&1; then awg-quick down "$CONF" || ip link del "$IFACE" || true; fi
    rm -f "$CONF" "/etc/systemd/system/$UNIT"
    systemctl daemon-reload || true
    log "Интерфейс $IFACE удалён"
    exit 0
fi

[[ "$PORT" =~ ^[0-9]+$ ]] || { log "ERROR: bad port '$PORT'"; exit 1; }

install_amneziawg || exit 1
WAN_IF=$(default_iface)
log "Интерфейс в интернет: $WAN_IF"

mkdir -p /etc/amnezia
umask 077
# Секция [Interface] (ключ, порт, параметры обфускации): awg syncconf не убирает из неё
# параметры, поэтому при её изменении (например, переход на AmneziaWG 3.1) интерфейс перезапускается.
iface_section() { [ -f "$1" ] && sed '/^\[Peer\]/,$d' "$1" | grep -v "^#" | md5sum | cut -d" " -f1; }
OLD_IFACE=$(iface_section "$CONF" || true)
jq -r '.conf' "$PARAMS_FILE" | sed "s/__WAN_IF__/$WAN_IF/g" > "$CONF.new"
chmod 600 "$CONF.new"
mv -f "$CONF.new" "$CONF"

enable_ip_forward

if ip link show "$IFACE" >/dev/null 2>&1 && [ -n "$OLD_IFACE" ] && [ "$OLD_IFACE" != "$(iface_section "$CONF")" ]; then
    log "Параметры интерфейса изменились — перезапускаю $IFACE"
    awg-quick down "$CONF" 2>/dev/null || ip link del "$IFACE" 2>/dev/null || true
    awg-quick up "$CONF"
elif ip link show "$IFACE" >/dev/null 2>&1; then
    log "Интерфейс $IFACE уже поднят — применяю пиров без разрыва (awg syncconf)"
    TMP=$(mktemp)
    awg-quick strip "$CONF" > "$TMP"
    if ! awg syncconf "$IFACE" "$TMP"; then
        log "syncconf не прошёл — перезапускаю интерфейс"
        awg-quick down "$CONF" || true
        awg-quick up "$CONF"
    fi
    rm -f "$TMP"
else
    awg-quick up "$CONF"
fi

cat > "/etc/systemd/system/$UNIT" <<EOF
[Unit]
Description=AmneziaWG $IFACE (VPS Router Android)
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
systemctl enable "$UNIT" >/dev/null 2>&1 || true

open_port "$PORT/udp"

PEERS=$(awg show "$IFACE" peers 2>/dev/null | wc -l || echo 0)
if grep -q "^HeaderProtectionKey" "$CONF"; then PROFILE="3.1"; else PROFILE="1.x"; fi
log "Готово: $IFACE слушает UDP $PORT, пиров: $PEERS, профиль AmneziaWG $PROFILE ($(awg --version 2>/dev/null | awk '{print $2}'))"
log "Не забудьте открыть UDP $PORT во внешнем файрволе хостера (группы безопасности), если он есть."
