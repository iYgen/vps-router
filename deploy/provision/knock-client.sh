#!/usr/bin/env bash
# Отправляет последовательность "стуков" (TCP SYN) на сервер, защищённый
# harden-portknock.sh, чтобы затем можно было подключиться к спрятанному
# порту. Использование: knock-client.sh <host> <port1> [port2] [port3] ...
# Любое количество портов, по порядку. Требует только bash (через
# /dev/tcp), без nc/nmap.

set -euo pipefail

HOST="${1:?usage: $0 <host> <port1> [port2] [port3] ...}"
shift

[ "$#" -ge 1 ] || { echo "usage: $0 <host> <port1> [port2] ..." >&2; exit 1; }

knock() {
    timeout 2 bash -c "cat < /dev/null > /dev/tcp/$HOST/$1" 2>/dev/null || true
}

SEQ=""
for port in "$@"; do
    knock "$port"
    SEQ="$SEQ $port ->"
    sleep 0.5
done

echo "Постучал в $HOST:${SEQ% ->}. Теперь можно подключаться к защищённому порту."
