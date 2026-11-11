# Ретранслятор (relay): «лёгкий» узел, который только пересылает порты входа
# на роутер (входной VPS). Никаких VPN-программ — одно ядро Linux: nftables DNAT
# + masquerade. Шифрование идёт насквозь от устройства до роутера, ретранслятор
# видит только TCP/UDP-поток.
#
#   устройство → relay:PORT  ──DNAT──▶  target_ip:PORT (роутер)
#
# Зачем: адрес ретранслятора (например, облако с «белым» IP) может быть доступен
# там, где адрес роутера недоступен.
#
# Параметры (JSON, $1):
#   target_ip — IPv4 роутера
#   ports     — [{"port":443,"proto":"tcp"}, ...] — порты входов роутера
#   ssh_port  — SSH этого узла (никогда не пересылается)
#   log_new   — true: писать новые подключения в журнал ядра (journalctl -k -g vpsr-relay)
#
# Идемпотентно: таблица inet vpsr_relay пересоздаётся целиком. Правила
# переживают перезагрузку через /etc/vpsr-relay.nft + vpsr-relay.service.
# Пустой ports = выключить пересылку (таблица удаляется).

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[relay] $*"; }

detect_os
ensure_cmd jq jq || { log "ERROR: не удалось установить jq"; exit 1; }
ensure_cmd nft nftables || { log "ERROR: не удалось установить nftables"; exit 1; }

TARGET=$(jq -r '.target_ip // ""' "$PARAMS_FILE")
SSH_PORT=$(jq -r '.ssh_port // 22' "$PARAMS_FILE")
LOG_NEW=$(jq -r '.log_new // true' "$PARAMS_FILE")
mapfile -t PORTS < <(jq -r '.ports[]? | "\(.port)/\(.proto)"' "$PARAMS_FILE")

[[ "$TARGET" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || { log "ERROR: target_ip должен быть IPv4: '$TARGET'"; exit 1; }
[[ "$SSH_PORT" =~ ^[0-9]+$ ]] || { log "ERROR: bad ssh_port"; exit 1; }

# Свой адрес на пути к роутеру — защита от петли (ретранслятор сам на себя).
SELF_IPS=$(ip -4 -o addr show | awk '{split($4,a,"/"); print a[1]}')
if echo "$SELF_IPS" | grep -qx "$TARGET"; then
    log "ERROR: target_ip $TARGET — адрес самого ретранслятора"; exit 1
fi

TCP=() UDP=()
for p in "${PORTS[@]}"; do
    port=${p%/*} proto=${p#*/}
    [[ "$port" =~ ^[0-9]+$ ]] && [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || { log "ERROR: bad port '$p'"; exit 1; }
    case "$proto" in
        tcp) [ "$port" = "$SSH_PORT" ] && { log "ERROR: порт $port/tcp = SSH ретранслятора — переслать нельзя (смените SSH-порт узла)"; exit 1; }
             TCP+=("$port") ;;
        udp) UDP+=("$port") ;;
        *) log "ERROR: bad proto '$p'"; exit 1 ;;
    esac
done

CONF=/etc/vpsr-relay.nft
UNIT=/etc/systemd/system/vpsr-relay.service

if [ "${#TCP[@]}" -eq 0 ] && [ "${#UDP[@]}" -eq 0 ]; then
    nft delete table inet vpsr_relay 2>/dev/null || true
    systemctl disable --now vpsr-relay >/dev/null 2>&1 || true
    rm -f "$CONF"
    log "Пересылка выключена (нет портов)"
    exit 0
fi

enable_ip_forward

join() { local IFS=,; echo "$*"; }
LOGRULE=""
[ "$LOG_NEW" = "true" ] && LOGRULE='ct state new limit rate 30/second log prefix "vpsr-relay: " level info'

{
    echo "# managed by vps-router (relay-forward.sh) — do not edit"
    echo "table inet vpsr_relay"
    echo "delete table inet vpsr_relay"
    echo "table inet vpsr_relay {"
    echo "  chain prerouting {"
    echo "    type nat hook prerouting priority dstnat; policy accept;"
    if [ "${#TCP[@]}" -gt 0 ]; then
        [ -n "$LOGRULE" ] && echo "    tcp dport { $(join "${TCP[@]}") } $LOGRULE"
        echo "    ip protocol tcp tcp dport { $(join "${TCP[@]}") } dnat ip to $TARGET"
    fi
    if [ "${#UDP[@]}" -gt 0 ]; then
        [ -n "$LOGRULE" ] && echo "    udp dport { $(join "${UDP[@]}") } $LOGRULE"
        echo "    ip protocol udp udp dport { $(join "${UDP[@]}") } dnat ip to $TARGET"
    fi
    echo "  }"
    echo "  chain postrouting {"
    echo "    type nat hook postrouting priority srcnat; policy accept;"
    echo "    ip daddr $TARGET ct status dnat masquerade"
    echo "  }"
    echo "  chain forward {"
    echo "    type filter hook forward priority filter - 5; policy accept;"
    echo "    ct status dnat ip daddr $TARGET accept"
    echo "  }"
    echo "}"
} > "$CONF.new"

nft -c -f "$CONF.new" || { log "ERROR: nft отверг правила"; rm -f "$CONF.new"; exit 1; }
mv -f "$CONF.new" "$CONF"
nft -f "$CONF"

cat > "$UNIT" <<UNITEOF
[Unit]
Description=vps-router relay port forwarding (nftables)
After=network-online.target nftables.service
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f $CONF
ExecStop=/usr/sbin/nft delete table inet vpsr_relay

[Install]
WantedBy=multi-user.target
UNITEOF
systemctl daemon-reload
systemctl enable vpsr-relay >/dev/null 2>&1 || true

# ufw по умолчанию режет транзитный трафик — разрешаем ровно пересылаемое.
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    for p in "${TCP[@]}"; do ufw allow "$p/tcp" >/dev/null; ufw route allow proto tcp to "$TARGET" port "$p" >/dev/null; done
    for p in "${UDP[@]}"; do ufw allow "$p/udp" >/dev/null; ufw route allow proto udp to "$TARGET" port "$p" >/dev/null; done
    log "ufw: открыты порты и транзит к $TARGET"
fi
if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    log "WARN: активен firewalld — проверьте, что он пропускает транзит (masquerade/forward)"
fi

log "Пересылка: tcp [${TCP[*]:-}] udp [${UDP[*]:-}] → $TARGET"
log "Не забудьте открыть эти порты во внешнем файрволе облака (группы безопасности)."
