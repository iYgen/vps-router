#!/usr/bin/env bash
# vpsrouter zapret / nfqws — пакетный DPI-десинк на входной роутере (self).
# Вставляет «фейковые/разбитые» пакеты в TLS-хендшейк вход→exit, чтобы Active Blocking System не
# смог подрезать соединение. Это НЕ sing-box — работает на уровне пакетов через
# NFQUEUE, на любой трафик к указанным exit-серверам.
#
# БЕЗОПАСНОСТЬ:
#   • NFQUEUE с --queue-bypass: если nfqws не запущен — пакеты ПРОХОДЯТ (egress
#     не ломается никогда);
#   • правило матчит ТОЛЬКО tcp/PORT к IP exit-серверов (TARGETS) — SSH и прочий
#     трафик (включая собственный HTTPS панели) не затрагиваются;
#   • всё в отдельной mangle-цепочке с пометкой-комментом, снимается полностью;
#   • стратегия — из белого списка (в аргументы nfqws не попадает пользовательский ввод).
#
# Запускается панелью без аргументов через sudo; параметры читает из
# /var/lib/panel/zapret.conf (JSON): {action, strategy, qnum, port, targets:[ip,...]}.
set -euo pipefail
# sudo часто даёт урезанный PATH (secure_path) — фиксируем, чтобы находить утилиты.
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

CONF="/var/lib/panel/zapret.conf"
STATE="/var/lib/panel/zapret.state"
BIN="/usr/local/sbin/vpsrouter-nfqws"
UNIT="/etc/systemd/system/vpsrouter-nfqws.service"
ZAPRET_VER="v71.2"
log() { echo "[zapret] $*"; }

# Конфиг — простой key=value (без jq: он может отсутствовать на входном узле).
# targets= список IP через пробел.
[ -r "$CONF" ] || { log "ERROR: нет $CONF"; exit 1; }
getconf() { sed -n "s/^$1=//p" "$CONF" | head -1; }
ACTION=$(getconf action); [ -n "$ACTION" ] || ACTION=status
STRATEGY=$(getconf strategy); [ -n "$STRATEGY" ] || STRATEGY=fakesplit
QNUM=$(getconf qnum); case "$QNUM" in ''|*[!0-9]*) QNUM=200;; esac
PORT=$(getconf port); case "$PORT" in ''|*[!0-9]*) PORT=443;; esac
TARGETS=$(getconf targets)
TAG="vpsr_zapret"

remove_rules() {
    # Удаляем все наши правила (по комментарию) из mangle OUTPUT.
    while iptables -t mangle -S OUTPUT 2>/dev/null | grep -q -- "--comment $TAG"; do
        rule=$(iptables -t mangle -S OUTPUT | grep -- "--comment $TAG" | head -1 | sed 's/^-A /-D /')
        # shellcheck disable=SC2086
        iptables -t mangle $rule || break
    done
}

stop_service() {
    systemctl stop vpsrouter-nfqws 2>/dev/null || true
    systemctl disable vpsrouter-nfqws 2>/dev/null || true
}

# Переустановка ТОЛЬКО iptables-правил из конфига (цели/порт). Используется и при
# install, и как ExecStartPre юнита — чтобы правило переживало перезагрузку/reload
# firewalld (на каждом старте службы правило ставится заново).
apply_rules() {
    remove_rules
    _n=0
    for ip in $TARGETS; do
        if printf '%s' "$ip" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$'; then
            iptables -t mangle -A OUTPUT -p tcp -d "$ip" --dport "$PORT" \
                -m comment --comment "$TAG" -j NFQUEUE --queue-num "$QNUM" --queue-bypass
            _n=$((_n+1))
        fi
    done
    echo "$_n"
}

# Лёгкий режим для systemd ExecStartPre: только правила, без службы/скачивания.
if [ "${1:-}" = "rules" ]; then
    apply_rules >/dev/null
    log "правила переустановлены (boot/restart)."
    exit 0
fi

if [ "$ACTION" = "remove" ]; then
    stop_service
    remove_rules
    rm -f "$UNIT"; systemctl daemon-reload 2>/dev/null || true
    echo "enabled=0" > "$STATE"
    log "выключено: правила сняты, сервис остановлен (egress не затронут)."
    exit 0
fi

# --- install ---
command -v iptables >/dev/null 2>&1 || { log "ERROR: iptables не найден"; exit 1; }

# nfqws: ставим статический бинарь из релиза zapret, если ещё нет.
if [ ! -x "$BIN" ]; then
    arch=$(uname -m)
    case "$arch" in
        x86_64) sub=linux-x86_64 ;;
        aarch64|arm64) sub=linux-arm64 ;;
        armv7l|armhf) sub=linux-arm ;;
        *) log "ERROR: неизвестная архитектура $arch — nfqws не поставить автоматически"; exit 1 ;;
    esac
    tmp=$(mktemp -d)
    log "качаю nfqws ($sub) из zapret $ZAPRET_VER..."
    if ! curl -fsSL -o "$tmp/z.tgz" "https://github.com/bol-van/zapret/releases/download/${ZAPRET_VER}/zapret-${ZAPRET_VER}.tar.gz"; then
        rm -rf "$tmp"; log "ERROR: не удалось скачать zapret"; exit 1
    fi
    tar xzf "$tmp/z.tgz" -C "$tmp"
    src=$(find "$tmp" -path "*/binaries/$sub/nfqws" | head -1)
    [ -n "$src" ] || { rm -rf "$tmp"; log "ERROR: nfqws для $sub не найден в релизе"; exit 1; }
    install -o root -g root -m 755 "$src" "$BIN"
    rm -rf "$tmp"
fi

# Стратегия — строго из белого списка (никакого ввода в argv nfqws).
case "$STRATEGY" in
    fake)     DESYNC="--dpi-desync=fake --dpi-desync-ttl=6 --dpi-desync-fooling=badsum" ;;
    disorder) DESYNC="--dpi-desync=disorder2 --dpi-desync-fooling=badsum" ;;
    split)    DESYNC="--dpi-desync=split2" ;;
    *)        STRATEGY=fakesplit; DESYNC="--dpi-desync=fake,split2 --dpi-desync-ttl=6 --dpi-desync-fooling=badsum" ;;
esac

# ExecStartPre переустанавливает iptables-правило при КАЖДОМ старте службы
# (в т.ч. после перезагрузки сервера) — так правило переживает reboot. '-' =
# не фейлить старт, если правило не добавилось.
cat > "$UNIT" <<EOF
[Unit]
Description=vpsrouter nfqws (DPI desync)
After=network.target firewalld.service
[Service]
Type=simple
ExecStartPre=-/usr/local/sbin/vpsrouter-zapret.sh rules
ExecStart=$BIN --qnum=$QNUM $DESYNC
Restart=on-failure
RestartSec=2
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable vpsrouter-nfqws >/dev/null 2>&1 || true
systemctl restart vpsrouter-nfqws

# Правила: ТОЛЬКО к exit-IP на tcp/PORT, с --queue-bypass (без демона — пропуск).
ntargets=$(apply_rules)

echo "enabled=1 strategy=$STRATEGY targets=$ntargets" > "$STATE"
log "включено: стратегия=$STRATEGY, цель(ей)=$ntargets на tcp/$PORT (NFQUEUE $QNUM, bypass)."
if [ "$ntargets" = 0 ]; then
    log "ВНИМАНИЕ: не задано ни одного exit-IP — десинк ни на что не применён."
fi
