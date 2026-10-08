#!/usr/bin/env bash
# Правила ufw для входной VPS. Выполнять построчно/осмысленно, не вслепую —
# подставить реальные значения IP/портов перед запуском.
#
# ВАЖНО: не трогает существующие правила для первого IP (сайты) — ufw
# правила по умолчанию применяются глобально к портам, но т.к. Reality
# слушает НА ВТОРОМ IP, правило ниже сузено именно до него через "to <ip>".

set -euo pipefail

SECOND_IP="203.0.113.10"   # заменить на реальный второй IPv4
PANEL_SSH_ADMIN_IP=""      # опционально: сузить SSH до конкретного IP администратора

# Reality inbound только на втором IP.
ufw allow in on eth0 to "$SECOND_IP" port 443 proto tcp comment 'sing-box reality'

# Исходящий WireGuard/AmneziaWG к exit-серверам — как правило не требует
# отдельного inbound-правила (исходящие разрешены по умолчанию), но если
# у вас default deny outgoing — раскомментировать и указать порты exit-серверов:
# ufw allow out to any port 51820 proto udp comment 'awg to exit servers'

# Панель управления слушает на первом IP:443 через существующий nginx —
# отдельного правила не требуется, если 443 на первом IP уже открыт.

if [ -n "$PANEL_SSH_ADMIN_IP" ]; then
    ufw allow from "$PANEL_SSH_ADMIN_IP" to any port 22 proto tcp comment 'ssh admin only'
fi

ufw status verbose
