# router-apply.sh — удалённое применение полной роли РОУТЕРА на не-self входном узле.
#
# Запускается панелью через Ssh::runProvisionScript (как exit-провижининг):
# _lib.sh приклеивается сверху автоматически, скрипт получает путь к JSON с
# параметрами первым аргументом. Идемпотентен, безопасно перезапускать.
#
# Делает то же, что связка entry-vps-install.sh + apply-router.sh на self-узле, но
# для удалённого роутера и получая конфиг не из фиксированных путей, а из JSON:
#   {
#     "singbox_config":  {...},                     # объект config.json
#     "rule_sets":       {"group-1": {...}, ...},    # локальные rule-set'ы
#     "amnezia_confs":   {"awg-ex1": "conf", ...},   # awg-quick конфиги
#     "interfaces_list": "awg-ex1\n...",             # какие интерфейсы поднимать
#     "awg_inbound_env": "IFACE=...\n" | "",         # вход устройств по AmneziaWG
#     "open_ports":      "443/tcp\n..."              # порты для firewall
#   }
set -euo pipefail

PARAMS="${1:?router-apply: не передан путь к JSON с параметрами}"

SINGBOX_CONFIG="/etc/sing-box/config.json"
RULESET_DIR="/etc/sing-box/rule-sets"
AMNEZIA_DIR="/etc/amnezia"
INTERFACES_LIST="$AMNEZIA_DIR/interfaces.list"
AWG_ENV="$AMNEZIA_DIR/awg-inbound.env"
PORTS_LIST="/etc/sing-box/open-ports.list"
STATE_DIR="/var/lib/panel/wg-checksums"

log() { echo "[router-apply] $*"; }

detect_os
ensure_base_tools || { log "ERROR: не удалось поставить базовые утилиты (jq)"; exit 1; }

if ! command -v jq >/dev/null 2>&1; then
    log "ERROR: jq недоступен — не могу разобрать параметры"; exit 1
fi

# --- 0. Устанавливаем sing-box (+ systemd-юнит), если ещё нет ------------
if command -v sing-box >/dev/null 2>&1 && sing-box version >/dev/null 2>&1; then
    log "sing-box уже установлен: $(sing-box version | head -1)"
else
    install_singbox /usr/local/bin/sing-box || { log "ERROR: sing-box не установлен"; exit 1; }
fi
SB=$(command -v sing-box)
if [ ! -f /etc/systemd/system/sing-box.service ] && [ ! -f /lib/systemd/system/sing-box.service ] && [ ! -f /usr/lib/systemd/system/sing-box.service ]; then
    log "Создаю systemd-юнит sing-box.service"
    cat > /etc/systemd/system/sing-box.service <<EOF
[Unit]
Description=sing-box (panel-managed router)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=$SB run -c $SINGBOX_CONFIG
Restart=on-failure
RestartSec=2
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
fi

mkdir -p /etc/sing-box "$RULESET_DIR" "$AMNEZIA_DIR" "$STATE_DIR"
systemctl enable sing-box >/dev/null 2>&1 || log "WARN: не удалось enable sing-box"

# --- 1. Пишем конфиг sing-box и rule-set'ы из JSON ----------------------
jq '.singbox_config' "$PARAMS" > "$SINGBOX_CONFIG"

# Удаляем rule-set'ы, которых больше нет, затем пишем актуальные.
declare -A KEEP=()
while IFS= read -r tag; do
    [ -z "$tag" ] && continue
    KEEP["$tag.json"]=1
    jq ".rule_sets[\"$tag\"]" "$PARAMS" > "$RULESET_DIR/$tag.json"
done < <(jq -r '.rule_sets | keys[]?' "$PARAMS")
for f in "$RULESET_DIR"/*.json; do
    [ -e "$f" ] || continue
    [ -n "${KEEP[$(basename "$f")]:-}" ] || rm -f "$f"
done

# --- 2. Пишем AmneziaWG-конфиги (туннели к exit + вход устройств) --------
have_amnezia=0
while IFS= read -r iface; do
    [ -z "$iface" ] && continue
    have_amnezia=1
    jq -r ".amnezia_confs[\"$iface\"]" "$PARAMS" > "$AMNEZIA_DIR/$iface.conf"
    chmod 600 "$AMNEZIA_DIR/$iface.conf"
done < <(jq -r '.amnezia_confs | keys[]?' "$PARAMS")

jq -r '.interfaces_list // ""' "$PARAMS" > "$INTERFACES_LIST"
jq -r '.awg_inbound_env // ""' "$PARAMS" > "$AWG_ENV"
jq -r '.open_ports // ""' "$PARAMS" > "$PORTS_LIST"

# AmneziaWG нужен только если есть хоть один awg-конфиг.
if [ "$have_amnezia" = 1 ] && ! command -v awg-quick >/dev/null 2>&1; then
    install_amneziawg || log "WARN: AmneziaWG не установлен — туннели AmneziaWG работать не будут"
fi

# --- 3. Проверяем и (пере)запускаем sing-box ----------------------------
if ! sing-box check -c "$SINGBOX_CONFIG"; then
    log "ERROR: конфиг sing-box не прошёл проверку, отменяю применение"
    exit 1
fi
systemctl restart sing-box
log "sing-box перезапущен"

# --- 4. Поднимаем/перезапускаем только изменившиеся WG-интерфейсы --------
if [ -s "$INTERFACES_LIST" ]; then
    while IFS= read -r iface; do
        [ -z "$iface" ] && continue
        conf="$AMNEZIA_DIR/$iface.conf"
        [ -f "$conf" ] || { log "WARN: $conf отсутствует, пропускаю $iface"; continue; }
        if grep -qiE '^\s*(PreUp|PostUp|PreDown|PostDown|SaveConfig)\s*=' "$conf"; then
            log "ERROR: в $conf есть Pre/PostUp — панель такое не пишет, пропускаю $iface"; continue
        fi
        new_sum=$(sha256sum "$conf" | awk '{print $1}')
        state_file="$STATE_DIR/$iface.sha256"
        old_sum=""; [ -f "$state_file" ] && old_sum=$(cat "$state_file")
        if ! ip link show "$iface" >/dev/null 2>&1; then
            log "Поднимаю интерфейс $iface"; awg-quick up "$conf"; echo "$new_sum" > "$state_file"
        elif [ "$new_sum" != "$old_sum" ]; then
            log "Конфиг $iface изменился, перезапускаю"; awg-quick down "$conf" || ip link del "$iface" 2>/dev/null || true
            awg-quick up "$conf"; echo "$new_sum" > "$state_file"
        else
            log "$iface без изменений"
        fi
    done < "$INTERFACES_LIST"
    for state_file in "$STATE_DIR"/*.sha256; do
        [ -e "$state_file" ] || continue
        iface=$(basename "$state_file" .sha256)
        if ! grep -qx "$iface" "$INTERFACES_LIST"; then
            log "Гашу удалённый интерфейс $iface"
            ip link show "$iface" >/dev/null 2>&1 && ip link del "$iface" || true
            rm -f "$state_file"
        fi
    done
fi

# --- 5. Вход устройств по AmneziaWG: NAT + заворот TCP в sing-box --------
ensure_chain() { iptables -t "$1" -N "$2" 2>/dev/null || true; iptables -t "$1" -F "$2"; iptables -t "$1" -C "$3" -j "$2" 2>/dev/null || iptables -t "$1" -I "$3" 1 -j "$2"; }
drop_chain()  { iptables -t "$1" -D "$3" -j "$2" 2>/dev/null || true; iptables -t "$1" -F "$2" 2>/dev/null || true; iptables -t "$1" -X "$2" 2>/dev/null || true; }

AWG_IFACE="" AWG_SUBNET="" AWG_REDIRECT=""
if [ -s "$AWG_ENV" ]; then
    AWG_IFACE=$(sed -n 's/^IFACE=//p' "$AWG_ENV")
    AWG_SUBNET=$(sed -n 's/^SUBNET=//p' "$AWG_ENV")
    AWG_REDIRECT=$(sed -n 's/^REDIRECT_PORT=//p' "$AWG_ENV")
    if ! [[ "$AWG_IFACE" =~ ^[a-z0-9-]{1,15}$ && "$AWG_SUBNET" =~ ^[0-9.]+/[0-9]{1,2}$ && "$AWG_REDIRECT" =~ ^[0-9]{1,5}$ ]]; then
        log "WARN: $AWG_ENV в неожиданном формате — пропускаю AmneziaWG-вход"; AWG_IFACE=""
    fi
fi
if command -v iptables >/dev/null 2>&1; then
    if [ -n "$AWG_IFACE" ]; then
        sysctl -qw net.ipv4.ip_forward=1
        ensure_chain nat PANEL-AWG-PRE PREROUTING
        ensure_chain nat PANEL-AWG-POST POSTROUTING
        ensure_chain filter PANEL-AWG-FWD FORWARD
        ensure_chain filter PANEL-AWG-IN INPUT
        iptables -t nat -A PANEL-AWG-PRE -i "$AWG_IFACE" -p tcp ! -d "$AWG_SUBNET" -j REDIRECT --to-ports "$AWG_REDIRECT"
        iptables -A PANEL-AWG-FWD -i "$AWG_IFACE" -p udp --dport 443 -j REJECT
        iptables -A PANEL-AWG-FWD -i "$AWG_IFACE" -j ACCEPT
        iptables -A PANEL-AWG-FWD -o "$AWG_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT
        iptables -t nat -A PANEL-AWG-POST -s "$AWG_SUBNET" ! -o "$AWG_IFACE" -j MASQUERADE
        iptables -A PANEL-AWG-IN -i "$AWG_IFACE" -p tcp --dport "$AWG_REDIRECT" -j ACCEPT
        iptables -A PANEL-AWG-IN -p tcp --dport "$AWG_REDIRECT" -j DROP
        log "AmneziaWG-вход настроен ($AWG_IFACE, $AWG_SUBNET)"
    else
        drop_chain nat PANEL-AWG-PRE PREROUTING
        drop_chain nat PANEL-AWG-POST POSTROUTING
        drop_chain filter PANEL-AWG-FWD FORWARD
        drop_chain filter PANEL-AWG-IN INPUT
    fi
fi

# --- 6. Открываем порты включённых протоколов в firewall ----------------
USE_UFW=0; USE_FIREWALLD=0
command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active" && USE_UFW=1
command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1 && USE_FIREWALLD=1
if [ -s "$PORTS_LIST" ] && [ "$USE_UFW" = 0 ] && [ "$USE_FIREWALLD" = 0 ] && command -v iptables >/dev/null 2>&1; then
    ensure_chain filter PANEL-PORTS INPUT
    while IFS= read -r p; do
        [[ "$p" =~ ^([0-9]{1,5})/(tcp|udp)$ ]] || continue
        iptables -A PANEL-PORTS -p "${BASH_REMATCH[2]}" --dport "${BASH_REMATCH[1]}" -j ACCEPT
        log "iptables: открыт $p"
    done < "$PORTS_LIST"
fi
if [ -s "$PORTS_LIST" ] && { [ "$USE_UFW" = 1 ] || [ "$USE_FIREWALLD" = 1 ]; }; then
    while IFS= read -r p; do
        [[ "$p" =~ ^[0-9]{1,5}/(tcp|udp)$ ]] || continue
        [ "$USE_UFW" = 1 ] && ufw allow "$p" >/dev/null && log "ufw: открыт $p"
        [ "$USE_FIREWALLD" = 1 ] && firewall-cmd --quiet --add-port="$p" && firewall-cmd --quiet --permanent --add-port="$p" && log "firewalld: открыт $p"
    done < "$PORTS_LIST"
fi

log "Готово"
