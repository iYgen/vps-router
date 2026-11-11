# Установка панели VPS Router на чистый VPS прямо из приложения:
# git clone репозитория + тонкий bootstrap (deploy/bootstrap.sh), ответы на его
# вопросы (язык, домен) подаются через stdin. В конце bootstrap печатает ссылку
# мастера установки install.php?t=<токен> — приложение её показывает.
#
# Параметры (JSON, $1):
#   repo   — URL git-репозитория (https://github.com/iYgen/vps-router.git)
#   branch — ветка (main)
#   domain — домен панели (пусто = по IP)
#   lang   — ru | en

set -euo pipefail

PARAMS_FILE="${1:?usage: $0 <params.json>}"
log() { echo "[panel-install] $*"; }

detect_os
ensure_base_tools || exit 1
ensure_cmd git git || { log "ERROR: не удалось установить git"; exit 1; }

REPO=$(jq -r '.repo' "$PARAMS_FILE")
BRANCH=$(jq -r '.branch // "main"' "$PARAMS_FILE")
DOMAIN=$(jq -r '.domain // ""' "$PARAMS_FILE")
LNG=$(jq -r '.lang // "ru"' "$PARAMS_FILE")
[[ "$REPO" =~ ^https://[A-Za-z0-9./_-]+$ ]] || { log "ERROR: bad repo url"; exit 1; }
[[ "$BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]] || { log "ERROR: bad branch"; exit 1; }
[[ -z "$DOMAIN" || "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { log "ERROR: bad domain"; exit 1; }

DEST=/opt/vps-router
if [ -d "$DEST/.git" ]; then
    log "Репозиторий уже есть — обновляю ($BRANCH)"
    git -C "$DEST" fetch --depth 1 origin "$BRANCH"
    git -C "$DEST" checkout -q -B "$BRANCH" FETCH_HEAD
else
    log "Клонирую $REPO ($BRANCH) в $DEST"
    rm -rf "$DEST"
    git clone -q --depth 1 --branch "$BRANCH" "$REPO" "$DEST"
fi

log "Запускаю deploy/bootstrap.sh"
printf '%s\n%s\n' "$LNG" "$DOMAIN" | VPSR_LANG="$LNG" bash "$DEST/deploy/bootstrap.sh"
