#!/usr/bin/env bash
# Привилегированный шаг мастера: выпуск TLS-сертификата Let's Encrypt для домена
# панели через certbot --nginx.
#
# Правила безопасности (как apply-router.sh):
#   • НЕ принимает аргументов;
#   • домен и e-mail читает из /var/lib/panel/install-cert.conf (пишет панель),
#     строго проверяя формат перед передачей в certbot;
#   • если файла нет или домен невалиден — выходит без изменений.
#
# Установка: bootstrap.sh / install.sh кладут в
#   /usr/local/sbin/vpsrouter-issue-cert.sh (root:root, 0755),
# доступ www-data — через /etc/sudoers.d/panel-install.
set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for cand in "$SELF_DIR/../provision/_lib.sh" /usr/local/share/panel/provision/_lib.sh; do
    if [ -r "$cand" ]; then LIB="$cand"; break; fi
done
[ -n "${LIB:-}" ] && . "$LIB" || true  # _lib.sh опционален (нужен только pkg_install)

CONF="/var/lib/panel/install-cert.conf"

if [ ! -s "$CONF" ]; then
    echo "[cert] Файл $CONF не найден — нечего выпускать (домен не задан)."
    exit 0
fi

DOMAIN=$(sed -n 's/^DOMAIN=//p' "$CONF" | head -1)
EMAIL=$(sed -n 's/^EMAIL=//p' "$CONF" | head -1)

# Домен пишет www-data — принимаем только строгий формат (буквы/цифры/точки/дефис,
# хотя бы одна точка, не длиннее 253). Иначе не зовём certbot.
if ! [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || [[ "$DOMAIN" != *.* ]]; then
    echo "[cert] ERROR: недопустимый домен в $CONF — прерываю."
    exit 1
fi

echo "[cert] Домен: $DOMAIN"

if ! command -v certbot >/dev/null 2>&1; then
    echo "[cert] Ставлю certbot..."
    if command -v pkg_install >/dev/null 2>&1; then
        [ -n "${PKG:-}" ] || detect_os
        pkg_install certbot python3-certbot-nginx || pkg_install certbot || true
    fi
fi
command -v certbot >/dev/null 2>&1 || { echo "[cert] ERROR: certbot недоступен — поставьте вручную."; exit 1; }

# E-mail валидируем мягко: если формат подозрительный — регистрируемся без него.
CERTBOT_EMAIL=(--register-unsafely-without-email)
if [[ "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
    CERTBOT_EMAIL=(-m "$EMAIL" --no-eff-email)
fi

echo "[cert] Выпускаю сертификат (certbot --nginx)..."
if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos "${CERTBOT_EMAIL[@]}" --redirect; then
    echo "[cert] Сертификат выпущен и nginx настроен на HTTPS."
    # conf с адресом больше не нужен.
    rm -f "$CONF"
else
    echo "[cert] ERROR: certbot не смог выпустить сертификат — проверьте, что домен $DOMAIN"
    echo "[cert]        указывает на этот сервер и порт 80/tcp открыт, затем повторите."
    exit 1
fi
