#!/usr/bin/env bash
# Port-scan detection + auto-ban — отдельный механизм от port knocking
# (deploy/provision/harden-portknock.sh): тот прячет ОДИН управляющий порт,
# этот банит источник целиком, если он трогает "порты-приманки" —
# заведомо неиспользуемые на этом сервере порты, к которым легитимный
# клиент никогда не обратится. Массовые сканеры (в т.ч. Active Probing
#) обычно перебирают много портов подряд с одного адреса за короткое
# время — именно на этом их и ловим, ДО того как они соберут полную
# картину реальных сервисов сервера.
#
# КРИТИЧНО ПРО БЕЗОПАСНОСТЬ ВЫПОЛНЕНИЯ (тот же принцип, что и в
# harden-portknock.sh — см. инцидент 2026-09-23 с Keenetic, где недостаток
# осторожности к деструктивным live-изменениям уже стоил реальных проблем):
#   1. ESTABLISHED,RELATED пропускается БЕЗ УСЛОВИЙ первым правилом —
#      уже открытые соединения (включая текущую SSH-сессию) не рвутся.
#   2. IP клиента, который прямо сейчас выполняет этот скрипт по SSH
#      ($SSH_CLIENT), НИКОГДА не попадает под бан этим механизмом.
#   3. Список decoy_ports НЕ должен содержать порт, который реально
#      слушает служба на этом сервере — скрипт явно проверяет это и
#      отказывается запускаться, если такое совпадение найдено (защита от
#      забанивания собственных легитимных клиентов).
#   4. `set -e` останавливает скрипт при любой ошибке до применения
#      неполного набора правил.
#
# Параметры (JSON, $1):
#   decoy_ports    — массив портов-приманок (заведомо не используемых на сервере)
#   ban_seconds    — на сколько секунд банить источник после касания приманки
#   protected_ports — порты, реально используемые сервером (SSH + основной
#                     сервис) — только для проверки пересечения с decoy_ports

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[harden-portscan-ban] $*"; }

detect_os
ensure_cmd jq jq || { log "ERROR: не удалось установить jq"; exit 1; }

mapfile -t DECOY_PORTS < <(jq -r '.decoy_ports[]' "$PARAMS_FILE")
BAN_SECONDS=$(jq -r '.ban_seconds // 86400' "$PARAMS_FILE")
mapfile -t PROTECTED_PORTS < <(jq -r '.protected_ports[]' "$PARAMS_FILE")

[ "${#DECOY_PORTS[@]}" -ge 1 ] || { log "ERROR: нужен хотя бы 1 порт в decoy_ports"; exit 1; }

for dp in "${DECOY_PORTS[@]}"; do
    for pp in "${PROTECTED_PORTS[@]}"; do
        if [ "$dp" = "$pp" ]; then
            log "ERROR: порт-приманка $dp совпадает с реально используемым портом сервера — отказ, чтобы не забанить легитимных клиентов"
            exit 1
        fi
    done
done

CHAIN="PANEL_SCANBAN"
MARK="panel_scanner"

# --- Идемпотентность: сносим свои же старые правила/цепочку перед пересозданием. ---
iptables -D INPUT -j "$CHAIN" 2>/dev/null || true
DECOY_CSV=$(IFS=,; echo "${DECOY_PORTS[*]}")
iptables -D INPUT -p tcp --syn -m multiport --dports "$DECOY_CSV" -m recent --name "$MARK" --set -j DROP 2>/dev/null || true
iptables -F "$CHAIN" 2>/dev/null || true
iptables -X "$CHAIN" 2>/dev/null || true
iptables -N "$CHAIN"

# --- Цепочка: свои/установленные соединения — всегда мимо бана. ---
CURRENT_IP="${SSH_CLIENT%% *}"
if [ -n "${CURRENT_IP:-}" ]; then
    iptables -A "$CHAIN" -s "$CURRENT_IP" -j RETURN
    log "Текущий клиент $CURRENT_IP исключён из бана навсегда (эта сессия)"
fi
iptables -A "$CHAIN" -m state --state ESTABLISHED,RELATED -j RETURN
iptables -A "$CHAIN" -m recent --name "$MARK" --rcheck --seconds "$BAN_SECONDS" -j DROP
iptables -A "$CHAIN" -j RETURN
iptables -I INPUT 1 -j "$CHAIN"

# --- Приманка: SYN на decoy-порт помечает источник как сканер (сам порт всегда DROP — сервиса там нет и не будет). ---
iptables -A INPUT -p tcp --syn -m multiport --dports "$DECOY_CSV" -m recent --name "$MARK" --set -j DROP

if command -v netfilter-persistent >/dev/null 2>&1; then
    netfilter-persistent save >/dev/null 2>&1 || true
elif command -v service >/dev/null 2>&1 && [ -f /etc/sysconfig/iptables ]; then
    service iptables save >/dev/null 2>&1 || true
fi

log "Готово: приманки на портах [$DECOY_CSV], бан на ${BAN_SECONDS}с при касании"
