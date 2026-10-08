#!/usr/bin/env bash
# vps_router — интерактивный установщик панели на чистый VPS.
# vps_router — interactive panel installer for a fresh VPS.
#
# Запуск на СВЕЖЕМ входной VPS из распакованного репозитория:
#   sudo bash deploy/install.sh
#
# Ставит PHP-FPM + расширения, sing-box, (опц.) AmneziaWG, разворачивает код
# панели в /var/www/panel, пишет /etc/panel/config.php, настраивает nginx-vhost,
# sudoers, каталоги, применяет миграции и создаёт администратора. Идемпотентен в
# разумных пределах — безопасно перезапускать. Существующие сайты не трогает
# (панель — отдельный vhost; Reality-инбаунд живёт на втором IP, задаётся в панели).
set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SELF_DIR/.." && pwd)"
PANEL_SRC="$REPO_DIR/panel"
PROV_SRC="$REPO_DIR/deploy/provision"
LIB="$PROV_SRC/_lib.sh"

if [ "$(id -u)" != "0" ]; then
    echo "Запустите через sudo/от root: sudo bash deploy/install.sh" >&2
    exit 1
fi
if [ ! -d "$PANEL_SRC" ] || [ ! -r "$LIB" ]; then
    echo "Не найден код панели ($PANEL_SRC) или _lib.sh — запускайте из распакованного репозитория." >&2
    exit 1
fi
# shellcheck disable=SC1090
. "$LIB"

# --- язык / language ----------------------------------------------------
LNG=ru
printf 'Язык / Language [ru/en] (ru): '
read -r _lng || true
[ "${_lng:-}" = "en" ] && LNG=en
export VPSR_LANG="$LNG"   # язык логов провижининга (_lib.sh/_t), наследуется дочерними скриптами
# tr "русский" "english" — печатает строку на выбранном языке
tr() { [ "$LNG" = en ] && printf '%s' "$2" || printf '%s' "$1"; }
say() { echo "$(tr "$1" "$2")"; }
hdr() { echo; echo "==== $(tr "$1" "$2") ===="; }

# ask VAR "ru prompt" "en prompt" "default"
ask() {
    local __var="$1" __ru="$2" __en="$3" __def="${4:-}" __in=""
    if [ -n "$__def" ]; then
        printf '%s [%s]: ' "$(tr "$__ru" "$__en")" "$__def"
    else
        printf '%s: ' "$(tr "$__ru" "$__en")"
    fi
    read -r __in || true
    printf -v "$__var" '%s' "${__in:-$__def}"
}
yesno() { # yesno "ru" "en" default(y/n) -> returns 0 for yes
    local __ru="$1" __en="$2" __def="${3:-y}" __in=""
    printf '%s [%s]: ' "$(tr "$__ru" "$__en")" "$([ "$__def" = y ] && echo Y/n || echo y/N)"
    read -r __in || true
    __in="${__in:-$__def}"
    [[ "$__in" =~ ^[YyДд] ]]
}

hdr "Установка панели vps_router" "vps_router panel installation"
detect_os
OS_PRETTY="${PRETTY_NAME:-${OS_ID:-unknown} ${OS_VER:-}}"
say "Обнаружена ОС: $OS_PRETTY (менеджер пакетов: $PKG)" "Detected OS: $OS_PRETTY (package manager: $PKG)"

# --- preflight: занят ли 443 (сайты/панель управления)? -----------------
# Не блокирует установку: панель ставится отдельным vhost'ом. Просто предупреждаем
# и подсказываем, как разделить один 443 между сайтами и VPN.
hdr "Проверка окружения" "Environment preflight"
PORT443_OWNER=""
if command -v ss >/dev/null 2>&1; then
    PORT443_OWNER=$(ss -H -ltnp 'sport = :443' 2>/dev/null | grep -oE 'users:\(\("[^"]+' | sed 's/.*"//' | sort -u | paste -sd, -)
fi
PANEL_DET=""
[ -d /usr/local/mgr5 ] && PANEL_DET="ISPmanager"
[ -d /usr/local/cpanel ] && PANEL_DET="cPanel"
{ [ -d /usr/local/psa ] || [ -d /opt/psa ]; } && PANEL_DET="Plesk"
[ -d /usr/local/hestia ] && PANEL_DET="HestiaCP"
if [ -n "$PORT443_OWNER" ]; then
    say "Порт 443 уже занят процессом «$PORT443_OWNER»${PANEL_DET:+ (панель: $PANEL_DET)}." \
        "Port 443 already used by «$PORT443_OWNER»${PANEL_DET:+ (panel: $PANEL_DET)}."
    say "Панель поставится (отдельный vhost). Чтобы отдавать сайты И VPN через ОДИН 443 — после установки:" \
        "The panel will still install (separate vhost). To serve sites AND the VPN over ONE 443, after install:"
    say "  sudo bash deploy/preflight.sh                  # диагностика (ничего не меняет)" \
        "  sudo bash deploy/preflight.sh                  # diagnose (changes nothing)"
    say "  sudo bash deploy/router/share-443.sh --apply … # разделить 443 (бэкап + авто-откат)" \
        "  sudo bash deploy/router/share-443.sh --apply … # split 443 (backup + auto-rollback)"
else
    say "Порт 443 свободен — Reality/inbound'ы смогут слушать его напрямую." \
        "Port 443 is free — Reality/inbounds can bind it directly."
fi

# --- сбор параметров ----------------------------------------------------
# Этот скрипт делает только рут-часть (веб-стек, sing-box, код, vhost) и передаёт
# управление ВИЗУАЛЬНОМУ мастеру в браузере (/install.php): логин/пароль, Reality,
# протоколы и восстановление из копии — уже там. Поэтому здесь спрашиваем минимум.
hdr "Параметры" "Parameters"
DETECTED_IP="$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)"
say "Панель откроется по домену ИЛИ по IP. Если домена нет — оставьте пустым, будет использован IP сервера." \
    "The panel opens by domain OR IP. If you have no domain, leave it empty and the server IP will be used."
ask DOMAIN "Домен панели (пусто = по IP $DETECTED_IP)" "Panel domain (empty = use IP $DETECTED_IP)" ""
if [ -n "${DOMAIN:-}" ] && ! [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]]; then
    say "Недопустимый домен — будет использован IP." "Invalid domain — the IP will be used."
    DOMAIN=""
fi
SERVER_NAME="${DOMAIN:-${DETECTED_IP:-_}}"
TLS=n
if [ -n "${DOMAIN:-}" ] && yesno "Выпустить TLS-сертификат Let's Encrypt для $DOMAIN сейчас? (нужен доступный домен и открытый 80/tcp)" "Issue a Let's Encrypt TLS cert for $DOMAIN now? (needs a resolvable domain and open 80/tcp)" n; then
    TLS=y
fi

APP_SECRET="$(openssl rand -hex 32 2>/dev/null || head -c 32 /dev/urandom | xxd -p -c 64)"

# --- пакеты -------------------------------------------------------------
hdr "Установка пакетов" "Installing packages"
ensure_base_tools || { say "Не удалось поставить базовые утилиты" "Failed to install base tools"; exit 1; }

# install_php_stack (в _lib.sh) подключает remi на EL и гарантирует PHP>=8.1,
# экспортируя PHP_BIN / PHP_FPM_SERVICE / WEB_USER.
if ! install_php_stack; then
    say "Не удалось установить PHP 8.1+/nginx на этой ОС. Поставьте вручную (на EL — из remi) и перезапустите." \
        "Failed to install PHP 8.1+/nginx on this OS. Install them manually (remi on EL) and re-run."
    exit 1
fi

# sing-box (+ AmneziaWG опционально)
if command -v sing-box >/dev/null 2>&1; then
    say "sing-box уже установлен" "sing-box already installed"
else
    install_singbox /usr/local/bin/sing-box || { say "sing-box не установлен" "sing-box install failed"; exit 1; }
fi
if yesno "Поставить AmneziaWG (нужен для AmneziaWG-туннелей и входа устройств по AmneziaWG)?" "Install AmneziaWG (needed for AmneziaWG tunnels and AmneziaWG device inbound)?" y; then
    install_amneziawg || say "AmneziaWG не установлен — остальные протоколы работают" "AmneziaWG not installed — other protocols still work"
fi

# --- каталоги и код -----------------------------------------------------
hdr "Развёртывание панели" "Deploying the panel"
id -u "$WEB_USER" >/dev/null 2>&1 || WEB_USER=www-data
mkdir -p /var/www/panel /etc/sing-box/rule-sets /etc/amnezia /var/lib/panel /etc/panel /usr/local/share/panel/provision /var/www/certbot
printf '%s' "$LNG" > /var/lib/panel/lang   # язык панели по умолчанию = выбранный в установщике (его читают UI/CLI/скрипты)
cp -r "$PANEL_SRC"/. /var/www/panel/

# зависимости (phpseclib) — composer, иначе оставляем vendor/ из репозитория
if [ ! -d /var/www/panel/vendor ]; then
    if command -v composer >/dev/null 2>&1; then
        ( cd /var/www/panel && sudo -u "$WEB_USER" composer install --no-dev --no-interaction ) || \
        ( cd /var/www/panel && composer install --no-dev --no-interaction ) || \
            say "composer install не удался — установите зависимости вручную (cd /var/www/panel && composer install --no-dev)" "composer install failed — run it manually (cd /var/www/panel && composer install --no-dev)"
    else
        say "composer не найден и vendor/ отсутствует — SSH-функции (тест подключения, провижининг) не заработают, пока не выполните composer install." "composer not found and vendor/ missing — SSH features won't work until you run composer install."
    fi
fi

# провижн-скрипты (панель заливает их на удалённые серверы по SFTP)
cp "$PROV_SRC"/*.sh /usr/local/share/panel/provision/
chmod 755 /usr/local/share/panel/provision/*.sh

# привилегированные локальные скрипты + sudoers (генерируем под нужного WEB_USER)
install -o root -g root -m 750 "$REPO_DIR/deploy/router/apply-router.sh" /usr/local/sbin/apply-router.sh
install -o root -g root -m 750 "$PROV_SRC/entry-vps-install.sh" /usr/local/sbin/vpsrouter-vps-install.sh 2>/dev/null || \
    install -o root -g root -m 750 "$REPO_DIR/deploy/provision/entry-vps-install.sh" /usr/local/sbin/vpsrouter-vps-install.sh
# read-only детект + план разделения 443 для веб-мастера (www-data через sudo БЕЗ аргументов).
# vpsrouter-share-443.sh без аргументов = dry-run (только план); деструктивный
# --apply доступен лишь из консоли (мастер аргументы не передаёт).
install -o root -g root -m 755 "$REPO_DIR/deploy/preflight.sh" /usr/local/sbin/vpsrouter-preflight.sh
install -o root -g root -m 755 "$REPO_DIR/deploy/router/share-443.sh" /usr/local/sbin/vpsrouter-share-443.sh
# Тяжёлые шаги мастера (доступны и здесь для повторного запуска из браузера).
install -o root -g root -m 755 "$PROV_SRC/install-components.sh" /usr/local/sbin/vpsrouter-install-components.sh
install -o root -g root -m 755 "$REPO_DIR/deploy/router/issue-cert.sh" /usr/local/sbin/vpsrouter-issue-cert.sh
install -o root -g root -m 750 "$PROV_SRC/entry-zapret.sh" /usr/local/sbin/vpsrouter-zapret.sh
install -o root -g root -m 755 "$PROV_SRC/entry-wg-status.sh" /usr/local/sbin/vpsrouter-wg-status.sh
install -o root -g root -m 755 "$PROV_SRC/pkg-manage.sh" /usr/local/sbin/vpsrouter-pkg-manage.sh
install -o root -g root -m 755 "$PROV_SRC/entry-tgproxy.sh" /usr/local/sbin/vpsrouter-tgproxy.sh
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

# --- config.php ---------------------------------------------------------
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

# --- одноразовый токен «уникальной ссылки» для веб-мастера ---------------
# Мастер работает до создания админа (pre-auth) — запираем его токеном в URL.
# Панель удалит токен и файлы мастера после установки (Installer::selfDestruct).
INSTALL_TOKEN="$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | xxd -p -c 32)"
printf '%s' "$INSTALL_TOKEN" > /var/lib/panel/install-token
chown "$WEB_USER:$WEB_USER" /var/lib/panel/install-token
chmod 640 /var/lib/panel/install-token

# --- инициализация БД (админа/Reality задаст веб-мастер) ----------------
hdr "Инициализация БД" "Initialising DB"
export PANEL_CONFIG_PATH=/etc/panel/config.php
# первое подключение к БД само прогоняет миграции (App\Database::migrate).
# Админа НЕ создаём — его (и Reality, и восстановление из копии) задаст мастер
# в браузере (/install.php). Пустая таблица users => мастер запустится сам.
sudo -u "$WEB_USER" env PANEL_CONFIG_PATH=/etc/panel/config.php "$PHP_BIN" -r 'require "/var/www/panel/src/bootstrap.php"; App\Database::get(); echo "migrations ok\n";'

# --- nginx vhost --------------------------------------------------------
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

# --- TLS (опц.) ---------------------------------------------------------
if [ "$TLS" = y ]; then
    hdr "TLS-сертификат" "TLS certificate"
    pkg_install certbot python3-certbot-nginx || pkg_install certbot
    if command -v certbot >/dev/null 2>&1; then
        certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect \
            || say "certbot не смог выпустить сертификат — проверьте DNS/80 порт и запустите: certbot --nginx -d $DOMAIN" "certbot failed — check DNS/port 80 and run: certbot --nginx -d $DOMAIN"
    fi
fi

# --- firewall: открыть 80/443 для панели --------------------------------
open_port 80/tcp 2>/dev/null || true
open_port 443/tcp 2>/dev/null || true

# --- итог ---------------------------------------------------------------
SCHEME="http"; [ "$TLS" = y ] && SCHEME="https"
hdr "Базовая установка завершена" "Base install complete"
say "Веб-стек, sing-box и код панели установлены. Осталось завершить настройку в браузере." \
    "Web stack, sing-box and the panel code are installed. Finish the setup in your browser."
echo
echo "  ==> $SCHEME://$SERVER_NAME/install.php?t=$INSTALL_TOKEN"
echo
say "Откройте ЭТУ УНИКАЛЬНУЮ ссылку — по ней (и только по ней) запустится ВИЗУАЛЬНЫЙ МАСТЕР:" "Open THIS UNIQUE link — only it starts the VISUAL SETUP WIZARD:"
say "  • проверка окружения (порт 443, панель, сайты) с живым выводом;" "  • environment check (port 443, control panel, sites) with live output;"
say "  • установка с нуля ИЛИ восстановление из копии настроек;" "  • fresh install OR restore from a settings backup;"
say "  • автоподбор IP, генерация Reality-ключей, протоколы;" "  • auto IP, Reality key generation, protocols;"
say "  • создание логина и пароля администратора;" "  • create the administrator login and password;"
say "  • после установки файлы мастера удаляются автоматически." "  • installer files are removed automatically when done."
echo
[ "$TLS" != y ] && [ -n "${DOMAIN:-}" ] && say "HTTPS не выпускался — позже: certbot --nginx -d $DOMAIN" "HTTPS not issued — later: certbot --nginx -d $DOMAIN"
[ -z "${DOMAIN:-}" ] && say "Домен не задан — панель работает по IP по HTTP. Для HTTPS привяжите домен и запустите certbot." "No domain set — the panel runs by IP over HTTP. For HTTPS attach a domain and run certbot."
[ "$TLS" != y ] && say "TLS не выпускался — настройте HTTPS: certbot --nginx -d $DOMAIN" "TLS was not issued — set up HTTPS: certbot --nginx -d $DOMAIN"
say "Рекомендуется cron скана обновлений: 0 */6 * * * $PHP_BIN /var/www/panel/bin/security_scan.php" "Recommended updates-scan cron: 0 */6 * * * $PHP_BIN /var/www/panel/bin/security_scan.php"
