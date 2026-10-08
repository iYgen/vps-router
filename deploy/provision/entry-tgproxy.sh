#!/usr/bin/env bash
# vpsrouter tg-proxy — прокси для мессенджера Telegram на выбранном узле.
# Два независимых варианта:
#   • MTProto (mtg, github.com/9seconds/mtg) — «родной» для Telegram, FakeTLS;
#   • SOCKS5 — через standalone-инстанс sing-box (если он установлен на узле).
#
# Параметры панель кладёт в /var/lib/panel/tgproxy.conf (key=value, без jq).
# Режим — единственный аргумент из белого списка (ввод пользователя в argv не идёт).
# Каждый сервис — в своём systemd-юните и со своим правилом firewall (снимается при выкл.).
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export DEBIAN_FRONTEND=noninteractive LC_ALL=C

CONF="/var/lib/panel/tgproxy.conf"
MTG_BIN="/usr/local/sbin/mtg"
MTG_VER="v2.1.7"
MTG_TOML="/etc/vpsrouter-mtg.toml"
MTG_UNIT="/etc/systemd/system/vpsrouter-mtg.service"
SOCKS_CFG="/etc/vpsrouter-tgsocks.json"
SOCKS_UNIT="/etc/systemd/system/vpsrouter-tgsocks.service"
mkdir -p /var/lib/panel 2>/dev/null || true
log() { echo "[tgproxy] $*"; }

getconf() { [ -r "$CONF" ] && sed -n "s/^$1=//p" "$CONF" | head -1 || true; }

# --- firewall (idempotent, по комментарию-метке) ---
fw_open() {  # $1=port $2=tag
    command -v iptables >/dev/null 2>&1 || return 0
    iptables -C INPUT -p tcp --dport "$1" -m comment --comment "$2" -j ACCEPT 2>/dev/null \
        || iptables -I INPUT -p tcp --dport "$1" -m comment --comment "$2" -j ACCEPT
}
fw_close() {  # $1=tag
    command -v iptables >/dev/null 2>&1 || return 0
    while iptables -S INPUT 2>/dev/null | grep -q -- "--comment $1"; do
        rule=$(iptables -S INPUT | grep -- "--comment $1" | head -1 | sed 's/^-A /-D /')
        # shellcheck disable=SC2086
        iptables $rule || break
    done
}

install_mtg() {
    [ -x "$MTG_BIN" ] && return 0
    local arch sub tmp src
    arch=$(uname -m)
    case "$arch" in
        x86_64) sub=amd64 ;; aarch64|arm64) sub=arm64 ;;
        *) log "ERROR: неизвестная архитектура $arch"; exit 1 ;;
    esac
    tmp=$(mktemp -d)
    log "качаю mtg $MTG_VER ($sub)…"
    if ! curl -fsSL -o "$tmp/m.tgz" "https://github.com/9seconds/mtg/releases/download/${MTG_VER}/mtg-${MTG_VER#v}-linux-${sub}.tar.gz"; then
        rm -rf "$tmp"; log "ERROR: не удалось скачать mtg"; exit 1
    fi
    tar xzf "$tmp/m.tgz" -C "$tmp"
    src=$(find "$tmp" -name mtg -type f | head -1)
    [ -n "$src" ] && install -o root -g root -m 755 "$src" "$MTG_BIN" || { rm -rf "$tmp"; log "ERROR: mtg не найден в архиве"; exit 1; }
    rm -rf "$tmp"
}

mtg_up() {
    local port secret
    port=$(getconf mtg_port); case "$port" in ''|*[!0-9]*) port=8443;; esac
    secret=$(getconf mtg_secret)
    # секрет строго hex (ee + 16 байт + домен) — панель его и генерит; тут только валидируем
    printf '%s' "$secret" | grep -qE '^[0-9a-fA-F]{34,}$' || { log "ERROR: пустой/битый secret"; exit 1; }
    install_mtg
    printf 'secret = "%s"\nbind-to = "0.0.0.0:%s"\n' "$secret" "$port" > "$MTG_TOML"
    cat > "$MTG_UNIT" <<EOF
[Unit]
Description=vpsrouter mtg (Telegram MTProto proxy)
After=network.target
[Service]
ExecStart=$MTG_BIN run $MTG_TOML
Restart=always
RestartSec=3
DynamicUser=yes
AmbientCapabilities=CAP_NET_BIND_SERVICE
[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable vpsrouter-mtg >/dev/null 2>&1 || true
    systemctl restart vpsrouter-mtg
    fw_open "$port" vpsr_mtg
    log "MTProto включён на tcp/$port."
}

mtg_down() {
    systemctl stop vpsrouter-mtg 2>/dev/null || true
    systemctl disable vpsrouter-mtg 2>/dev/null || true
    rm -f "$MTG_UNIT" "$MTG_TOML"; systemctl daemon-reload 2>/dev/null || true
    fw_close vpsr_mtg
    log "MTProto выключен."
}

socks_up() {
    local port user pass sb
    port=$(getconf socks_port); case "$port" in ''|*[!0-9]*) port=1080;; esac
    user=$(getconf socks_user); pass=$(getconf socks_pass)
    sb=$(command -v sing-box || true)
    [ -n "$sb" ] || { log "ERROR: sing-box не установлен на этом узле — SOCKS недоступен"; exit 1; }
    # минимальный standalone socks-inbound (с логином/паролем, иначе открытый прокси)
    cat > "$SOCKS_CFG" <<EOF
{ "log": { "level": "warn" },
  "inbounds": [ { "type": "socks", "listen": "0.0.0.0", "listen_port": $port,
    "users": [ { "username": "$user", "password": "$pass" } ] } ],
  "outbounds": [ { "type": "direct" } ] }
EOF
    cat > "$SOCKS_UNIT" <<EOF
[Unit]
Description=vpsrouter tg SOCKS5 (sing-box)
After=network.target
[Service]
ExecStart=$sb run -c $SOCKS_CFG
Restart=always
RestartSec=3
[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable vpsrouter-tgsocks >/dev/null 2>&1 || true
    systemctl restart vpsrouter-tgsocks
    fw_open "$port" vpsr_tgsocks
    log "SOCKS5 включён на tcp/$port."
}

socks_down() {
    systemctl stop vpsrouter-tgsocks 2>/dev/null || true
    systemctl disable vpsrouter-tgsocks 2>/dev/null || true
    rm -f "$SOCKS_UNIT" "$SOCKS_CFG"; systemctl daemon-reload 2>/dev/null || true
    fw_close vpsr_tgsocks
    log "SOCKS5 выключен."
}

status() {
    local mp sp
    mp=$(getconf mtg_port); sp=$(getconf socks_port)
    echo "mtg_active=$(systemctl is-active vpsrouter-mtg 2>/dev/null || echo inactive)"
    echo "mtg_port=${mp:-}"
    echo "socks_active=$(systemctl is-active vpsrouter-tgsocks 2>/dev/null || echo inactive)"
    echo "socks_port=${sp:-}"
    echo "singbox=$(command -v sing-box >/dev/null 2>&1 && echo yes || echo no)"
}

case "${1:-status}" in
    mtg-up)    mtg_up ;;
    mtg-down)  mtg_down ;;
    socks-up)  socks_up ;;
    socks-down) socks_down ;;
    status)    status ;;
    *) echo "usage: $0 {mtg-up|mtg-down|socks-up|socks-down|status}" >&2; exit 2 ;;
esac
