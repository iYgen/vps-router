#!/usr/bin/env bash
# vps_router — preflight «доктор»: READ-ONLY проверка окружения перед установкой.
# Ничего не меняет. Определяет, свободен ли 443, кто им владеет, есть ли панель
# управления и сайты, сколько IP — и классифицирует ситуацию GREEN/YELLOW/RED,
# подсказывая, что делать с разделением порта 443 между сайтами и VPN.
#
# Запуск:  sudo bash deploy/preflight.sh
# Код возврата: 0 = GREEN, 10 = YELLOW, 20 = RED, 1 = не удалось определить.
set -u

# Цвета только при выводе в терминал (в пайпе/логе — без ANSI-мусора).
if [ -t 1 ]; then
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
    C_GREEN=""; C_YELLOW=""; C_RED=""; C_DIM=""; C_OFF=""
fi
hdr() { echo; echo "==== $* ===="; }
have() { command -v "$1" >/dev/null 2>&1; }

# --- кто слушает порт (возвращает "процесс" или пусто) --------------------
port_owner() {
    local port="$1" out=""
    if have ss; then
        out=$(ss -H -ltnp "sport = :$port" 2>/dev/null | grep -oE 'users:\(\("[^"]+' | sed 's/.*"//' | sort -u | paste -sd, -)
    elif have lsof; then
        out=$(lsof -iTCP:"$port" -sTCP:LISTEN -Pn 2>/dev/null | awk 'NR>1{print $1}' | sort -u | paste -sd, -)
    fi
    printf '%s' "$out"
}

# --- панель управления хостингом -----------------------------------------
detect_panel() {
    [ -d /usr/local/mgr5 ]        && { echo "ISPmanager"; return; }
    [ -d /usr/local/cpanel ]      && { echo "cPanel"; return; }
    [ -d /usr/local/psa ] || [ -d /opt/psa ] && { echo "Plesk"; return; }
    [ -d /usr/local/hestia ]      && { echo "HestiaCP"; return; }
    [ -d /usr/local/fastpanel2 ] || [ -d /usr/local/fastpanel ] && { echo "FASTPANEL"; return; }
    [ -d /www/server/panel ]      && { echo "aaPanel/BT"; return; }
    echo ""
}

# --- домены существующих сайтов (nginx/apache), кроме служебных -----------
detect_site_domains() {
    local domains=""
    if have nginx; then
        domains=$(nginx -T 2>/dev/null | grep -E '^\s*server_name' \
            | sed -E 's/^\s*server_name\s+//; s/;.*//' | tr ' ' '\n' \
            | grep -vE '^(_|localhost|)$' | sort -u | paste -sd' ' -)
    fi
    printf '%s' "$domains"
}

count_public_ips() {
    if have ip; then
        ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 \
            | grep -vE '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|127\.)' | sort -u | wc -l
    else
        echo "?"
    fi
}

# =========================================================================
hdr "vps_router preflight — проверка окружения (ничего не меняется)"

if have ss || have lsof; then :; else
    echo "${C_DIM}Нет ss/lsof — проверка портов ограничена. Поставьте iproute2/lsof для точности.${C_OFF}"
fi

PANEL=$(detect_panel)
OWNER443=$(port_owner 443)
OWNER80=$(port_owner 80)
SITES=$(detect_site_domains)
NIPS=$(count_public_ips)

echo "Панель управления : ${PANEL:-нет}"
echo "Порт 443 слушает  : ${OWNER443:-свободен}"
echo "Порт 80  слушает  : ${OWNER80:-свободен}"
echo "Публичных IPv4    : ${NIPS}"
echo "Домены сайтов     : ${SITES:-не найдено}"
if have openssl; then
    echo "OpenSSL           : $(openssl version 2>/dev/null) ${C_DIM}(TLS1.3 нужен для Reality steal-oneself; старый OpenSSL → используем локальный TLS-фронт)${C_OFF}"
fi

# --- классификация -------------------------------------------------------
hdr "Вывод"
RC=0
if [ -z "$OWNER443" ]; then
    echo "${C_GREEN}🟢 GREEN: порт 443 свободен.${C_OFF}"
    echo "  Устанавливаем как есть. Reality/inbound'ы могут слушать 443 напрямую."
    RC=0
elif [ -n "$PANEL" ]; then
    echo "${C_RED}🔴 RED: порт 443 держит панель управления «$PANEL».${C_OFF}"
    echo "  Панель сама генерирует vhost'ы и может перезаписать правки 443."
    echo "  Полностью авто — небезопасно (тихо сломается при следующем изменении сайта в панели)."
    echo "  ▸ Разделить 443 между сайтами и VPN поможет:  deploy/router/share-443.sh"
    echo "    Он делает бэкап, ставит stream-демукс по SNI и переносит сайты на внутренний порт"
    echo "    с health-check и авто-откатом. Запускать с доступом к консоли сервера."
    echo "  ▸ Долговременно закрепить в «$PANEL» — через её настройку «альтернативный порт nginx»."
    RC=20
else
    echo "${C_YELLOW}🟡 YELLOW: порт 443 держит «${OWNER443}» (без панели управления).${C_OFF}"
    if [ "$NIPS" = "1" ] || [ "$NIPS" = "?" ]; then
        echo "  Один IP + сайты на 443. Разделить порт между сайтами и VPN можно автоматически:"
    else
        echo "  Есть несколько IP — можно и разнести по IP, и разделить один 443."
    fi
    echo "  ▸ deploy/router/share-443.sh — бэкап → stream-демукс по SNI → перенос сайтов"
    echo "    на 127.0.0.1:8443 → health-check → авто-откат при сбое. Downtime — секунды."
    RC=10
fi

echo
echo "${C_DIM}Это только диагностика. Ничего не изменено.${C_OFF}"
exit $RC
