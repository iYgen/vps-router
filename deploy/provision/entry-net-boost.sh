#!/usr/bin/env bash
# Ускорение сети на сервере самой панели. Панель (веб-пользователь) вызывает через sudo:
#   vpsrouter-net-boost.sh on|off|status
# Аргумент проверяется здесь; функции — из _lib.sh рядом с остальными скриптами установки.
set -o pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
LIB=/usr/local/share/panel/provision/_lib.sh
[ -r "$LIB" ] || { echo "error: no $LIB"; exit 2; }
# shellcheck source=/dev/null
. "$LIB"
case "${1:-}" in
    on) net_boost_on ;;
    off) net_boost_off ;;
    status) net_boost_status ;;
    *) echo "usage: $0 on|off|status"; exit 2 ;;
esac
