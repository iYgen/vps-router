#!/usr/bin/env bash
# Read-only дамп пиров WireGuard и AmneziaWG на входной роутере (self) — для монитора
# «жив ли вход» (Keenetic → вход). Формат `wg show all dump`:
#   интерфейс: iface  privkey  pubkey  listen-port  fwmark            (5 колонок)
#   пир:       iface  pubkey  psk  endpoint  allowed-ips  last-handshake  rx  tx  keepalive (9)
# Панель читает его по sudo (без аргументов) и парсит. Ничего не меняет.
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

for cmd in wg awg; do
    command -v "$cmd" >/dev/null 2>&1 || continue
    "$cmd" show all dump 2>/dev/null || true
done
