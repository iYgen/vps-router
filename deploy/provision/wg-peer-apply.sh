#!/usr/bin/env bash
# Добавляет или убирает ОДНОГО дополнительного WireGuard-пира (домашнее
# устройство, например Keenetic) на уже существующем интерфейсе
# exit-сервера, не трогая остальных пиров (в первую очередь — пира входной VPS,
# который создаёт exit-wireguard.sh).
#
# ВАЖНО: если после этого будет запущен полный exit-wireguard.sh повторно
# (переустановка/пересоздание связи в панели), он перезапишет
# /etc/wireguard/<iface>.conf заново только с одним пиром (входной VPS) — все
# пиры, добавленные этим скриптом, из файла пропадут (хотя останутся в БД
# панели и будут повторно применены при следующем вызове add-peer). Это
# известное ограничение, см. docs/infrastructure-ui.md TODO.
#
# Ожидаемые поля в params.json:
#   interface_name, peer_public_key, peer_allowed_ip, action ("add"|"remove")

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[wg-peer-apply] $*"; }

detect_os
ensure_cmd jq jq || { log "ERROR: не удалось установить jq"; exit 1; }

IFACE=$(jq -r '.interface_name' "$PARAMS_FILE")
PEER_PUBKEY=$(jq -r '.peer_public_key' "$PARAMS_FILE")
PEER_ALLOWED_IP=$(jq -r '.peer_allowed_ip' "$PARAMS_FILE")
ACTION=$(jq -r '.action // "add"' "$PARAMS_FILE")

for v in "$IFACE" "$PEER_PUBKEY" "$PEER_ALLOWED_IP"; do
    [ -n "$v" ] && [ "$v" != "null" ] || { log "ERROR: не все обязательные параметры заданы"; exit 1; }
done

CONF="/etc/wireguard/${IFACE}.conf"
[ -f "$CONF" ] || { log "ERROR: $CONF не найден — сначала настройте базовый WireGuard-интерфейс (exit-wireguard.sh)"; exit 1; }
ip link show "$IFACE" >/dev/null 2>&1 || { log "ERROR: интерфейс $IFACE не поднят"; exit 1; }

if [ "$ACTION" = "remove" ]; then
    log "Убираю пира $PEER_PUBKEY с $IFACE"
    wg set "$IFACE" peer "$PEER_PUBKEY" remove || true
    # Вырезаем блок [Peer]...(до следующего [Peer] или конца файла), который
    # содержит строку "PublicKey = <этот ключ>" — всё, что шло ДО первого
    # [Peer] (секция [Interface]), копируется как есть.
    awk -v pk="PublicKey = $PEER_PUBKEY" '
        BEGIN { buf=""; drop=0 }
        /^\[Peer\]/ {
            if (buf != "" && !drop) printf "%s", buf
            buf = $0 "\n"; drop=0; next
        }
        {
            buf = buf $0 "\n"
            if ($0 == pk) drop=1
        }
        END { if (buf != "" && !drop) printf "%s", buf }
    ' "$CONF" > "${CONF}.tmp" && mv "${CONF}.tmp" "$CONF"
    chmod 600 "$CONF"
    log "Готово: пир убран"
    exit 0
fi

# action == add — идемпотентно: если ключ уже прописан в файле, просто
# синхронизируем live-состояние и выходим.
if grep -qF "$PEER_PUBKEY" "$CONF"; then
    log "Пир уже есть в $CONF, применяю live-состояние"
else
    log "Добавляю пира $PEER_PUBKEY ($PEER_ALLOWED_IP) в $CONF"
    {
        echo ""
        echo "[Peer]"
        echo "PublicKey = $PEER_PUBKEY"
        echo "AllowedIPs = $PEER_ALLOWED_IP"
    } >> "$CONF"
    chmod 600 "$CONF"
fi

wg set "$IFACE" peer "$PEER_PUBKEY" allowed-ips "$PEER_ALLOWED_IP"
log "Готово: пир применён"
