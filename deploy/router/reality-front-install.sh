#!/usr/bin/env bash
# Локальный TLS 1.3-фронт для маскировки Reality под СВОЙ сайт.
#
# Reality отдаёт рукопожатие посторонних (сканеров, Active Probing) настоящему
# сайту — сканер видит реальный сайт с реальным сертификатом, домен которого
# и правда указывает на этот IP. Reality требует от такого сайта TLS 1.3, а
# nginx на CentOS 7 собран с OpenSSL 1.0.2 (без TLS 1.3). Поэтому рядом
# ставится Caddy, который слушает ТОЛЬКО 127.0.0.1:8443, отдаёт сертификат
# Let's Encrypt этого сайта по TLS 1.3 и проксирует запросы в существующий
# nginx. Посетители сайтов его не видят и nginx не меняется.
#
# Использование: reality-front-install.sh <домен>   (например panel.example.com)
# Затем в панели: Настройки → Camouflage-домен = <домен>,
#                  «Куда отдавать рукопожатие» = 127.0.0.1:8443.
# Откат: systemctl disable --now reality-front; очистить это поле в панели.

set -euo pipefail
DOMAIN="${1:?usage: $0 <domain>}"
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || { echo "bad domain"; exit 1; }
LISTEN=127.0.0.1:8443
CERT_DIR=/etc/letsencrypt/live/$DOMAIN
log() { echo "[reality-front] $*"; }

[ -f "$CERT_DIR/fullchain.pem" ] || { log "ERROR: нет сертификата $CERT_DIR"; exit 1; }
# IP, на котором nginx отдаёт этот сайт по HTTPS (первый публичный адрес).
UPSTREAM_IP=$(ip -4 route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')

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

mkdir -p /etc/reality-front
cat > /etc/reality-front/Caddyfile <<EOF
{
	admin off
	auto_https off
	persist_config off
	servers {
		protocols h1 h2
	}
}

https://$DOMAIN:8443, https://www.$DOMAIN:8443 {
	bind 127.0.0.1
	tls $CERT_DIR/fullchain.pem $CERT_DIR/privkey.pem {
		protocols tls1.2 tls1.3
	}
	reverse_proxy https://$UPSTREAM_IP:443 {
		header_up Host {host}
		transport http {
			tls_server_name $DOMAIN
		}
	}
}
EOF
/usr/local/bin/caddy validate --config /etc/reality-front/Caddyfile --adapter caddyfile >/dev/null

cat > /etc/systemd/system/reality-front.service <<'EOF'
[Unit]
Description=Reality camouflage front (TLS 1.3 on 127.0.0.1:8443 -> nginx)
After=network-online.target nginx.service
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/caddy run --config /etc/reality-front/Caddyfile --adapter caddyfile
Restart=on-failure
RestartSec=2
Environment=HOME=/var/lib/reality-front XDG_DATA_HOME=/var/lib/reality-front XDG_CONFIG_HOME=/var/lib/reality-front
StateDirectory=reality-front
NoNewPrivileges=yes

[Install]
WantedBy=multi-user.target
EOF
mkdir -p /var/lib/reality-front

# После продления сертификата certbot'ом — перезапуск, чтобы Caddy взял новый.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/reality-front.sh <<'EOF'
#!/bin/sh
systemctl try-restart reality-front.service
EOF
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reality-front.sh

systemctl daemon-reload
systemctl enable reality-front.service >/dev/null 2>&1
systemctl restart reality-front.service
sleep 1
systemctl is-active --quiet reality-front.service || { log "ERROR: не запустился, см. journalctl -u reality-front"; exit 1; }
log "Готово: $LISTEN отдаёт $DOMAIN по TLS 1.3 (прокси в nginx $UPSTREAM_IP:443)"
