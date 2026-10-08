#!/usr/bin/env bash
# Ставит на EXIT-сервере сайт-прикрытие + TLS 1.3-фронт для Reality (Caddy).
# Reality-инбаунд (sing-box-exit на :443) отдаёт рукопожатие сканеров сюда, на
# 127.0.0.1:8443, где живёт настоящий сайт с валидным сертификатом Let's Encrypt.
# Порт 443 остаётся за sing-box-exit; Caddy слушает только :80 (ACME) и 127.0.0.1:8443.
#
# Вызывается панелью через Ssh::runProvisionScript. Параметры в $1 (JSON):
#   domain     — домен сайта-прикрытия (A-запись обязана указывать на этот сервер)
#   files      — { "index.html": "...", ... } содержимое сайта (пишет панель)
# _lib.sh с install_caddy приклеивается автоматически.

set -euo pipefail
PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[exit-camouflage] $*"; }

detect_os
ensure_base_tools || exit 1
install_caddy /usr/local/bin/caddy || exit 1

DOMAIN=$(jq -r '.domain' "$PARAMS_FILE")
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || { log "ERROR: некорректный домен"; exit 1; }

mkdir -p /var/www/update-site /etc/update-site /var/lib/update-site
# Файлы сайта — из params.json (ключи = имена файлов, значения = содержимое).
jq -r '.files | keys[]' "$PARAMS_FILE" | while IFS= read -r name; do
    [[ "$name" =~ ^[A-Za-z0-9._-]+$ ]] || { log "пропускаю имя файла $name"; continue; }
    jq -r --arg n "$name" '.files[$n]' "$PARAMS_FILE" > "/var/www/update-site/$name"
done
[ -f /var/www/update-site/index.html ] || { log "ERROR: не передан index.html"; exit 1; }

cat > /etc/update-site/Caddyfile <<EOF
{
	admin off
	email admin@$DOMAIN
	storage file_system /var/lib/update-site/acme
}

http://$DOMAIN {
	root * /var/www/update-site
	file_server
	header -Server
}

https://$DOMAIN:8443 {
	bind 127.0.0.1
	tls {
		protocols tls1.2 tls1.3
	}
	root * /var/www/update-site
	file_server
	header -Server
}
EOF

/usr/local/bin/caddy validate --config /etc/update-site/Caddyfile --adapter caddyfile >/dev/null

cat > /etc/systemd/system/update-site.service <<'EOF'
[Unit]
Description=Update site + Reality TLS front (Caddy)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/caddy run --config /etc/update-site/Caddyfile --adapter caddyfile
Restart=on-failure
RestartSec=2
Environment=HOME=/var/lib/update-site XDG_DATA_HOME=/var/lib/update-site XDG_CONFIG_HOME=/var/lib/update-site
StateDirectory=update-site
AmbientCapabilities=CAP_NET_BIND_SERVICE
NoNewPrivileges=yes

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable update-site.service >/dev/null 2>&1
systemctl restart update-site.service

# Порт 80 для ACME (Let's Encrypt HTTP-01), если активен firewall.
open_port 80/tcp

sleep 2
systemctl is-active --quiet update-site.service || { log "ERROR: update-site не запустился (journalctl -u update-site)"; exit 1; }
# Дать Caddy до 40 c получить сертификат по HTTP-01.
for i in $(seq 1 20); do
    [ -d /var/lib/update-site/acme/certificates ] && find /var/lib/update-site/acme/certificates -name '*.crt' | grep -q . && break
    sleep 2
done
if find /var/lib/update-site/acme -name '*.crt' 2>/dev/null | grep -q .; then
    log "Готово: сайт $DOMAIN активен, сертификат получен, Reality-фронт на 127.0.0.1:8443"
else
    log "WARN: сайт запущен, но сертификат ещё не получен — проверьте A-запись $DOMAIN и порт 80. Reality-фронт заработает после выпуска."
fi
