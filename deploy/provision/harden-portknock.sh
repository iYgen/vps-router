#!/usr/bin/env bash
# Прячет управляющий порт (обычно SSH/22) от массовых сканеров (Censys,
# Shodan, Active Probing) через port knocking на iptables-модуле
# "recent" — без дополнительных демонов. Сканеры почти всегда шлют один
# TCP SYN на порт и не пытаются повторно — не увидев ответа на первый
# пакет, помечают порт закрытым/отфильтрованным.
#
# Последовательность стука — ЛЮБОЙ длины (не фиксированные 3 порта):
# задаётся списком портов в любом порядке, каждый следующий порт в списке
# нужно "стукнуть" после предыдущего в пределах knock_window секунд.
# (Сознательно НЕ реализован повтор одного и того же порта N раз через
# iptables --hitcount — на живом firewall это легко реализовать с ошибкой
# и потерять доступ; тот же эффект даёт более длинная последовательность
# РАЗНЫХ портов, что безопаснее и покрывает тот же смысл "секретного кода".)
#
# КРИТИЧНО ПРО БЕЗОПАСНОСТЬ ВЫПОЛНЕНИЯ:
#   1. ESTABLISHED,RELATED пропускается БЕЗ УСЛОВИЙ первым правилом —
#      уже открытая (в т.ч. текущая SSH-сессия, из-под которой запущен
#      этот скрипт) не обрывается ни при каких условиях.
#   2. IP клиента, который прямо сейчас выполняет этот скрипт по SSH
#      ($SSH_CLIENT), сразу заносится в белый список "постучавшихся" —
#      скрипт не может отрезать сам себя.
#   3. `set -e` останавливает скрипт при любой ошибке ДО применения
#      неполного набора правил.
#
# Параметры (JSON, $1):
#   protected_port     — порт, который прячем (обычно 22)
#   knock_ports         — массив портов для "стука", ЛЮБОЙ длины >= 1, по порядку
#   knock_window        — секунд между стуками (по умолчанию 10)
#   whitelist_seconds   — на сколько секунд IP считается "своим" после стука (по умолчанию 3600)

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[harden-portknock] $*"; }

detect_os
ensure_cmd jq jq || { log "ERROR: не удалось установить jq"; exit 1; }

PORT=$(jq -r '.protected_port' "$PARAMS_FILE")
WINDOW=$(jq -r '.knock_window // 10' "$PARAMS_FILE")
WHITELIST=$(jq -r '.whitelist_seconds // 3600' "$PARAMS_FILE")
mapfile -t KNOCK_PORTS < <(jq -r '.knock_ports[]' "$PARAMS_FILE")

[ -n "$PORT" ] && [ "$PORT" != "null" ] || { log "ERROR: protected_port обязателен"; exit 1; }
[ "${#KNOCK_PORTS[@]}" -ge 1 ] || { log "ERROR: нужен хотя бы 1 порт в knock_ports"; exit 1; }

N=${#KNOCK_PORTS[@]}
GATE_CHAIN="PANEL_GATE_$PORT"
FINAL_MARK="panel_knock_final_$PORT"

# --- Идемпотентность: сносим свои же старые правила/цепочки перед пересозданием.
#     Известное ограничение: если ДЛИНА последовательности изменилась по
#     сравнению с прошлым запуском, старые правила для портов вне нового
#     диапазона не удаляются этим циклом (эффект чисто косметический —
#     лишние неактивные DROP-правила на портах, которые больше никогда не
#     завершат новую последовательность, доступ они не блокируют). ---
iptables -D INPUT -p tcp --dport "$PORT" -j "$GATE_CHAIN" 2>/dev/null || true
for ((i = 0; i < N; i++)); do
    kport="${KNOCK_PORTS[$i]}"
    mark="panel_knock_stage${i}_$PORT"
    chain="PANEL_KNOCK${i}_$PORT"
    if [ "$i" -eq 0 ]; then
        iptables -D INPUT -p tcp --dport "$kport" -m recent --name "$mark" --set -j DROP 2>/dev/null || true
    else
        prev_mark="panel_knock_stage$((i - 1))_$PORT"
        iptables -D INPUT -p tcp --dport "$kport" -m recent --rcheck --name "$prev_mark" --seconds "$WINDOW" -j "$chain" 2>/dev/null || true
        iptables -F "$chain" 2>/dev/null || true
        iptables -X "$chain" 2>/dev/null || true
    fi
done
iptables -F "$GATE_CHAIN" 2>/dev/null || true
iptables -X "$GATE_CHAIN" 2>/dev/null || true
iptables -N "$GATE_CHAIN"

# --- Строим цепочку стука: порт[0] -> порт[1] -> ... -> порт[N-1] -> финальная метка. ---
for ((i = 0; i < N; i++)); do
    kport="${KNOCK_PORTS[$i]}"
    mark="panel_knock_stage${i}_$PORT"
    last=$((N - 1))

    if [ "$i" -eq 0 ] && [ "$i" -eq "$last" ]; then
        # Единственный порт в последовательности — сразу финальная метка.
        iptables -A INPUT -p tcp --dport "$kport" -m recent --name "$FINAL_MARK" --set -j DROP
    elif [ "$i" -eq 0 ]; then
        iptables -A INPUT -p tcp --dport "$kport" -m recent --name "$mark" --set -j DROP
    else
        prev_mark="panel_knock_stage$((i - 1))_$PORT"
        chain="PANEL_KNOCK${i}_$PORT"
        iptables -N "$chain"
        iptables -A INPUT -p tcp --dport "$kport" -m recent --rcheck --name "$prev_mark" --seconds "$WINDOW" -j "$chain"
        iptables -A "$chain" -m recent --name "$prev_mark" --remove
        if [ "$i" -eq "$last" ]; then
            iptables -A "$chain" -p tcp --dport "$kport" -m recent --name "$FINAL_MARK" --set -j DROP
        else
            iptables -A "$chain" -p tcp --dport "$kport" -m recent --name "$mark" --set -j DROP
        fi
    fi
done

# --- Гейт на защищаемый порт: ESTABLISHED — всегда, иначе — только если полностью отстучал. ---
iptables -A "$GATE_CHAIN" -m state --state ESTABLISHED,RELATED -j ACCEPT
iptables -A "$GATE_CHAIN" -m recent --rcheck --name "$FINAL_MARK" --seconds "$WHITELIST" -j ACCEPT
iptables -A "$GATE_CHAIN" -j DROP
iptables -I INPUT -p tcp --dport "$PORT" -j "$GATE_CHAIN"

# --- Не отрезаем сами себя: текущий SSH-клиент сразу помечается как "постучался". ---
CURRENT_IP="${SSH_CLIENT%% *}"
if [ -n "${CURRENT_IP:-}" ]; then
    iptables -I "$GATE_CHAIN" 1 -s "$CURRENT_IP" -m recent --name "$FINAL_MARK" --set -j ACCEPT
    log "Текущий клиент $CURRENT_IP сразу добавлен в белый список порта $PORT"
fi

if command -v netfilter-persistent >/dev/null 2>&1; then
    netfilter-persistent save >/dev/null 2>&1 || true
elif command -v service >/dev/null 2>&1 && [ -f /etc/sysconfig/iptables ]; then
    service iptables save >/dev/null 2>&1 || true
fi

SEQ=$(printf '%s -> ' "${KNOCK_PORTS[@]}"); SEQ=${SEQ% -> }
log "Готово: порт $PORT спрятан, стук — $SEQ (окно ${WINDOW}с между шагами), белый список на ${WHITELIST}с"
log "ВАЖНО: клиент, который будет подключаться к $PORT штатно (например входной VPS), должен сначала постучать в эту последовательность по порядку"
