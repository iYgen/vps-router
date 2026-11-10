#!/usr/bin/env bash
# Вход VLESS+XHTTP для устройств: Xray за nginx существующего сайта.
#
# sing-box не умеет транспорт XHTTP, поэтому рядом ставится Xray, который
# слушает ТОЛЬКО 127.0.0.1 (без TLS). Снаружи — обычный HTTPS-сайт на 443:
# nginx по секретному пути проксирует HTTP/2 (grpc_pass) в Xray, а Xray
# отдаёт трафик каждого устройства в sing-box (SOCKS-мост на 127.0.0.1) —
# маршрутизация, exit'ы и учёт остаются общими.
#
# Конфиг Xray и nginx-snippet пишет apply-router.sh по настройкам панели
# (Настройки → «Протоколы подключения устройств» → VLESS + XHTTP).
#
# Использование: xhttp-install.sh <домен-сайта>
#   Домен должен указывать на этот сервер, в nginx уже должен быть его
#   server{} на 443 с http2 и действующим сертификатом.
# Версия Xray: XRAY_VERSION=v26.10.21 (по умолчанию — закреплённая ниже).
# Откат: выключить протокол в панели (apply остановит сервис и очистит snippet);
#        полностью — systemctl disable --now xray-xhttp; убрать include из server{}.

set -euo pipefail
DOMAIN="${1:?usage: $0 <domain>}"
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || { echo "bad domain"; exit 1; }
XRAY_VERSION="${XRAY_VERSION:-v26.10.21}"
[[ "$XRAY_VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "bad XRAY_VERSION"; exit 1; }
SNIPPET=/etc/nginx/snippets/vpsrouter-xhttp.conf
log() { echo "[xhttp-install] $*"; }

command -v nginx >/dev/null || { log "ERROR: нужен nginx"; exit 1; }
command -v unzip >/dev/null || apt-get install -y unzip >/dev/null

# 1. Бинарник Xray (с проверкой SHA-256 из .dgst релиза)
if [ "$(xray version 2>/dev/null | awk 'NR==1{print "v"$2}')" != "$XRAY_VERSION" ]; then
    case "$(uname -m)" in
        x86_64) asset=Xray-linux-64.zip ;;
        aarch64) asset=Xray-linux-arm64-v8a.zip ;;
        *) log "ERROR: архитектура $(uname -m) не поддержана"; exit 1 ;;
    esac
    tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
    base="https://github.com/XTLS/Xray-core/releases/download/$XRAY_VERSION"
    log "Качаю Xray $XRAY_VERSION"
    curl -fsSL -o "$tmp/x.zip" "$base/$asset"
    curl -fsSL -o "$tmp/x.dgst" "$base/$asset.dgst"
    want=$(sed -n 's/^SHA2-256= *//p' "$tmp/x.dgst" | head -1)
    have=$(sha256sum "$tmp/x.zip" | awk '{print $1}')
    [ -n "$want" ] && [ "$want" = "$have" ] || { log "ERROR: SHA-256 не совпал"; exit 1; }
    unzip -q -o "$tmp/x.zip" xray -d "$tmp"
    install -m 0755 "$tmp/xray" /usr/local/bin/xray
fi
log "$(xray version | head -1)"

# 2. Пользователь и каталог конфига (конфиг кладёт apply-router.sh)
id xray-xhttp >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin xray-xhttp
install -d -o root -g xray-xhttp -m 0750 /etc/xray-xhttp

# 3. systemd-юнит
cat > /etc/systemd/system/xray-xhttp.service <<'UNIT'
[Unit]
Description=Xray (VLESS+XHTTP entry for vps-router)
After=network-online.target sing-box.service
Wants=network-online.target

[Service]
User=xray-xhttp
Group=xray-xhttp
ExecStart=/usr/local/bin/xray run -c /etc/xray-xhttp/config.json
Restart=on-failure
RestartSec=3
LimitNOFILE=65535
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
CapabilityBoundingSet=
AmbientCapabilities=

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload

# 4. Пустой snippet (apply-router.sh наполнит его location'ом)
install -d /etc/nginx/snippets
[ -f "$SNIPPET" ] || echo "# managed by vps-router apply-router.sh — XHTTP disabled" > "$SNIPPET"

if grep -rqs "vpsrouter-xhttp.conf" /etc/nginx/; then
    log "include уже подключён"
else
    log "Добавьте в server{} сайта $DOMAIN на 443 строку:"
    log "    include snippets/vpsrouter-xhttp.conf;"
    log "затем: nginx -t && systemctl reload nginx"
fi
log "Готово. В панели: Настройки → протокол «VLESS + XHTTP», домен = $DOMAIN → Сохранить и применить."
