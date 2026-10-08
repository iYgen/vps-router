#!/usr/bin/env bash
# Привилегированный шаг мастера: доустановка тяжёлых компонентов после тонкого
# bootstrap'а — sing-box (ядро маршрутизации), wireguard-tools и (опц.) AmneziaWG.
#
# Правила безопасности (как у apply-router.sh / share-443.sh):
#   • НЕ принимает аргументов — www-data вызывает его через sudo без argv;
#   • всё, что нужно, читает из фиксированного файла /var/lib/panel/install-components.conf
#     (пишет панель), строго проверяя формат;
#   • идемпотентен — если компонент уже стоит, _lib.sh это увидит и не тронет.
#
# Установка: deploy/bootstrap.sh (и install.sh) кладут его в
#   /usr/local/sbin/vpsrouter-install-components.sh (root:root, 0755)
# и разрешают www-data через /etc/sudoers.d/panel-install.
set -euo pipefail

# _lib.sh рядом (provision-каталог) при ручном запуске из репозитория, либо в
# /usr/local/share/panel/provision после установки.
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for cand in "$SELF_DIR/_lib.sh" /usr/local/share/panel/provision/_lib.sh; do
    if [ -r "$cand" ]; then LIB="$cand"; break; fi
done
if [ -z "${LIB:-}" ]; then
    echo "[components] ERROR: не найден _lib.sh" >&2
    exit 1
fi
# shellcheck disable=SC1090
. "$LIB"

CONF="/var/lib/panel/install-components.conf"

# По умолчанию ставим всё; AmneziaWG можно отключить флагом в conf.
WANT_AMNEZIA=1
if [ -s "$CONF" ]; then
    # Принимаем только строго ожидаемый формат AMNEZIA=0|1 (файл пишет www-data).
    v=$(sed -n 's/^AMNEZIA=\([01]\)$/\1/p' "$CONF" | head -1)
    [ -n "$v" ] && WANT_AMNEZIA="$v"
fi

detect_os
ensure_base_tools || { echo "[components] $(_t "ERROR: не удалось поставить базовые утилиты" "ERROR: failed to install base tools")"; exit 1; }

echo "[components] $(_t "Устанавливаю sing-box..." "Installing sing-box...")"
if command -v sing-box >/dev/null 2>&1; then
    echo "[components] $(_t "sing-box уже установлен" "sing-box already installed"): $(sing-box version 2>/dev/null | head -1)"
else
    install_singbox /usr/local/bin/sing-box || { echo "[components] $(_t "ERROR: sing-box не установлен" "ERROR: sing-box not installed")"; exit 1; }
fi

echo "[components] $(_t "Устанавливаю wireguard-tools..." "Installing wireguard-tools...")"
install_wireguard_tools || echo "[components] $(_t "WARN: wireguard-tools не установлены (WG-туннели могут не работать)" "WARN: wireguard-tools not installed (WG tunnels may not work)")"

if [ "$WANT_AMNEZIA" = 1 ]; then
    echo "[components] $(_t "Устанавливаю AmneziaWG (модуль ядра + tools)..." "Installing AmneziaWG (kernel module + tools)...")"
    install_amneziawg || echo "[components] $(_t "WARN: AmneziaWG не установлен — остальные протоколы работают" "WARN: AmneziaWG not installed — other protocols still work")"
else
    echo "[components] $(_t "AmneziaWG пропущен по выбору в мастере." "AmneziaWG skipped by choice in the wizard.")"
fi

# Форвардинг нужен для NAT туннелей и входа устройств.
enable_ip_forward 2>/dev/null || true

echo "[components] $(_t "Готово." "Done.")"
