# Ускорение сети на сервере (BBR + fq). Перед этим файлом приклеивается _lib.sh.
#
# Параметры (JSON, $1):
#   action — on | off | status
# Последняя строка вывода: «NETBOOST enabled=0|1 cc=… qdisc=… kernel=…».

set -o pipefail
PARAMS_FILE="${1:?usage: $0 <params.json>}"
ACTION=$(sed -n 's/.*"action"[[:space:]]*:[[:space:]]*"\([a-z]*\)".*/\1/p' "$PARAMS_FILE" | head -1)

case "$ACTION" in
    on) net_boost_on ;;
    off) net_boost_off ;;
    status) net_boost_status ;;
    *) echo "ERROR: action must be on|off|status"; exit 2 ;;
esac
