#!/usr/bin/env bash
# Единственная привилегированная операция, доступная панели (www-data) через sudo.
# Не принимает аргументов, ничего не читает из окружения вызывающего —
# вся конфигурация уже записана панелью в фиксированные пути ниже.
#
# Установка: скопировать в /usr/local/sbin/apply-router.sh, chown root:root, chmod 750.

set -euo pipefail

SINGBOX_CONFIG="/etc/sing-box/config.json"
AMNEZIA_DIR="/etc/amnezia"
INTERFACES_LIST="/etc/amnezia/interfaces.list"
STATE_DIR="/var/lib/panel/wg-checksums"

log() { echo "[apply-router] $*"; }

mkdir -p "$STATE_DIR"

# --- 1. Проверяем и перезапускаем sing-box -------------------------------
if [ ! -f "$SINGBOX_CONFIG" ]; then
    log "ERROR: $SINGBOX_CONFIG не найден"
    exit 1
fi

if ! sing-box check -c "$SINGBOX_CONFIG"; then
    log "ERROR: конфиг sing-box не прошёл проверку, отменяю применение"
    exit 1
fi
# Типовой юнит запускает sing-box с -C <каталог> — он читает ВСЕ *.json рядом.
# Посторонний .json в каталоге уронит сервис, хотя основной конфиг валиден.
if systemctl cat sing-box 2>/dev/null | grep -q -- '-C '; then
    if ! sing-box check -C "$(dirname "$SINGBOX_CONFIG")"; then
        log "ERROR: каталог $(dirname "$SINGBOX_CONFIG") целиком не проходит проверку (лишний *.json?), отменяю применение"
        exit 1
    fi
fi

systemctl restart sing-box
sleep 2
if ! systemctl is-active --quiet sing-box; then
    log "ERROR: sing-box не поднялся после перезапуска: $(journalctl -u sing-box -n 3 --no-pager -o cat 2>/dev/null | tr '\n' ' ')"
    exit 1
fi
log "sing-box перезапущен"

# --- 2. Поднимаем/перезапускаем только изменившиеся WG-интерфейсы --------
if [ -f "$INTERFACES_LIST" ]; then
    while IFS= read -r iface; do
        [ -z "$iface" ] && continue
        conf="$AMNEZIA_DIR/$iface.conf"
        if [ ! -f "$conf" ]; then
            log "WARN: $conf отсутствует, пропускаю $iface"
            continue
        fi

        # PostUp/PreUp/… выполняют команды от root, а файлы пишет www-data/panel.
        # Панель их никогда не генерирует — их появление значит подмену файла.
        if grep -qiE '^\s*(PreUp|PostUp|PreDown|PostDown|SaveConfig)\s*=' "$conf"; then
            log "ERROR: в $conf есть PreUp/PostUp/PreDown/PostDown/SaveConfig — панель такое не пишет, пропускаю $iface"
            continue
        fi

        new_sum=$(sha256sum "$conf" | awk '{print $1}')
        state_file="$STATE_DIR/$iface.sha256"
        old_sum=""
        [ -f "$state_file" ] && old_sum=$(cat "$state_file")

        # awg-quick получает ПОЛНЫЙ путь: по одному имени он ищет конфиг в
        # /etc/amnezia/amneziawg/, а панель пишет в /etc/amnezia/.
        if ! ip link show "$iface" >/dev/null 2>&1; then
            log "Поднимаю новый интерфейс $iface"
            awg-quick up "$conf"
            echo "$new_sum" > "$state_file"
        elif [ "$new_sum" != "$old_sum" ]; then
            log "Конфиг $iface изменился, перезапускаю интерфейс"
            awg-quick down "$conf" || ip link del "$iface" 2>/dev/null || true
            awg-quick up "$conf"
            echo "$new_sum" > "$state_file"
        else
            log "$iface без изменений, не трогаю"
        fi
    done < "$INTERFACES_LIST"

    # Гасим интерфейсы, которых больше нет в списке (сервер удалили из панели).
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

# --- 3. Вход устройств по AmneziaWG: NAT + заворот TCP в sing-box ----------
# Панель пишет awg-inbound.env только когда вход по AmneziaWG включён.
# Все правила — в собственных цепочках PANEL-AWG-*, пересобираются целиком
# при каждом apply (идемпотентно), чужие правила iptables не трогаются.
AWG_ENV="$AMNEZIA_DIR/awg-inbound.env"
ensure_chain() { # table chain parent
    iptables -t "$1" -N "$2" 2>/dev/null || true
    iptables -t "$1" -F "$2"
    iptables -t "$1" -C "$3" -j "$2" 2>/dev/null || iptables -t "$1" -I "$3" 1 -j "$2"
}
drop_chain() { # table chain parent
    iptables -t "$1" -D "$3" -j "$2" 2>/dev/null || true
    iptables -t "$1" -F "$2" 2>/dev/null || true
    iptables -t "$1" -X "$2" 2>/dev/null || true
}

AWG_IFACE="" AWG_SUBNET="" AWG_REDIRECT=""
if [ -s "$AWG_ENV" ]; then
    AWG_IFACE=$(sed -n 's/^IFACE=//p' "$AWG_ENV")
    AWG_SUBNET=$(sed -n 's/^SUBNET=//p' "$AWG_ENV")
    AWG_REDIRECT=$(sed -n 's/^REDIRECT_PORT=//p' "$AWG_ENV")
    # Файл пишет www-data — принимаем только строго ожидаемый формат.
    if ! [[ "$AWG_IFACE" =~ ^[a-z0-9-]{1,15}$ && "$AWG_SUBNET" =~ ^[0-9.]+/[0-9]{1,2}$ && "$AWG_REDIRECT" =~ ^[0-9]{1,5}$ ]]; then
        log "WARN: $AWG_ENV в неожиданном формате — пропускаю настройку AmneziaWG-входа"
        AWG_IFACE=""
    fi
fi

if command -v iptables >/dev/null 2>&1; then
    if [ -n "$AWG_IFACE" ]; then
        sysctl -qw net.ipv4.ip_forward=1
        ensure_chain nat PANEL-AWG-PRE PREROUTING
        ensure_chain nat PANEL-AWG-POST POSTROUTING
        ensure_chain filter PANEL-AWG-FWD FORWARD
        ensure_chain filter PANEL-AWG-IN INPUT
        # TCP устройств -> sing-box (там решается: exit-сервер или напрямую).
        iptables -t nat -A PANEL-AWG-PRE -i "$AWG_IFACE" -p tcp ! -d "$AWG_SUBNET" -j REDIRECT --to-ports "$AWG_REDIRECT"
        # QUIC (UDP/443) режем, чтобы YouTube и т.п. откатились на TCP и прошли через правила sing-box.
        iptables -A PANEL-AWG-FWD -i "$AWG_IFACE" -p udp --dport 443 -j REJECT
        iptables -A PANEL-AWG-FWD -i "$AWG_IFACE" -j ACCEPT
        iptables -A PANEL-AWG-FWD -o "$AWG_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT
        iptables -t nat -A PANEL-AWG-POST -s "$AWG_SUBNET" ! -o "$AWG_IFACE" -j MASQUERADE
        # redirect-порт sing-box доступен только из туннеля.
        iptables -A PANEL-AWG-IN -i "$AWG_IFACE" -p tcp --dport "$AWG_REDIRECT" -j ACCEPT
        iptables -A PANEL-AWG-IN -p tcp --dport "$AWG_REDIRECT" -j DROP
        log "AmneziaWG-вход: NAT и заворот TCP в sing-box настроены ($AWG_IFACE, $AWG_SUBNET)"
    else
        drop_chain nat PANEL-AWG-PRE PREROUTING
        drop_chain nat PANEL-AWG-POST POSTROUTING
        drop_chain filter PANEL-AWG-FWD FORWARD
        drop_chain filter PANEL-AWG-IN INPUT
    fi
fi

# --- 3b. Вход VLESS+XHTTP (Xray за nginx сайта) ----------------------------
# Панель пишет xray-xhttp.conf (JSON) (пусто = выключено) и xhttp.env (путь + loopback-порт).
# Xray ставится отдельно (deploy/router/xhttp-install.sh): юнит xray-xhttp, юзер
# xray-xhttp, snippet /etc/nginx/snippets/vpsrouter-xhttp.conf, подключённый в
# server{} сайта. Без установленного Xray шаг молча пропускается.
XRAY_SRC="$(dirname "$SINGBOX_CONFIG")/xray-xhttp.conf"  # не .json: sing-box запущен с -C <каталог> и читает все *.json
XHTTP_ENV="$(dirname "$SINGBOX_CONFIG")/xhttp.env"
XRAY_DIR=/etc/xray-xhttp
XHTTP_SNIPPET=/etc/nginx/snippets/vpsrouter-xhttp.conf
if command -v xray >/dev/null 2>&1 && id xray-xhttp >/dev/null 2>&1 && [ -d "$XRAY_DIR" ]; then
    XH_PATH="" XH_PORT=""
    if [ -s "$XHTTP_ENV" ]; then
        XH_PATH=$(sed -n 's/^PATH_PREFIX=//p' "$XHTTP_ENV")
        XH_PORT=$(sed -n 's/^LOCAL_PORT=//p' "$XHTTP_ENV")
        # Файл пишет веб-пользователь — принимаем только строго ожидаемый формат.
        if ! [[ "$XH_PATH" =~ ^/[A-Za-z0-9_-]{6,64}$ && "$XH_PORT" =~ ^[0-9]{2,5}$ ]]; then
            log "WARN: $XHTTP_ENV в неожиданном формате — XHTTP выключаю"
            XH_PATH=""
        fi
    fi
    if [ -n "$XH_PATH" ] && [ -s "$XRAY_SRC" ]; then
        install -o root -g xray-xhttp -m 640 "$XRAY_SRC" "$XRAY_DIR/config.new.json"
        if runuser -u xray-xhttp -- xray run -test -c "$XRAY_DIR/config.new.json" >/dev/null 2>&1; then
            if ! cmp -s "$XRAY_DIR/config.new.json" "$XRAY_DIR/config.json" || ! systemctl is-active --quiet xray-xhttp; then
                mv -f "$XRAY_DIR/config.new.json" "$XRAY_DIR/config.json"
                systemctl enable xray-xhttp >/dev/null 2>&1 || true
                systemctl restart xray-xhttp
                log "xray-xhttp перезапущен"
            else
                rm -f "$XRAY_DIR/config.new.json"
            fi
        else
            rm -f "$XRAY_DIR/config.new.json"
            log "ERROR: конфиг Xray не прошёл проверку — XHTTP оставлен как был"
        fi
        SNIP="# managed by vps-router apply-router.sh — do not edit
location ^~ $XH_PATH {
    client_max_body_size 0;
    client_body_timeout 5m;
    grpc_pass grpc://127.0.0.1:$XH_PORT;
    grpc_set_header Host \$host;
    grpc_set_header X-Real-IP \$remote_addr;
    grpc_read_timeout 1h;
    grpc_send_timeout 1h;
    grpc_socket_keepalive on;
    access_log off;
}"
    else
        systemctl disable --now xray-xhttp >/dev/null 2>&1 || true
        SNIP="# managed by vps-router apply-router.sh — XHTTP disabled"
    fi
    if [ "$(cat "$XHTTP_SNIPPET" 2>/dev/null)" != "$SNIP" ]; then
        [ -f "$XHTTP_SNIPPET" ] && cp -f "$XHTTP_SNIPPET" "$XHTTP_SNIPPET.bak"
        printf '%s\n' "$SNIP" > "$XHTTP_SNIPPET"
        if nginx -t >/dev/null 2>&1; then
            systemctl reload nginx && log "nginx: XHTTP location обновлён"
        else
            log "ERROR: nginx -t не прошёл — возвращаю прежний snippet"
            [ -f "$XHTTP_SNIPPET.bak" ] && mv -f "$XHTTP_SNIPPET.bak" "$XHTTP_SNIPPET"
        fi
    fi
fi

# --- 4. Открываем порты включённых протоколов в firewall -------------------
PORTS_LIST="$(dirname "$SINGBOX_CONFIG")/open-ports.list"
USE_UFW=0; USE_FIREWALLD=0
command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active" && USE_UFW=1
command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1 && USE_FIREWALLD=1
if [ -f "$PORTS_LIST" ] && [ "$USE_UFW" = 0 ] && [ "$USE_FIREWALLD" = 0 ] && command -v iptables >/dev/null 2>&1; then
    # Чистый iptables (ISPmanager, ручные правила с финальным REJECT) —
    # своя цепочка PANEL-PORTS первой в INPUT, пересобирается при каждом apply.
    ensure_chain filter PANEL-PORTS INPUT
    while IFS= read -r p; do
        [[ "$p" =~ ^([0-9]{1,5})/(tcp|udp)$ ]] || continue
        iptables -A PANEL-PORTS -p "${BASH_REMATCH[2]}" --dport "${BASH_REMATCH[1]}" -j ACCEPT
        log "iptables: открыт $p"
    done < "$PORTS_LIST"
fi
if [ -f "$PORTS_LIST" ] && { [ "$USE_UFW" = 1 ] || [ "$USE_FIREWALLD" = 1 ]; }; then
    while IFS= read -r p; do
        [ -z "$p" ] && continue
        if ! [[ "$p" =~ ^[0-9]{1,5}/(tcp|udp)$ ]]; then
            log "WARN: пропускаю строку '$p' в $PORTS_LIST"
            continue
        fi
        if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
            ufw allow "$p" >/dev/null && log "ufw: открыт $p"
        fi
        if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
            firewall-cmd --quiet --add-port="$p" && firewall-cmd --quiet --permanent --add-port="$p" && log "firewalld: открыт $p"
        fi
    done < "$PORTS_LIST"
fi

log "Готово"
