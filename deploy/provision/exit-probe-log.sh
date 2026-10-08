#!/usr/bin/env bash
# Включает/снимает ТОЛЬКО логирование входящих новых соединений на Reality-порт
# exit-сервера. Реализовано в ОТДЕЛЬНОЙ изолированной таблице nftables
# (inet vpsr_probe), правило — LOG-only с rate-limit:
#   • LOG не терминирует пакет и не фильтрует — НИЧЕГО не блокирует;
#   • отдельная таблица не пересекается с боевым firewall — положить сервер нельзя;
#   • снимается одной командой (nft delete table inet vpsr_probe).
# Логи идут в kernel log (journalctl -k) с префиксом "VPSR_PROBE"; панель
# вычитывает их по SSH (pull) и агрегирует «кто стучался».
#
# Вызывается панелью через Ssh::runProvisionScript — параметры в $1 (JSON):
#   {"action":"install"|"remove","port":443}
set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[probe-log] $*"; }

ACTION=$(jq -r '.action // "install"' "$PARAMS_FILE" 2>/dev/null || echo install)
PORT=$(jq -r '.port // 443' "$PARAMS_FILE" 2>/dev/null || echo 443)
case "$PORT" in '' | *[!0-9]*) PORT=443 ;; esac
[ "$PORT" -ge 1 ] 2>/dev/null && [ "$PORT" -le 65535 ] 2>/dev/null || PORT=443

if ! command -v nft >/dev/null 2>&1; then
    log "ERROR: nftables (nft) не найден — логирование зондирований недоступно на этом сервере"
    exit 1
fi

# Снятие — просто удаляем нашу изолированную таблицу (боевой firewall не трогаем).
if [ "$ACTION" = "remove" ]; then
    nft delete table inet vpsr_probe 2>/dev/null || true
    log "удалено: таблица nftables inet vpsr_probe (логирование выключено)"
    exit 0
fi

# Установка идемпотентна: пересоздаём таблицу заново.
nft delete table inet vpsr_probe 2>/dev/null || true
nft add table inet vpsr_probe
# Базовая цепочка с policy accept: сама по себе ничего не фильтрует (accept не
# отменяет drop'ы боевых цепочек). DROP-правила ниже бьют ТОЛЬКО по Reality-порту
# заданных IP — SSH и прочее не трогают, положить сервер нельзя.
nft add chain inet vpsr_probe probe_in "{ type filter hook input priority 0 ; policy accept ; }"

# DROP для заблокированных IP (только на tcp/$PORT) — ставим ПЕРЕД LOG.
BLOCKED=$(jq -r '.blocked[]? // empty' "$PARAMS_FILE" 2>/dev/null || true)
nblocked=0
for ip in $BLOCKED; do
    # принимаем только валидный IPv4 (защита от инъекций в nft)
    if printf '%s' "$ip" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$'; then
        nft add rule inet vpsr_probe probe_in ip saddr "$ip" tcp dport "$PORT" drop && nblocked=$((nblocked+1))
    fi
done

nft add rule inet vpsr_probe probe_in tcp dport "$PORT" ct state new \
    limit rate 20/second burst 40 packets log prefix \"VPSR_PROBE \" level info

log "включено: LOG на tcp/$PORT + DROP для $nblocked IP (таблица inet vpsr_probe, rate 20/s)."
log "снять всё: nft delete table inet vpsr_probe"
