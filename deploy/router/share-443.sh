#!/usr/bin/env bash
# vps_router — разделение порта 443 между сайтами и VPN (Reality) на ОДНОМ IP.
#
# Идея: перед nginx/сайтами ставится L4-демукс (nginx stream + ssl_preread),
# который по SNI (имя домена в TLS, идёт открытым текстом) раскидывает 443:
#   SNI = VPN-домен      -> sing-box (Reality), внутренний порт
#   всё остальное (сайты) -> nginx http, внутренний порт (127.0.0.1:8443)
# TLS при этом НЕ терминируется на фронте — сайты как терминировали сами, так и
# продолжают (свои сертификаты сохраняются).
#
# БЕЗОПАСНОСТЬ: по умолчанию НИЧЕГО не меняет (dry-run). Меняет только с --apply,
# и то: полный бэкап /etc/nginx -> nginx -t -> reload -> health-check каждого
# домена -> АВТО-ОТКАТ при любом сбое. Кратковременный downtime возможен; потери
# конфигов — нет (бэкап один-в-один).
#
# Использование:
#   sudo bash share-443.sh                      # только показать план (dry-run)
#   sudo bash share-443.sh --apply --vpn-sni vpn.example.com
#
# Флаги:
#   --apply                 реально применить (иначе dry-run)
#   --vpn-sni <домен>       SNI, по которому трафик уходит в sing-box (обязателен для --apply)
#   --singbox-port <порт>   внутренний порт sing-box Reality (по умолчанию 11443)
#   --backend-port <порт>   внутренний порт nginx для сайтов (по умолчанию 8443)
set -u

APPLY=0; VPN_SNI=""; SB_PORT=11443; BE_PORT=8443
# TLS-мультиплекс (VLESS-WS/gRPC на одном 443 за терминирующим TLS-фронтом).
TLS_MUX=0; TLS_SNI=""; TLS_CERT=""; TLS_KEY=""
WS_PATH="/vpnws"; GRPC_SVC="vpngrpc"; WS_BACKEND="127.0.0.1:8444"; GRPC_BACKEND="127.0.0.1:8445"
MUX_TERM_PORT=8081
while [ $# -gt 0 ]; do
    case "$1" in
        --apply) APPLY=1 ;;
        --vpn-sni) VPN_SNI="${2:-}"; shift ;;
        --singbox-port) SB_PORT="${2:-}"; shift ;;
        --backend-port) BE_PORT="${2:-}"; shift ;;
        --tls-mux) TLS_MUX=1 ;;
        --tls-sni) TLS_SNI="${2:-}"; shift ;;
        --tls-cert) TLS_CERT="${2:-}"; shift ;;
        --tls-key) TLS_KEY="${2:-}"; shift ;;
        --ws-path) WS_PATH="${2:-}"; shift ;;
        --grpc-service) GRPC_SVC="${2:-}"; shift ;;
        --ws-backend) WS_BACKEND="${2:-}"; shift ;;
        --grpc-backend) GRPC_BACKEND="${2:-}"; shift ;;
        --mux-term-port) MUX_TERM_PORT="${2:-}"; shift ;;
        *) echo "Неизвестный флаг: $1" >&2; exit 2 ;;
    esac
    shift
done

log() { echo "[share-443] $*"; }
die() { echo "[share-443][ОШИБКА] $*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

# TLS-МУЛЬТИПЛЕКС: печать плана конфигурации (ничего не меняет). Добавляет ВТОРОЙ
# SNI на тот же :443, который терминируется TLS и раскидывает по пути/ALPN на
# loopback-инбаунды sing-box (VLESS-WS и VLESS-gRPC). Reality продолжает жить на
# своём SNI (raw, без терминации) через тот же ssl_preread.
print_tls_mux_plan() {
    log "TLS-МУЛЬТИПЛЕКС — план (НИЧЕГО не применяется; проверьте и внесите на роутере вручную)."
    [ -n "$TLS_SNI" ] || die "--tls-sni <домен> обязателен для --tls-mux (реальный домен с TLS-сертификатом)."
    local CERT KEY
    CERT="${TLS_CERT:-/etc/letsencrypt/live/$TLS_SNI/fullchain.pem}"
    KEY="${TLS_KEY:-/etc/letsencrypt/live/$TLS_SNI/privkey.pem}"
    cat <<EOF

# --- 1) stream: добавьте \$TLS_SNI в map ssl_preread (рядом с VPN-SNI) ---------
#   ${VPN_SNI:-vpn.example.com}   127.0.0.1:${SB_PORT};      # Reality (raw TLS)
#   ${TLS_SNI}   127.0.0.1:${MUX_TERM_PORT};      # <-- этот домен терминируем и мультиплексируем
#   default      127.0.0.1:${BE_PORT};

# --- 2) http: TLS-терминирующий сервер для \$TLS_SNI на 127.0.0.1:${MUX_TERM_PORT} ---
# (принимает PROXY protocol от stream-фронта; http2 — для gRPC)
server {
    listen 127.0.0.1:${MUX_TERM_PORT} ssl http2 proxy_protocol;
    server_name ${TLS_SNI};
    set_real_ip_from 127.0.0.1;
    real_ip_header proxy_protocol;

    ssl_certificate     ${CERT};
    ssl_certificate_key ${KEY};
    ssl_protocols TLSv1.2 TLSv1.3;

    # VLESS-WS -> sing-box vless-ws-in (${WS_BACKEND})
    location ${WS_PATH} {
        if (\$http_upgrade != "websocket") { return 404; }
        proxy_pass http://${WS_BACKEND};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_read_timeout 300s;
    }

    # VLESS-gRPC -> sing-box vless-grpc-in (${GRPC_BACKEND}); service_name=${GRPC_SVC}
    location ^~ /${GRPC_SVC}/ {
        grpc_pass grpc://${GRPC_BACKEND};
        grpc_set_header Host \$host;
        grpc_set_header X-Real-IP \$remote_addr;
    }

    # Всё остальное — сайт-прикрытие (замените на свой камуфляж или reverse-proxy).
    location / { return 404; }
}
EOF
    echo
    log "В панели включите мультиплекс-инбаунды: WS на ${WS_BACKEND}, gRPC на ${GRPC_BACKEND}"
    log "  (Настройки: inbound_mux_ws_enabled=1 path=${WS_PATH}; inbound_mux_grpc_enabled=1 service=${GRPC_SVC})."
    log "Клиент (WS):  vless://<uuid>@${TLS_SNI}:443?type=ws&security=tls&host=${TLS_SNI}&path=$(printf '%s' "$WS_PATH" | sed 's,/,%2F,g')#ws"
    log "Клиент (gRPC): vless://<uuid>@${TLS_SNI}:443?type=grpc&security=tls&serviceName=${GRPC_SVC}&mode=gun#grpc"
    log "Проверьте конфиг на роутере (nginx -t), затем reload. Это часть прогона мульти-ОС/роутера."
}

# TLS-мультиплекс — только печать плана: root/nginx не требуются (ничего не меняем).
if [ "$TLS_MUX" = 1 ]; then
    print_tls_mux_plan
    exit 0
fi

[ "$(id -u)" = 0 ] || die "Запускать от root (sudo)."
have nginx || die "nginx не найден — этот скрипт для nginx-фронта."
nginx -t >/dev/null 2>&1 || die "Текущий конфиг nginx уже невалиден (nginx -t падает) — сначала почините его."

# --- окружение ------------------------------------------------------------
PANEL=""
[ -d /usr/local/mgr5 ] && PANEL="ISPmanager"
[ -d /usr/local/cpanel ] && PANEL="cPanel"
{ [ -d /usr/local/psa ] || [ -d /opt/psa ]; } && PANEL="Plesk"
[ -d /usr/local/hestia ] && PANEL="HestiaCP"

DOMAINS=$(nginx -T 2>/dev/null | grep -E '^\s*server_name' | sed -E 's/^\s*server_name\s+//; s/;.*//' \
    | tr ' ' '\n' | grep -vE '^(_|localhost|)$' | sort -u)

log "Панель: ${PANEL:-нет}"
log "VPN SNI: ${VPN_SNI:-<не задан>} | sing-box порт: $SB_PORT | бэкенд сайтов: 127.0.0.1:$BE_PORT"
log "Найдены домены сайтов:"; echo "$DOMAINS" | sed 's/^/    - /'

STREAM_CONF="/etc/nginx/conf.d/stream-share443.conf"
# stream ДОЛЖЕН быть top-level; conf.d обычно включается только внутрь http{}.
# Поэтому кладём его отдельным файлом и подключаем из главного nginx.conf через
# отдельную stream-директиву include (см. ниже ensure_stream_include).
STREAM_BODY="stream {
    map \$ssl_preread_server_name \$vpsrouter_up443 {
        ${VPN_SNI:-vpn.example.com}   127.0.0.1:${SB_PORT};
        default                        127.0.0.1:${BE_PORT};
    }
    server {
        listen 443 reuseport;
        listen [::]:443 reuseport;
        ssl_preread on;
        proxy_pass \$vpsrouter_up443;
        proxy_protocol on;
    }
}"

# =========================================================================
if [ "$APPLY" != 1 ]; then
    echo
    log "DRY-RUN. Ничего не изменено. Что было бы сделано с --apply:"
    echo "  1) Бэкап всего /etc/nginx в /root/nginx-backup-<время>."
    echo "  2) Установка stream-демукса на :443 -> сайты(127.0.0.1:$BE_PORT) / VPN($VPN_SNI ->127.0.0.1:$SB_PORT)."
    if [ -n "$PANEL" ]; then
        echo "  3) 🔴 Панель «$PANEL»: vhost'ы НЕ трогаем (панель их вернёт). Вместо этого —"
        echo "     в настройках «$PANEL» переключить nginx на альтернативный порт $BE_PORT"
        echo "     (панель сама перегенерирует сайты на внутренний порт — durable)."
    else
        echo "  3) 🟡 Обычный nginx: переписать 'listen 443' у сайтов на"
        echo "     '127.0.0.1:$BE_PORT ... proxy_protocol' + real_ip, затем nginx -t / reload / health-check."
    fi
    echo "  4) Health-check каждого домена; при сбое — АВТО-ОТКАТ из бэкапа."
    echo
    echo "  Применить:  sudo bash $0 --apply --vpn-sni <ваш-vpn-домен>"
    exit 0
fi

# --- APPLY ---------------------------------------------------------------
[ -n "$VPN_SNI" ] || die "--vpn-sni обязателен при --apply."

TS=$(date +%Y%m%d-%H%M%S)
BACKUP="/root/nginx-backup-$TS"
log "Бэкап /etc/nginx -> $BACKUP"
cp -a /etc/nginx "$BACKUP" || die "Не удалось сделать бэкап — остановка."

rollback() {
    log "ОТКАТ: восстанавливаю /etc/nginx из $BACKUP"
    rm -rf /etc/nginx && cp -a "$BACKUP" /etc/nginx
    nginx -t >/dev/null 2>&1 && nginx -s reload 2>/dev/null
    die "Изменения откачены. Сервер в исходном состоянии. Разбор: $BACKUP"
}

# базовая доступность сайтов ДО изменений (для сравнения)
declare -A BASELINE
while IFS= read -r d; do
    [ -n "$d" ] || continue
    BASELINE["$d"]=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 8 \
        --connect-to "$d:443:127.0.0.1:443" "https://$d/" 2>/dev/null || echo 000)
done <<< "$DOMAINS"

# 1) stream-фронт (главный nginx.conf панель не перегенерирует)
log "Устанавливаю stream-демукс: $STREAM_CONF"
printf '%s\n' "$STREAM_BODY" > "$STREAM_CONF"

ensure_stream_include() {
    # nginx.conf должен подключать наш stream-файл на верхнем уровне.
    local ncf=/etc/nginx/nginx.conf
    grep -q "stream-share443" "$ncf" 2>/dev/null && return 0
    # Если файл сам содержит 'stream {' — не дублируем, просим вручную.
    if grep -qE '^\s*stream\s*\{' "$ncf"; then
        log "В nginx.conf уже есть блок stream — вставьте содержимое $STREAM_CONF внутрь него вручную и перезапустите с --apply."
        return 1
    fi
    printf '\ninclude %s;\n' "$STREAM_CONF" >> "$ncf"
}

if [ -z "$PANEL" ]; then
    # 🟡 обычный nginx: переносим сайты на внутренний порт
    log "Перевожу сайты с 'listen 443' на 127.0.0.1:$BE_PORT (proxy_protocol)"
    # nginx.conf сам include'ит vhost'ы; правим файлы, где есть listen 443 ssl
    grep -rlE 'listen\s+(\[::\]:)?443\s+ssl' /etc/nginx 2>/dev/null | while IFS= read -r f; do
        [ "$f" = "$STREAM_CONF" ] && continue
        sed -i -E \
            -e "s/listen\s+443\s+ssl([^;]*)/listen 127.0.0.1:${BE_PORT} ssl proxy_protocol\1/g" \
            -e "s/listen\s+\[::\]:443\s+ssl([^;]*)/listen [::1]:${BE_PORT} ssl proxy_protocol\1/g" \
            "$f"
    done
    # real_ip из PROXY protocol, чтобы бэкенд/логи видели настоящий IP
    RIP="/etc/nginx/conf.d/realip-proxyproto.conf"
    printf 'set_real_ip_from 127.0.0.1;\nset_real_ip_from ::1;\nreal_ip_header proxy_protocol;\n' > "$RIP"
    ensure_stream_include || rollback
else
    # 🔴 панель: только stream-фронт; порт сайтов переключается в самой панели
    ensure_stream_include || rollback
    log "🔴 Панель «$PANEL»: НЕ переписываю vhost'ы автоматически."
    log "    Сейчас 443 займёт stream-фронт, но сайты всё ещё слушают 443 -> будет конфликт."
    log "    Чтобы завершить БЕЗ конфликта: в «$PANEL» переключите nginx на порт $BE_PORT"
    log "    (обычно: настройки веб-сервера / альтернативный порт), затем перезапустите nginx."
    log "    Пока порт сайтов не переключён, применяю мягко: НЕ роняю текущий nginx."
    # Не рискуем: откатываем stream-include, оставляем только заготовку .conf + инструкции.
    sed -i '/stream-share443/d' /etc/nginx/nginx.conf 2>/dev/null
    log "Заготовка стрим-конфига оставлена в $STREAM_CONF (не активна)."
    log "После переключения порта сайтов в панели: добавьте 'include $STREAM_CONF;' в nginx.conf и nginx -s reload."
    log "Бэкап на всякий случай: $BACKUP"
    exit 0
fi

# 2) валидация + reload
if ! nginx -t >/dev/null 2>&1; then
    log "nginx -t не прошёл после изменений."
    rollback
fi
nginx -s reload 2>/dev/null || rollback

# 3) health-check доменов
log "Проверяю доступность сайтов после изменений..."
FAIL=0
while IFS= read -r d; do
    [ -n "$d" ] || continue
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 8 \
        --connect-to "$d:443:127.0.0.1:443" "https://$d/" 2>/dev/null || echo 000)
    base="${BASELINE[$d]:-000}"
    if [ "$code" = "000" ] && [ "$base" != "000" ]; then
        log "  ✗ $d: было $base, стало $code (перестал отвечать)"
        FAIL=1
    else
        log "  ✓ $d: $base -> $code"
    fi
done <<< "$DOMAINS"

[ "$FAIL" = 0 ] || rollback

log "Готово. 443 разделён: сайты работают, SNI «$VPN_SNI» уходит в sing-box (127.0.0.1:$SB_PORT)."
log "Теперь в панели vps_router: reality_listen_ip=127.0.0.1, reality_listen_port=$SB_PORT, camouflage-домен=$VPN_SNI."
log "Бэкап исходного nginx: $BACKUP (удалите, когда убедитесь, что всё стабильно)."
