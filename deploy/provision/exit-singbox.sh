#!/usr/bin/env bash
# Ставит отдельный, изолированный sing-box-инстанс на exit-сервере,
# обслуживающий inbound'ы VLESS/Shadowsocks/Hysteria2/TUIC/Trojan (все эти
# протоколы нативно умеет один и тот же sing-box, поэтому не плодим
# сервисов на протокол). Полностью отдельный юнит sing-box-exit.service и
# конфиг — не пересекается ни с каким другим sing-box/xray на сервере.
# Идемпотентен: каждый вызов ПОЛНОСТЬЮ перезаписывает конфиг заданным
# набором inbound'ов (частичных обновлений нет — панель всегда присылает
# актуальный полный список, как и SingboxConfigBuilder на стороне входной VPS).
#
# Вызывается панелью через Ssh::runProvisionScript — параметры в $1
# (JSON-файл). Поле "inbounds" уже приходит в готовой sing-box JSON-форме
# (собрано в PHP), скрипт оборачивает его в валидный config.json.
# Поле "domains" — список доменов, которым нужен НАСТОЯЩИЙ TLS-сертификат
# (Hysteria2/TUIC/Trojan/VLESS+ws/grpc — в отличие от VLESS+Reality, который
# чужой сертификат не предъявляет вообще). Для каждого домена, если
# сертификата ещё нет, скрипт получает его через certbot (HTTP-01,
# standalone — порт 80 должен быть свободен на момент выпуска).

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[exit-singbox] $*"; }

detect_os
ensure_base_tools || exit 1

DOMAINS=$(jq -r '.domains[]? // empty' "$PARAMS_FILE")
if [ -n "$DOMAINS" ]; then
    ensure_cmd certbot certbot || { log "ERROR: не удалось установить certbot"; exit 1; }
    # Порт 80 нужен свободным для HTTP-01 standalone-проверки —
    # останавливаем sing-box-exit на время выпуска (если уже был запущен).
    systemctl stop sing-box-exit 2>/dev/null || true
    while IFS= read -r domain; do
        [ -z "$domain" ] && continue
        if [ -d "/etc/letsencrypt/live/$domain" ]; then
            log "Сертификат для $domain уже есть, пропускаю"
            continue
        fi
        log "Получаю сертификат Let's Encrypt для $domain..."
        certbot certonly --standalone --non-interactive --agree-tos \
            --register-unsafely-without-email -d "$domain" \
            || { log "ERROR: не удалось получить сертификат для $domain — домен должен указывать на этот сервер (A-запись) и порт 80 быть доступен снаружи"; exit 1; }
    done <<< "$DOMAINS"
fi

BIN=/usr/local/bin/sing-box-exit
if [ ! -x "$BIN" ] || ! "$BIN" version >/dev/null 2>&1; then
    log "Ставлю отдельный бинарник sing-box для exit-роли..."
    install_singbox "$BIN" || { log "ERROR: не удалось установить sing-box"; exit 1; }
fi

mkdir -p /etc/sing-box-exit
jq -n --argjson inbounds "$(jq -c '.inbounds' "$PARAMS_FILE")" \
    '{log: {level: "info", timestamp: true}, inbounds: $inbounds, outbounds: [{type: "direct", tag: "direct"}]}' \
    > /etc/sing-box-exit/config.json

if ! "$BIN" check -c /etc/sing-box-exit/config.json; then
    log "ERROR: сгенерированный конфиг не прошёл проверку sing-box check"
    exit 1
fi

UNIT=/etc/systemd/system/sing-box-exit.service
cat > "$UNIT" <<EOF
[Unit]
Description=sing-box (panel-managed exit inbound)
After=network.target

[Service]
ExecStart=$BIN run -c /etc/sing-box-exit/config.json
Restart=on-failure
RestartSec=2
User=root
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable sing-box-exit >/dev/null 2>&1
systemctl restart sing-box-exit

sleep 1
if ! systemctl is-active --quiet sing-box-exit; then
    log "ERROR: sing-box-exit не запустился, см. journalctl -u sing-box-exit"
    exit 1
fi

for p in $(jq -r '.inbounds[] | "\(.listen_port)/\(if .type == "hysteria2" or .type == "tuic" then "udp" else "tcp" end)"' /etc/sing-box-exit/config.json); do
    open_port "$p"
done

log "Готово: sing-box-exit активен"
