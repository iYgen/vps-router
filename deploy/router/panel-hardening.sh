#!/usr/bin/env bash
# Закрывает снаружи сервисы, опасные для IP, на котором живут обычные сайты
# (открытый прокси, узнаваемый Active Blocking System WireGuard, FTP с паролями открытым текстом),
# и восстанавливает правила панели после перезагрузки. Идемпотентен.
#
# Установка: /usr/local/sbin/panel-hardening.sh (root:root 750) +
# /etc/systemd/system/panel-hardening.service (запуск при загрузке после docker).
# Откат: systemctl disable --now panel-hardening; iptables -D INPUT -j PANEL-HARDEN;
#        iptables -D DOCKER-USER -j PANEL-HARDEN-DOCKER; docker start amnezia-wireguard

set -euo pipefail
log() { echo "[panel-hardening] $*"; }

WAN_IF=$(ip -4 route show default | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')
WAN_IF=${WAN_IF:-eth0}

chain() { # table chain parent
    iptables -t "$1" -N "$2" 2>/dev/null || true
    iptables -t "$1" -F "$2"
    iptables -t "$1" -C "$3" -j "$2" 2>/dev/null || iptables -t "$1" -I "$3" 1 -j "$2"
}

# --- Сервисы на самом хосте -------------------------------------------------
chain filter PANEL-HARDEN INPUT
# FTP (пароли открытым текстом; файлы — через SFTP по SSH) и веб-интерфейс ISPmanager
# (лицензия закончилась, не используется; служба ihttpd отключена).
iptables -A PANEL-HARDEN -i "$WAN_IF" -p tcp -m multiport --dports 20,21,1500 -j DROP
log "FTP 20-21/tcp и ISPmanager 1500/tcp закрыты снаружи"

# --- Опубликованные порты Docker (обходят INPUT, фильтруются в DOCKER-USER) -
if iptables -L DOCKER-USER -n >/dev/null 2>&1; then
    chain filter PANEL-HARDEN-DOCKER DOCKER-USER
    # Открытый SOCKS5 без пароля (amnezia-socks5proxy).
    iptables -A PANEL-HARDEN-DOCKER -i "$WAN_IF" -p tcp --dport 10808 -j DROP
    log "SOCKS5 10808/tcp закрыт снаружи (из контейнеров/локально доступен)"
fi

# Обычный WireGuard в контейнере — Active Blocking System узнаёт его мгновенно.
if command -v docker >/dev/null 2>&1 && docker inspect amnezia-wireguard >/dev/null 2>&1; then
    docker update --restart=no amnezia-wireguard >/dev/null
    if [ "$(docker inspect -f '{{.State.Running}}' amnezia-wireguard)" = "true" ]; then
        docker stop amnezia-wireguard >/dev/null
    fi
    log "amnezia-wireguard остановлен и не стартует автоматически"
fi

# --- Правила панели (порты протоколов, AmneziaWG-вход) после перезагрузки ----
if [ "${1:-}" = "--boot" ] && [ -x /usr/local/sbin/apply-router.sh ]; then
    /usr/local/sbin/apply-router.sh || log "WARN: apply-router.sh завершился с ошибкой"
fi

log "Готово"
