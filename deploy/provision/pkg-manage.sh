#!/usr/bin/env bash
# vpsrouter package manager helper — проверка и установка обновлений ОС-пакетов.
# Поддерживает apt (Debian/Ubuntu), dnf и yum (RHEL/CentOS/Alma/Rocky).
#
# Режимы (единственный аргумент, строго из белого списка — пользовательский
# ввод в скрипт не попадает):
#   count    — быстро: сколько пакетов можно обновить (и сколько security).
#   upgrade  — ЗАПУСКАЕТ обновление В ФОНЕ (detached) с логом, сразу возвращает
#              управление (веб-запрос не блокируется на долгом apt/yum).
#   log      — состояние фонового обновления (running=0|1) + хвост лога.
#
# Безопасность: ничего не принимает из argv кроме имени режима; запускается
# панелью по sudo (для self-узла — через /etc/sudoers.d/panel-pkg). На удалённых
# серверах тот же скрипт панель передаёт по SSH и исполняет под root.
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export DEBIAN_FRONTEND=noninteractive LC_ALL=C

LOG="/var/lib/panel/pkg-upgrade.log"
LOCK="/var/lib/panel/pkg-upgrade.lock"
mkdir -p /var/lib/panel 2>/dev/null || true

detect() {
    if command -v apt-get >/dev/null 2>&1; then echo apt
    elif command -v dnf >/dev/null 2>&1; then echo dnf
    elif command -v yum >/dev/null 2>&1; then echo yum
    else echo none; fi
}
MGR=$(detect)

running() {
    # Жив ли фоновый процесс обновления? Лок содержит его PID.
    [ -f "$LOCK" ] || { echo 0; return; }
    pid=$(cat "$LOCK" 2>/dev/null || echo)
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then echo 1; else echo 0; fi
}

do_count() {
    echo "manager=$MGR"
    local total=0 sec=0
    case "$MGR" in
        apt)
            # apt list --upgradable не требует root; индекс обновляем «молча».
            apt-get update -qq >/dev/null 2>&1 || true
            total=$(apt-get -s -o Debug::NoLocking=true upgrade 2>/dev/null | grep -c '^Inst ' || true)
            sec=$(apt-get -s -o Debug::NoLocking=true upgrade 2>/dev/null | grep -c '^Inst .*[Ss]ecurity' || true)
            ;;
        dnf)
            # check-update: код 100 = есть обновления, 0 = нет.
            total=$(dnf -q check-update 2>/dev/null | grep -cE '^[a-zA-Z0-9]' || true)
            sec=$(dnf -q updateinfo list security 2>/dev/null | grep -cE '^[A-Z]' || true)
            ;;
        yum)
            total=$(yum -q check-update 2>/dev/null | grep -cE '^[a-zA-Z0-9]' || true)
            sec=$(yum -q --security check-update 2>/dev/null | grep -cE '^[a-zA-Z0-9]' || true)
            ;;
    esac
    echo "count=${total:-0}"
    echo "security=${sec:-0}"
    echo "running=$(running)"
}

do_upgrade() {
    if [ "$(running)" = 1 ]; then echo "status=running"; return; fi
    if [ "$MGR" = none ]; then echo "status=error" ; echo "msg=no package manager"; return; fi
    local cmd
    case "$MGR" in
        apt) cmd='apt-get update -qq && apt-get -y -o Dpkg::Options::=--force-confold upgrade' ;;
        dnf) cmd='dnf -y upgrade' ;;
        yum) cmd='yum -y update' ;;
    esac
    : > "$LOG"
    {
        echo "=== $(date -u +%Y-%m-%dT%H:%M:%SZ) start ($MGR) ==="
    } >> "$LOG"
    # Полностью отвязываем от SSH/веб-сессии: setsid + nohup, stdin из /dev/null.
    setsid bash -c "$cmd >> '$LOG' 2>&1; echo \"=== \$(date -u +%Y-%m-%dT%H:%M:%SZ) done rc=\$? ===\" >> '$LOG'; rm -f '$LOCK'" </dev/null >/dev/null 2>&1 &
    echo $! > "$LOCK"
    echo "status=started"
}

do_log() {
    echo "running=$(running)"
    if [ -f "$LOG" ]; then tail -n 200 "$LOG"; fi
}

case "${1:-}" in
    count)   do_count ;;
    upgrade) do_upgrade ;;
    log)     do_log ;;
    *)       echo "usage: $0 {count|upgrade|log}" >&2; exit 2 ;;
esac
