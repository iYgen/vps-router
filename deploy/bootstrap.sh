#!/usr/bin/env bash
# vps_router — ТОНКИЙ bootstrap-установщик.
# vps_router — THIN bootstrap installer.
#
# Запуск на свежем VPS из распакованного репозитория:
#   sudo bash deploy/bootstrap.sh
#
# Отличие от deploy/install.sh: bootstrap ставит только МИНИМУМ, нужный, чтобы
# открыть браузерный мастер — PHP-FPM + nginx + код панели + /etc/panel/config.php
# + одноразовый токен + привилегированные whitelisted-скрипты и sudoers. Тяжёлые
# шаги (sing-box, AmneziaWG, TLS-сертификат) выполняет уже сам мастер по кнопке,
# с живым логом (SSE) — они переживают обрыв связи и не блокируют консоль.
#
# Это безопаснее «толстого» install.sh: если пакетная установка на конкретной ОС
# спотыкается, пользователь видит это в браузере и может повторить шаг, а не
# теряет весь установщик на середине.
set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SELF_DIR/.." && pwd)"
PANEL_SRC="$REPO_DIR/panel"
PROV_SRC="$REPO_DIR/deploy/provision"
LIB="$PROV_SRC/_lib.sh"

if [ "$(id -u)" != "0" ]; then
    echo "Запустите через sudo/от root: sudo bash deploy/bootstrap.sh" >&2
    exit 1
fi
if [ ! -d "$PANEL_SRC" ] || [ ! -r "$LIB" ]; then
    echo "Не найден код панели ($PANEL_SRC) или _lib.sh — запускайте из распакованного репозитория." >&2
    exit 1
fi
# shellcheck disable=SC1090
. "$LIB"

LNG=ru
printf 'Язык / Language [ru/en] (ru): '
read -r _lng || true
[ "${_lng:-}" = "en" ] && LNG=en
export VPSR_LANG="$LNG"   # язык логов провижининга (_lib.sh/_t), наследуется дочерними скриптами
tr() { [ "$LNG" = en ] && printf '%s' "$2" || printf '%s' "$1"; }
say() { echo "$(tr "$1" "$2")"; }
hdr() { echo; echo "==== $(tr "$1" "$2") ===="; }
ask() {
    local __var="$1" __ru="$2" __en="$3" __def="${4:-}" __in=""
    if [ -n "$__def" ]; then printf '%s [%s]: ' "$(tr "$__ru" "$__en")" "$__def"
    else printf '%s: ' "$(tr "$__ru" "$__en")"; fi
    read -r __in || true
    printf -v "$__var" '%s' "${__in:-$__def}"
}

hdr "Тонкая установка панели vps_router" "vps_router thin panel install"
detect_os
OS_PRETTY="${PRETTY_NAME:-${OS_ID:-unknown} ${OS_VER:-}}"
say "Обнаружена ОС: $OS_PRETTY (менеджер пакетов: $PKG)" "Detected OS: $OS_PRETTY (package manager: $PKG)"

# --- параметры (минимум) -------------------------------------------------
hdr "Параметры" "Parameters"
DETECTED_IP="$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)"
say "Панель откроется по домену ИЛИ по IP. Если домена нет — оставьте пустым (будет IP)." \
    "The panel opens by domain OR IP. Leave empty to use the server IP."
ask DOMAIN "Домен панели (пусто = по IP $DETECTED_IP)" "Panel domain (empty = use IP $DETECTED_IP)" ""
if [ -n "${DOMAIN:-}" ] && ! [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]]; then
    say "Недопустимый домен — будет использован IP." "Invalid domain — the IP will be used."
    DOMAIN=""
fi
SERVER_NAME="${DOMAIN:-${DETECTED_IP:-_}}"
APP_SECRET="$(openssl rand -hex 32 2>/dev/null || head -c 32 /dev/urandom | xxd -p -c 64)"

# --- только веб-стек (без sing-box/amnezia — это сделает мастер) ----------
# install_php_stack (в _lib.sh) сам подключает remi на EL и гарантирует PHP>=8.1,
# экспортируя PHP_BIN / PHP_FPM_SERVICE / WEB_USER.
hdr "Установка веб-стека" "Installing web stack"
ensure_base_tools || { say "Не удалось поставить базовые утилиты" "Failed to install base tools"; exit 1; }

if ! install_php_stack; then
    say "Не удалось установить PHP 8.1+/nginx на этой ОС. Поставьте их вручную (на EL — из remi) и перезапустите." \
        "Failed to install PHP 8.1+/nginx on this OS. Install them manually (remi on EL) and re-run."
    exit 1
fi

# --- каталоги и код ------------------------------------------------------
hdr "Развёртывание панели" "Deploying the panel"
id -u "$WEB_USER" >/dev/null 2>&1 || WEB_USER=www-data
mkdir -p /var/www/panel /etc/sing-box/rule-sets /etc/amnezia /var/lib/panel /etc/panel /usr/local/share/panel/provision /var/www/certbot
printf '%s' "$LNG" > /var/lib/panel/lang   # язык панели по умолчанию = выбранный в установщике (его читают UI/CLI/скрипты)
cp -r "$PANEL_SRC"/. /var/www/panel/

if [ ! -d /var/www/panel/vendor ]; then
    if command -v composer >/dev/null 2>&1; then
        ( cd /var/www/panel && sudo -u "$WEB_USER" composer install --no-dev --no-interaction ) || \
        ( cd /var/www/panel && composer install --no-dev --no-interaction ) || \
            say "composer install не удался — выполните вручную (cd /var/www/panel && composer install --no-dev)" "composer install failed — run it manually"
    else
        say "composer не найден и vendor/ отсутствует — SSH-функции заработают после composer install." "composer not found and vendor/ missing — SSH features need composer install."
    fi
fi

# провижн-скрипты (панель заливает их на удалённые серверы по SFTP)
cp "$PROV_SRC"/*.sh /usr/local/share/panel/provision/
chmod 755 /usr/local/share/panel/provision/*.sh

# --- привилегированные whitelisted-скрипты + sudoers ---------------------
# Мастер (www-data) вызывает их через sudo БЕЗ аргументов; параметры — через
# файлы в /var/lib/panel (см. InstallRunner / Installer).
install -o root -g root -m 750 "$REPO_DIR/deploy/router/apply-router.sh"          /usr/local/sbin/apply-router.sh
install -o root -g root -m 750 "$PROV_SRC/entry-vps-install.sh"                       /usr/local/sbin/vpsrouter-vps-install.sh
install -o root -g root -m 755 "$REPO_DIR/deploy/preflight.sh"                     /usr/local/sbin/vpsrouter-preflight.sh
install -o root -g root -m 755 "$REPO_DIR/deploy/router/share-443.sh"              /usr/local/sbin/vpsrouter-share-443.sh
install -o root -g root -m 755 "$PROV_SRC/install-components.sh"                   /usr/local/sbin/vpsrouter-install-components.sh
install -o root -g root -m 755 "$REPO_DIR/deploy/router/issue-cert.sh"             /usr/local/sbin/vpsrouter-issue-cert.sh
install -o root -g root -m 750 "$PROV_SRC/entry-zapret.sh"                             /usr/local/sbin/vpsrouter-zapret.sh
install -o root -g root -m 755 "$PROV_SRC/entry-wg-status.sh"                          /usr/local/sbin/vpsrouter-wg-status.sh
install -o root -g root -m 755 "$PROV_SRC/pkg-manage.sh"                            /usr/local/sbin/vpsrouter-pkg-manage.sh
install -o root -g root -m 755 "$PROV_SRC/entry-tgproxy.sh"                            /usr/local/sbin/vpsrouter-tgproxy.sh

cat > /etc/sudoers.d/panel-zapret <<EOF
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-zapret.sh
EOF
cat > /etc/sudoers.d/panel-wgstatus <<EOF
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-wg-status.sh
EOF
cat > /etc/sudoers.d/panel-pkg <<EOF
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-pkg-manage.sh
EOF
cat > /etc/sudoers.d/panel-tgproxy <<EOF
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-tgproxy.sh
EOF
cat > /etc/sudoers.d/panel-apply <<EOF
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/apply-router.sh
EOF
cat > /etc/sudoers.d/panel-provision <<EOF
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-vps-install.sh
EOF
cat > /etc/sudoers.d/panel-install <<EOF
Defaults env_keep += "VPSR_LANG"
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-preflight.sh
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-share-443.sh
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-install-components.sh
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/vpsrouter-issue-cert.sh
EOF
chmod 440 /etc/sudoers.d/panel-apply /etc/sudoers.d/panel-provision /etc/sudoers.d/panel-install /etc/sudoers.d/panel-zapret /etc/sudoers.d/panel-wgstatus /etc/sudoers.d/panel-pkg /etc/sudoers.d/panel-tgproxy
visudo -cf /etc/sudoers.d/panel-zapret >/dev/null || rm -f /etc/sudoers.d/panel-zapret
visudo -cf /etc/sudoers.d/panel-wgstatus >/dev/null || rm -f /etc/sudoers.d/panel-wgstatus
visudo -cf /etc/sudoers.d/panel-pkg >/dev/null || rm -f /etc/sudoers.d/panel-pkg
visudo -cf /etc/sudoers.d/panel-tgproxy >/dev/null || rm -f /etc/sudoers.d/panel-tgproxy
visudo -cf /etc/sudoers.d/panel-apply >/dev/null && visudo -cf /etc/sudoers.d/panel-provision >/dev/null \
    && visudo -cf /etc/sudoers.d/panel-install >/dev/null

# --- config.php ----------------------------------------------------------
cat > /etc/panel/config.php <<EOF
<?php
return [
    'db_path' => '/var/lib/panel/panel.db',
    'app_secret' => '$APP_SECRET',
    'singbox_config_path' => '/etc/sing-box/config.json',
    'singbox_ruleset_dir' => '/etc/sing-box/rule-sets',
    'amnezia_conf_dir' => '/etc/amnezia',
    'amnezia_interfaces_list' => '/etc/amnezia/interfaces.list',
    'apply_script' => '/usr/local/sbin/apply-router.sh',
    'provision_install_script' => '/usr/local/sbin/vpsrouter-vps-install.sh',
    'provision_scripts_dir' => '/usr/local/share/panel/provision',
    'reality_listen_ip' => '${DETECTED_IP:-0.0.0.0}',
    'reality_listen_port' => 443,
    'session_name' => 'panel_sess',
];
EOF

chown -R "$WEB_USER:$WEB_USER" /var/www/panel /etc/sing-box /etc/amnezia /var/lib/panel
chown root:"$WEB_USER" /etc/panel/config.php
chmod 640 /etc/panel/config.php

# --- одноразовый токен «уникальной ссылки» -------------------------------
INSTALL_TOKEN="$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | xxd -p -c 32)"
printf '%s' "$INSTALL_TOKEN" > /var/lib/panel/install-token
chown "$WEB_USER:$WEB_USER" /var/lib/panel/install-token
chmod 640 /var/lib/panel/install-token

# --- инициализация БД (миграции; админа задаст мастер) -------------------
hdr "Инициализация БД" "Initialising DB"
export PANEL_CONFIG_PATH=/etc/panel/config.php
sudo -u "$WEB_USER" env PANEL_CONFIG_PATH=/etc/panel/config.php "$PHP_BIN" -r 'require "/var/www/panel/src/bootstrap.php"; App\Database::get(); echo "migrations ok\n";'

# --- nginx vhost (HTTP; HTTPS выпустит мастер) ---------------------------
hdr "Настройка nginx" "Configuring nginx"
FPM_PASS="$(php_fpm_pass)"  # unix-сокет FPM (вкл. remi-пути) или 127.0.0.1:9000

VHOST_HTTP="server {
    listen 80;
    listen [::]:80;
    server_name $SERVER_NAME;
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    root /var/www/panel/public;
    index index.php;
    location / { try_files \$uri /index.php\$is_args\$args; }
    location ~ \\.php\$ {
        include $(ls /etc/nginx/snippets/fastcgi-php.conf >/dev/null 2>&1 && echo 'snippets/fastcgi-php.conf' || echo 'fastcgi_params');
        fastcgi_pass $FPM_PASS;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PANEL_CONFIG_PATH /etc/panel/config.php;
    }
    location ~ /\\. { deny all; }
}"
VHOST_NAME="${DOMAIN:-panel}"
if [ -d /etc/nginx/sites-available ]; then
    echo "$VHOST_HTTP" > "/etc/nginx/sites-available/$VHOST_NAME.conf"
    ln -sf "../sites-available/$VHOST_NAME.conf" "/etc/nginx/sites-enabled/$VHOST_NAME.conf"
else
    mkdir -p /etc/nginx/conf.d
    echo "$VHOST_HTTP" > "/etc/nginx/conf.d/panel-$VHOST_NAME.conf"
fi
nginx -t && { systemctl enable nginx >/dev/null 2>&1 || true; systemctl restart nginx || service nginx restart; }
if [ -n "${PHP_FPM_SERVICE:-}" ]; then
    systemctl enable "$PHP_FPM_SERVICE" >/dev/null 2>&1 || true
    systemctl restart "$PHP_FPM_SERVICE" 2>/dev/null || service "$PHP_FPM_SERVICE" restart 2>/dev/null || true
else
    systemctl restart php*-fpm 2>/dev/null || service php-fpm restart 2>/dev/null || true
fi

# --- firewall: открыть 80/443 -------------------------------------------
open_port 80/tcp 2>/dev/null || true
open_port 443/tcp 2>/dev/null || true

# --- итог ----------------------------------------------------------------
hdr "Bootstrap завершён" "Bootstrap complete"
say "Веб-стек и код панели установлены. Тяжёлые шаги (sing-box, AmneziaWG, TLS) — в браузере." \
    "Web stack and panel code installed. Heavy steps (sing-box, AmneziaWG, TLS) run in the browser."
echo
echo "  ==> http://$SERVER_NAME/install.php?t=$INSTALL_TOKEN"
echo
say "Откройте ЭТУ УНИКАЛЬНУЮ ссылку — мастер по шагам: компоненты → (домен/TLS) → Reality → админ." \
    "Open THIS UNIQUE link — the wizard runs: components → (domain/TLS) → Reality → admin."
say "После создания админа файлы мастера удаляются автоматически." "Installer files are removed automatically once the admin is created."
