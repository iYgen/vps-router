#!/usr/bin/env bash
# Псевдо-сайт обновлений + TLS 1.3-прикрытие Reality для EXIT-сервера.
#
# Ставит Caddy, который:
#  - слушает 80 (ACME HTTP-01, авто-сертификат Let's Encrypt) и
#    127.0.0.1:8443 (TLS 1.3, куда Reality отдаёт рукопожатие сканеров);
#  - отдаёт статический сайт из /var/www/update-site.
# Reality на этом сервере (sing-box-exit) настраивается панелью: в параметрах
# связи вход→exit domain=<домен>, handshake=127.0.0.1:8443.
#
# Использование: reality-front-install.sh <домен>   (например updates.example.com)
# ВАЖНО: A-запись <домена> должна указывать на этот сервер, иначе ACME не выдаст сертификат.
# Порт 443 остаётся за sing-box-exit (VLESS) — Caddy его НЕ занимает.

set -euo pipefail
DOMAIN="${1:?usage: $0 <domain>}"
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || { echo "bad domain"; exit 1; }
log() { echo "[update-site] $*"; }

if ! /usr/local/bin/caddy version >/dev/null 2>&1; then
    tag=$(curl -fsSLI -o /dev/null -w '%{url_effective}' https://github.com/caddyserver/caddy/releases/latest | sed -n 's#.*/tag/\(v[0-9][^/]*\)$#\1#p')
    [ -n "$tag" ] || { log "ERROR: не удалось узнать версию Caddy"; exit 1; }
    arch=$(uname -m); case "$arch" in x86_64) arch=amd64 ;; aarch64) arch=arm64 ;; esac
    tmp=$(mktemp -d)
    log "Качаю Caddy $tag"
    curl -fsSL -o "$tmp/c.tgz" "https://github.com/caddyserver/caddy/releases/download/$tag/caddy_${tag#v}_linux_${arch}.tar.gz"
    tar -xzf "$tmp/c.tgz" -C "$tmp" caddy
    install -m 0755 "$tmp/caddy" /usr/local/bin/caddy
    rm -rf "$tmp"
fi
log "$(/usr/local/bin/caddy version)"

mkdir -p /var/www/update-site /etc/update-site /var/lib/update-site
# Контент сайта кладётся рядом со скриптом (site/) или уже присутствует в /var/www/update-site.
SRC="$(dirname "$0")/update-site"
[ -d "$SRC" ] && cp -r "$SRC"/. /var/www/update-site/

cat > /etc/update-site/Caddyfile <<EOF
{
	admin off
	email admin@$DOMAIN
	storage file_system /var/lib/update-site/acme
}

# Публичный HTTPS-сайт на 80->авто-редирект и ACME. 443 занят VLESS, поэтому
# публичный TLS отдаём на 8080? Нет: для правдоподобия достаточно, чтобы домен
# открывался. Caddy получает сертификат по HTTP-01 (порт 80) и отдаёт сайт.
http://$DOMAIN {
	root * /var/www/update-site
	file_server
	header -Server
}

# TLS 1.3-прикрытие для Reality: sing-box шлёт сюда рукопожатие сканеров.
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
sleep 2
systemctl is-active --quiet update-site.service || { log "ERROR: не запустился, journalctl -u update-site"; exit 1; }
log "Готово. Сайт: http://$DOMAIN  ·  Reality-фронт TLS1.3: 127.0.0.1:8443"
log "Дальше в панели: связь вход→exit → domain=$DOMAIN, handshake=127.0.0.1:8443, применить."
