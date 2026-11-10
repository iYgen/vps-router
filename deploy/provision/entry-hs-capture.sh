#!/usr/bin/env bash
# Handshake watch: short, bounded packet capture of ONE client IP on the entry
# node, started by the panel when a wave of failed handshakes begins.
#
#   vpsrouter-hs-capture.sh start <wave_id> <client_ip> <seconds>
#
# The panel (unprivileged web user) calls it via sudo; every argument is
# validated here. Writes <captures>/wave-<id>.pcap (headers only: snaplen 256,
# max 50k packets, 10..300 s), then creates wave-<id>.pcap.done and hands both
# files to the owner of the panel data dir. Only one capture runs at a time.
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

DATA_DIR=/var/lib/panel
CAP_DIR="$DATA_DIR/captures"
PID_FILE=/run/vpsrouter-hs-capture.pid

die() { echo "error: $*" >&2; exit 2; }

[ "${1:-}" = "start" ] || die "usage: $0 start <wave_id> <client_ip> <seconds>"
ID="${2:-}"; IP="${3:-}"; SECS="${4:-}"

[[ "$ID" =~ ^[0-9]{1,10}$ ]] || die "bad wave id"
[[ "$SECS" =~ ^[0-9]{1,3}$ ]] && [ "$SECS" -ge 10 ] && [ "$SECS" -le 300 ] || die "bad duration"
if [[ "$IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    :
elif [[ "$IP" =~ ^[0-9a-fA-F:]{2,39}$ ]] && [[ "$IP" == *:* ]]; then
    :
else
    die "bad ip"
fi
command -v tcpdump >/dev/null 2>&1 || die "tcpdump not installed"
[ -d "$DATA_DIR" ] || die "no $DATA_DIR"

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE" 2>/dev/null)" 2>/dev/null; then
    die "another capture is running"
fi

OWNER="$(stat -c %U "$DATA_DIR")"
GROUP="$(stat -c %G "$DATA_DIR")"
install -d -m 750 -o "$OWNER" -g "$GROUP" "$CAP_DIR"

OUT="$CAP_DIR/wave-$ID.pcap"
rm -f "$OUT" "$OUT.done"

# Detached: the panel cron must not wait for the capture.
setsid bash -c '
    timeout "$1" tcpdump -i any -nn -s 256 -c 50000 -Z root -w "$2" "host $3 and tcp" >/dev/null 2>&1 || true
    [ -f "$2" ] || : > "$2"
    chown "$4:$5" "$2"; chmod 640 "$2"
    : > "$2.done"; chown "$4:$5" "$2.done"
    rm -f "$6"
' _ "$SECS" "$OUT" "$IP" "$OWNER" "$GROUP" "$PID_FILE" </dev/null >/dev/null 2>&1 &
echo $! > "$PID_FILE"
echo "started wave-$ID.pcap"
