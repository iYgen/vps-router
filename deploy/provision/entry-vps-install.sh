#!/usr/bin/env bash
# Устанавливает ПО, необходимое входной VPS для работы панели: sing-box (всегда)
# и AmneziaWG (awg-quick — для туннелей к exit-серверам и входа устройств
# по AmneziaWG). Работает на Debian/Ubuntu, CentOS 7, CentOS Stream / RHEL /
# Rocky / Alma 8-9, Fedora и др. — логика установки в _lib.sh рядом.
#
# ЛОКАЛЬНЫЙ скрипт — запускается на самой входной VPS через тот же узкий sudo,
# что и apply-router.sh (см. deploy/router/sudoers.d/panel-provision).
# Без аргументов, ничего не читает из окружения вызывающего. Идемпотентен —
# безопасно перезапускать сколько угодно раз.
#
# Установка: скопировать в /usr/local/sbin/vpsrouter-vps-install.sh, chown root:root, chmod 750;
# _lib.sh — в /usr/local/share/panel/provision/ (см. deploy/router/README.md).

set -euo pipefail

log() { echo "[entry-vps-install] $*"; }

LIB=""
for candidate in /usr/local/share/panel/provision/_lib.sh "$(dirname "$0")/_lib.sh"; do
    [ -r "$candidate" ] && { LIB="$candidate"; break; }
done
if [ -z "$LIB" ]; then
    log "ERROR: не найден _lib.sh (ожидается в /usr/local/share/panel/provision/) — скопируйте deploy/provision/*.sh туда"
    exit 1
fi
# shellcheck disable=SC1090
. "$LIB"

detect_os
ensure_base_tools || exit 1

# --- sing-box ----------------------------------------------------------
if command -v sing-box >/dev/null 2>&1 && sing-box version >/dev/null 2>&1; then
    log "sing-box уже установлен: $(sing-box version | head -1)"
else
    install_singbox /usr/local/bin/sing-box || { log "ERROR: sing-box не установлен"; exit 1; }
fi

SB=$(command -v sing-box)
if [ ! -f /etc/systemd/system/sing-box.service ] && [ ! -f /lib/systemd/system/sing-box.service ] && [ ! -f /usr/lib/systemd/system/sing-box.service ]; then
    log "Создаю systemd-юнит sing-box.service"
    cat > /etc/systemd/system/sing-box.service <<EOF
[Unit]
Description=sing-box (panel-managed router)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=$SB run -c /etc/sing-box/config.json
Restart=on-failure
RestartSec=2
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
fi
mkdir -p /etc/sing-box
systemctl enable sing-box >/dev/null 2>&1 || log "WARN: не удалось enable sing-box"

# --- AmneziaWG (awg-quick) ----------------------------------------------
install_amneziawg || log "WARN: AmneziaWG не установлен — туннели AmneziaWG и вход устройств по AmneziaWG работать не будут (остальные протоколы работают)"

log "Готово. sing-box: $(command -v sing-box || echo 'НЕ НАЙДЕН'); awg-quick: $(command -v awg-quick || echo 'НЕ НАЙДЕН')"
