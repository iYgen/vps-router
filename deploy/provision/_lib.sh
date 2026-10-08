# ---------------------------------------------------------------------------
# Общие функции provision-скриптов: определение ОС, установка пакетов и
# sing-box / wireguard-tools / amneziawg-tools на популярных дистрибутивах:
#   Debian, Ubuntu (и производные) — apt
#   CentOS 7 — yum (+EPEL/ELRepo)
#   CentOS Stream, RHEL, Rocky, Alma, Oracle 8/9, Fedora — dnf (+EPEL/COPR)
#   openSUSE — zypper, Arch — pacman, Alpine — apk
#
# App\Ssh::runProvisionScript() автоматически приклеивает этот файл перед
# каждым deploy/provision/*.sh, поэтому скрипты просто вызывают функции.
# entry-vps-install.sh (запускается локально) подключает его через source.
# ---------------------------------------------------------------------------

# Язык логов провижининга (en/ru). Приоритет: env VPSR_LANG (его пробрасывают
# bootstrap.sh/install.sh, браузерный мастер через env_keep и Ssh.php для удалённых
# серверов) → язык панели по умолчанию из /var/lib/panel/lang (панель пишет его при
# смене языка) → ru. Файл есть только на узле панели; на удалённых язык приходит в env.
if [ -z "${VPSR_LANG:-}" ] && [ -r /var/lib/panel/lang ]; then
    VPSR_LANG="$(cat /var/lib/panel/lang 2>/dev/null)"
fi
[ "$VPSR_LANG" = en ] || VPSR_LANG=ru
# _t "русский" "english" — вернуть строку на выбранном языке.
_t() { [ "$VPSR_LANG" = en ] && printf '%s' "$2" || printf '%s' "$1"; }
_lib_log() { echo "[provision] $*"; }

detect_os() {
    OS_ID="unknown"; OS_LIKE=""; OS_VER=""; OS_CODENAME=""
    if [ -r /etc/os-release ]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        OS_ID="${ID:-unknown}"; OS_LIKE="${ID_LIKE:-}"; OS_VER="${VERSION_ID:-}"; OS_CODENAME="${VERSION_CODENAME:-}"
    fi
    OS_MAJOR="${OS_VER%%.*}"
    if command -v apt-get >/dev/null 2>&1; then PKG=apt
    elif command -v dnf >/dev/null 2>&1; then PKG=dnf
    elif command -v yum >/dev/null 2>&1; then PKG=yum
    elif command -v zypper >/dev/null 2>&1; then PKG=zypper
    elif command -v pacman >/dev/null 2>&1; then PKG=pacman
    elif command -v apk >/dev/null 2>&1; then PKG=apk
    else PKG=none
    fi
    _lib_log "$(_t "ОС" "OS"): ${PRETTY_NAME:-$OS_ID $OS_VER}, $(_t "пакетный менеджер" "package manager"): $PKG"
}

is_rhel_like() {
    case " $OS_ID $OS_LIKE " in
        *" rhel "*|*" centos "*|*" fedora "*|*" rocky "*|*" almalinux "*|*" ol "*) return 0 ;;
    esac
    return 1
}

_APT_UPDATED=0
pkg_install() { # pkg_install pkg1 [pkg2...] — без ошибки наружу, код возврата = успех установки
    case "$PKG" in
        apt)
            if [ "$_APT_UPDATED" = 0 ]; then DEBIAN_FRONTEND=noninteractive apt-get update -qq || true; _APT_UPDATED=1; fi
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" ;;
        dnf) dnf install -y -q "$@" ;;
        yum) yum install -y -q "$@" ;;
        zypper) zypper --non-interactive install "$@" ;;
        pacman) pacman -Sy --noconfirm --needed "$@" ;;
        apk) apk add --no-cache "$@" ;;
        *) return 1 ;;
    esac
}

ensure_epel() {
    is_rhel_like || return 0
    [ "$OS_ID" = "fedora" ] && return 0
    rpm -q epel-release >/dev/null 2>&1 && return 0
    _lib_log "$(_t "Подключаю EPEL..." "Enabling EPEL...")"
    pkg_install epel-release \
        || pkg_install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-${OS_MAJOR}.noarch.rpm" \
        || _lib_log "$(_t "WARN: не удалось подключить EPEL" "WARN: failed to enable EPEL")"
    if [ "$PKG" = dnf ] && [ "${OS_MAJOR:-0}" -ge 8 ] 2>/dev/null; then
        # jq/wireguard-tools на EL8/9 зависят от CRB/PowerTools
        dnf config-manager --set-enabled crb >/dev/null 2>&1 \
            || dnf config-manager --set-enabled powertools >/dev/null 2>&1 || true
    fi
}

# ensure_cmd <команда> <пакет...> — ставит пакет, если команды нет; на RHEL-семействе при неудаче подключает EPEL и повторяет
ensure_cmd() {
    local cmd="$1"; shift
    command -v "$cmd" >/dev/null 2>&1 && return 0
    _lib_log "$(_t "Ставлю $* (нужна команда $cmd)..." "Installing $* (need command $cmd)...")"
    pkg_install "$@" >/dev/null 2>&1 || { ensure_epel; pkg_install "$@"; } || true
    command -v "$cmd" >/dev/null 2>&1
}

ensure_base_tools() {
    [ -n "${PKG:-}" ] || detect_os
    ensure_cmd curl curl || true
    ensure_cmd jq jq || { _lib_log "$(_t "ERROR: не удалось установить jq" "ERROR: failed to install jq")"; return 1; }
    ensure_cmd tar tar || true
    ensure_cmd iptables iptables || _lib_log "$(_t "WARN: iptables не установлен — NAT для туннелей работать не будет" "WARN: iptables not installed — NAT for tunnels will not work")"
}

# ---------------------------------------------------------------------------
# PHP-стек с ГАРАНТИЕЙ версии >= 8.1 (панели нужен 8.1+).
#
# Главная засада мульти-ОС: на RHEL-семействе штатный PHP слишком старый
# (EL7 → 5.4, EL8 → 7.2/7.4, EL9 → 8.0), поэтому там подключаем remi:
#   • EL8/EL9 (dnf) — модуль php:remi-8.x, имена штатные (php, php-fpm);
#   • EL7 (yum)     — SCL-пакеты phpXX (php82, php82-php-fpm), имена нестандартные;
#   • Fedora        — штатный PHP уже свежий;
#   • Debian/Ubuntu — штатный php-fpm свежий.
#
# install_php_stack экспортирует: PHP_BIN, PHP_FPM_SERVICE, WEB_USER.
# ПРИМЕЧАНИЕ: ветки EL/remi проверены логически, но нуждаются в прогоне на
# реальных Rocky/Alma/CentOS (см. TODO «Прогнать на нескольких ОС»).
# ---------------------------------------------------------------------------

# Желаемая версия PHP из remi (можно переопределить переменными окружения).
: "${REMI_PHP_MODULE:=8.3}"   # для dnf-модуля php:remi-<X.Y>
: "${REMI_PHP_SCL:=82}"       # для EL7 SCL-пакетов php<NN>

_php_version_ok() { # _php_version_ok <бинарь php> — 0, если версия >= 8.1
    "$1" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1
}

# Ищет подходящий CLI-бинарь PHP (>=8.1), пишет путь в PHP_BIN.
_resolve_php_bin() {
    PHP_BIN=""
    local c
    for c in php php8.4 php8.3 php8.2 php8.1 php84 php83 php82 php81 \
             /opt/remi/php84/root/usr/bin/php /opt/remi/php83/root/usr/bin/php \
             /opt/remi/php82/root/usr/bin/php /opt/remi/php81/root/usr/bin/php; do
        local p=""
        command -v "$c" >/dev/null 2>&1 && p="$(command -v "$c")"
        [ -z "$p" ] && [ -x "$c" ] && p="$c"
        if [ -n "$p" ] && _php_version_ok "$p"; then PHP_BIN="$p"; return 0; fi
    done
    return 1
}

# Определяет имя systemd-сервиса FPM. Если PHP_BIN версионный (php82) — предпочитает
# сервис с тем же токеном версии (php82-php-fpm), иначе первый php*-fpm.
_resolve_php_fpm_service() {
    PHP_FPM_SERVICE=""
    local units token
    units=$(ls /lib/systemd/system /usr/lib/systemd/system /etc/systemd/system 2>/dev/null \
        | grep -E '^php[0-9.]*-?(php-)?fpm\.service$' | sort -u)
    # Токен версии из PHP_BIN: /opt/remi/php82/... или php82 → "82"; php8.2 → "8.2".
    token=$(printf '%s' "${PHP_BIN:-}" | grep -oE 'php[0-9.]+' | head -1 | sed 's/^php//')
    if [ -n "$token" ]; then
        PHP_FPM_SERVICE=$(printf '%s\n' $units | grep -F "$token" | head -1)
        PHP_FPM_SERVICE="${PHP_FPM_SERVICE%.service}"
    fi
    if [ -z "$PHP_FPM_SERVICE" ]; then
        PHP_FPM_SERVICE=$(printf '%s\n' $units | head -1)
        PHP_FPM_SERVICE="${PHP_FPM_SERVICE%.service}"
    fi
    [ -z "$PHP_FPM_SERVICE" ] && command -v php-fpm >/dev/null 2>&1 && PHP_FPM_SERVICE="php-fpm"
}

# Значение для fastcgi_pass в nginx: unix-сокет FPM (вкл. remi-пути) или TCP.
php_fpm_pass() {
    local s conf listen
    # 1) Уже существующий сокет (php-fpm запущен).
    s=$(ls /run/php/php*-fpm.sock /run/php-fpm/*.sock /var/run/php-fpm/*.sock \
           /var/opt/remi/php*/run/php-fpm/*.sock 2>/dev/null | head -1)
    if [ -n "$s" ]; then echo "unix:$s"; return; fi
    # 2) Сокета ещё нет (php-fpm не стартовал на момент генерации vhost) — читаем
    #    директиву listen= из конфига пула. Иначе на EL улетали в 127.0.0.1:9000,
    #    а FPM там слушает unix-сокет → 502 Bad Gateway.
    for conf in /etc/php-fpm.d/*.conf \
                /etc/php/*/fpm/pool.d/*.conf \
                /etc/opt/remi/php*/php-fpm.d/*.conf; do
        [ -f "$conf" ] || continue
        listen=$(sed -n 's/^[[:space:]]*listen[[:space:]]*=[[:space:]]*//p' "$conf" | head -1)
        [ -n "$listen" ] || continue
        case "$listen" in
            /*) echo "unix:$listen"; return ;;   # путь к сокету
            *)  echo "$listen"; return ;;        # host:port или :port
        esac
    done
    # 3) Совсем ничего не нашли — последний fallback.
    echo "127.0.0.1:9000"
}

_install_remi_release() {
    is_rhel_like || return 0
    [ "$OS_ID" = fedora ] && return 0
    rpm -q remi-release >/dev/null 2>&1 && return 0
    _lib_log "$(_t "Подключаю remi (нужен свежий PHP/php-sodium на EL${OS_MAJOR})..." "Enabling remi (need up-to-date PHP/php-sodium on EL${OS_MAJOR})...")"
    local url="https://rpms.remirepo.net/enterprise/remi-release-${OS_MAJOR}.rpm"
    if ! pkg_install "$url"; then
        # remi-release требует ПОСЛЕДНИЙ минор ОС (redhat-release >= X.Y). На более
        # старом миноре (напр. AlmaLinux 9.7 при remi-release 9.8) эта зависимость
        # не выполняется, хотя сам репозиторий remi собран под МАЖОР EL и работает.
        # Ставим репо-файлы напрямую, минуя проверку минорной версии.
        if curl -fsSLo /tmp/remi-release.rpm "$url" 2>/dev/null; then
            rpm -Uvh --nodeps --replacepkgs /tmp/remi-release.rpm >/dev/null 2>&1 \
                || _lib_log "$(_t "WARN: remi-release не установился даже через rpm --nodeps" "WARN: remi-release failed to install even via rpm --nodeps")"
            rm -f /tmp/remi-release.rpm
        else
            _lib_log "$(_t "WARN: не удалось скачать remi-release" "WARN: failed to download remi-release")"
        fi
    fi
    [ "$PKG" = dnf ] && pkg_install dnf-plugins-core >/dev/null 2>&1 || true
    [ "$PKG" = yum ] && pkg_install yum-utils >/dev/null 2>&1 || true
    rpm -q remi-release >/dev/null 2>&1   # код возврата = удалось ли подключить remi
}

# Ставит sodium-расширение PHP устойчиво к различиям репозиториев Debian/Ubuntu:
# если расширение уже активно — ничего не делает; иначе пробует метапакет
# php-sodium, затем версионное phpX.Y-sodium по версии установленного php.
# Не роняет установку при неудаче — только предупреждает (sodium нужен панели
# для шифрования секретов; на штатных Ubuntu/Debian версионный пакет ставится).
ensure_php_sodium() {
    if command -v php >/dev/null 2>&1 && php -m 2>/dev/null | grep -qi '^sodium$'; then
        return 0
    fi
    pkg_install php-sodium && return 0
    local v
    v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
    [ -n "$v" ] && pkg_install "php${v}-sodium" && return 0
    _lib_log "$(_t "WARN: не удалось установить php-sodium — поставьте вручную phpX.Y-sodium (нужно панели для шифрования секретов)." "WARN: failed to install php-sodium — install phpX.Y-sodium manually (the panel needs it to encrypt secrets).")"
    return 0
}

install_php_stack() {
    [ -n "${PKG:-}" ] || detect_os
    case "$PKG" in
        apt)
            pkg_install nginx-light nginx || pkg_install nginx
            # Обязательный набор без sodium: метапакет php-sodium есть не во всех
            # репозиториях (например, на Ubuntu 24.04/noble его нет — расширение
            # зовётся phpX.Y-sodium). Поэтому sodium ставим отдельно и мягко ниже,
            # чтобы одно имя пакета не роняло весь стек.
            pkg_install php-fpm php-cli php-sqlite3 php-curl php-mbstring php-xml php-gd sqlite3 || return 1
            ensure_php_sodium
            WEB_USER=www-data
            ;;
        dnf)
            pkg_install nginx || true
            if [ "$OS_ID" = fedora ]; then
                pkg_install php-fpm php-cli php-pdo php-sqlite3 php-sodium php-mbstring php-xml php-gd sqlite || return 1
            else
                ensure_epel
                # Чистим возможный PHP от прошлого запуска (напр. штатный 8.0),
                # иначе переключение потока модуля конфликтует. На свежем — no-op.
                if rpm -q php-common >/dev/null 2>&1; then
                    dnf -y remove 'php-*' >/dev/null 2>&1 || true
                fi
                dnf -y module reset php >/dev/null 2>&1 || true
                # На EL предпочитаем remi: он даёт и свежий PHP, и php-sodium —
                # расширение, без которого панель не работает (генерация ключей
                # Reality/WG, шифрование секретов). В штатном AppStream EL9 пакета
                # php-sodium может не быть вовсе (напр. AlmaLinux 9.7), а в EPEL он
                # собран под базовый PHP 8.0 и конфликтует с 8.1+. remi решает оба.
                if _install_remi_release; then
                    dnf -y module enable "php:remi-${REMI_PHP_MODULE}" >/dev/null 2>&1 \
                        || dnf -y module enable php:remi-8.3 >/dev/null 2>&1 \
                        || dnf -y module enable php:remi-8.2 >/dev/null 2>&1 || true
                    # --allowerasing: заменить уже стоявшие appstream-пакеты PHP на remi.
                    dnf -y --allowerasing install \
                        php-fpm php-cli php-pdo php-sqlite3 php-sodium php-mbstring php-xml php-gd sqlite || return 1
                else
                    # remi недоступен — штатный AppStream (PHP 8.1/8.2/8.3 без remi).
                    # sodium там может отсутствовать: тогда предупреждаем, но не валим
                    # установку (часть функций — ключи Reality/WG — не заработает).
                    local _stream=""
                    for _s in 8.3 8.2 8.1; do
                        if dnf -y module enable "php:${_s}" >/dev/null 2>&1; then _stream="$_s"; break; fi
                    done
                    dnf -y install --disablerepo='epel*' \
                        php-fpm php-cli php-pdo php-sqlite3 php-mbstring php-xml php-gd sqlite || return 1
                    php -m 2>/dev/null | grep -qi '^sodium$' \
                        || _lib_log "$(_t "WARN: php-sodium недоступен (ни remi, ни модуль php:${_stream:-?} не дали). Генерация ключей Reality/WG и шифрование секретов не заработают — подключите remi вручную." "WARN: php-sodium unavailable (neither remi nor module php:${_stream:-?} provided it). Reality/WG key generation and secret encryption will not work — enable remi manually.")"
                fi
            fi
            WEB_USER=apache
            ;;
        yum)
            # EL7: штатного PHP 8.x нет и модулей нет — ставим SCL-пакеты remi phpNN.
            pkg_install nginx || true
            ensure_epel
            _install_remi_release
            local v="$REMI_PHP_SCL"
            pkg_install "php${v}" "php${v}-php-fpm" "php${v}-php-cli" "php${v}-php-pdo" \
                "php${v}-php-sqlite3" "php${v}-php-sodium" "php${v}-php-mbstring" \
                "php${v}-php-xml" "php${v}-php-gd" sqlite || return 1
            WEB_USER=apache
            ;;
        zypper|pacman|apk)
            pkg_install nginx || true
            pkg_install php-fpm php-cli php-sqlite3 php-sodium php-mbstring php-xml sqlite || return 1
            WEB_USER="${WEB_USER:-wwwrun}"
            ;;
        *)
            _lib_log "$(_t "ERROR: неизвестный пакетный менеджер — поставьте PHP 8.1+ и nginx вручную" "ERROR: unknown package manager — install PHP 8.1+ and nginx manually")"
            return 1
            ;;
    esac

    if ! _resolve_php_bin; then
        _lib_log "$(_t "ERROR: не найден PHP >= 8.1 после установки ($OS_ID $OS_VER)." "ERROR: PHP >= 8.1 not found after install ($OS_ID $OS_VER).")"
        [ "$PKG" = yum ] && _lib_log "$(_t "        Для EL7 нужен remi SCL: yum install php${REMI_PHP_SCL} php${REMI_PHP_SCL}-php-fpm ..." "        EL7 needs remi SCL: yum install php${REMI_PHP_SCL} php${REMI_PHP_SCL}-php-fpm ...")"
        return 1
    fi
    _resolve_php_fpm_service
    _lib_log "PHP: $("$PHP_BIN" -v | head -1); FPM-сервис: ${PHP_FPM_SERVICE:-?}; веб-пользователь: $WEB_USER"
    return 0
}

# Интерфейс, через который сервер ходит в интернет (eth0 / ens3 / enp1s0 / venet0 ...)
default_iface() {
    local dev
    dev=$(ip -4 route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')
    echo "${dev:-eth0}"
}

# open_port 51820/udp — открывает порт в ufw/firewalld, если они включены (иначе ничего не делает)
open_port() {
    local p="$1"
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
        ufw allow "$p" >/dev/null 2>&1 && _lib_log "$(_t "ufw: открыт $p" "ufw: opened $p")"
    fi
    if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        firewall-cmd --quiet --add-port="$p" && firewall-cmd --quiet --permanent --add-port="$p" && _lib_log "$(_t "firewalld: открыт $p" "firewalld: opened $p")"
        # masquerade нужен firewalld для NAT туннелей
        { firewall-cmd --quiet --add-masquerade && firewall-cmd --quiet --permanent --add-masquerade; } >/dev/null 2>&1 || true
    fi
    return 0
}

enable_ip_forward() {
    sysctl -qw net.ipv4.ip_forward=1
    mkdir -p /etc/sysctl.d
    echo 'net.ipv4.ip_forward=1' > /etc/sysctl.d/99-panel-forward.conf
}

_singbox_arch() {
    case "$(uname -m)" in
        x86_64|amd64) echo amd64 ;;
        aarch64|arm64) echo arm64 ;;
        armv7l|armv7) echo armv7 ;;
        i386|i686) echo 386 ;;
        s390x) echo s390x ;;
        *) echo "" ;;
    esac
}

# install_singbox <путь к бинарнику> — ставит sing-box не завися от install.sh:
#  1) сам определяет последнюю версию и качает статический бинарник с GitHub
#     (работает на любом дистрибутиве, даже CentOS 7);
#  2) иначе — официальный репозиторий SagerNet (apt / rpm);
#  3) иначе — пакет из репозиториев дистрибутива (Arch, Alpine, Fedora).
install_singbox() {
    local dest="$1" arch ver tag tmp url
    [ -n "${PKG:-}" ] || detect_os
    arch=$(_singbox_arch)
    if [ -n "$arch" ] && command -v curl >/dev/null 2>&1 && command -v tar >/dev/null 2>&1; then
        # Последний релиз: редирект releases/latest -> .../tag/vX.Y.Z (без лимитов API), запасной вариант — API.
        tag=$(curl -fsSLI -o /dev/null -w '%{url_effective}' https://github.com/SagerNet/sing-box/releases/latest 2>/dev/null | sed -n 's#.*/tag/\(v[0-9][^/]*\)$#\1#p' || true)
        [ -n "$tag" ] || tag=$(curl -fsSL https://api.github.com/repos/SagerNet/sing-box/releases/latest 2>/dev/null | sed -n 's/.*"tag_name": *"\(v[^"]*\)".*/\1/p' | head -1 || true)
        if [ -n "$tag" ]; then
            ver="${tag#v}"
            url="https://github.com/SagerNet/sing-box/releases/download/${tag}/sing-box-${ver}-linux-${arch}.tar.gz"
            _lib_log "$(_t "Качаю sing-box $ver ($arch): $url" "Downloading sing-box $ver ($arch): $url")"
            tmp=$(mktemp -d)
            if curl -fsSL --retry 3 -o "$tmp/sb.tgz" "$url" && tar -xzf "$tmp/sb.tgz" -C "$tmp"; then
                local bin
                bin=$(find "$tmp" -type f -name sing-box | head -1)
                if [ -n "$bin" ]; then
                    install -m 0755 "$bin" "$dest"
                    rm -rf "$tmp"
                    "$dest" version >/dev/null 2>&1 && { _lib_log "$(_t "sing-box установлен" "sing-box installed"): $("$dest" version | head -1)"; return 0; }
                fi
            fi
            rm -rf "$tmp"
            _lib_log "$(_t "WARN: не удалось скачать бинарник с GitHub, пробую репозитории" "WARN: failed to download binary from GitHub, trying repositories")"
        else
            _lib_log "$(_t "WARN: не удалось узнать последнюю версию sing-box на GitHub, пробую репозитории" "WARN: could not determine latest sing-box version on GitHub, trying repositories")"
        fi
    fi

    case "$PKG" in
        apt)
            mkdir -p /etc/apt/keyrings
            if curl -fsSL https://sing-box.app/gpg.key -o /etc/apt/keyrings/sagernet.asc; then
                chmod a+r /etc/apt/keyrings/sagernet.asc
                echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/sagernet.asc] https://deb.sagernet.org/ * *" \
                    > /etc/apt/sources.list.d/sagernet.list
                _APT_UPDATED=0
            fi
            pkg_install sing-box || true ;;
        dnf|yum)
            curl -fsSL https://sing-box.app/sing-box.repo -o /etc/yum.repos.d/sing-box.repo 2>/dev/null || true
            pkg_install sing-box || true ;;
        pacman|apk|zypper)
            pkg_install sing-box || true ;;
    esac
    local sys
    sys=$(command -v sing-box || true)
    if [ -n "$sys" ]; then
        if [ "$sys" != "$dest" ]; then install -m 0755 "$sys" "$dest"; fi
        _lib_log "$(_t "sing-box установлен из репозитория" "sing-box installed from repository"): $("$dest" version | head -1)"
        return 0
    fi
    _lib_log "$(_t "ERROR: не удалось установить sing-box ни с GitHub, ни из репозиториев ($OS_ID $OS_VER, $(uname -m))" "ERROR: failed to install sing-box from GitHub or repositories ($OS_ID $OS_VER, $(uname -m))")"
    return 1
}

# install_caddy <dest> — статический бинарник Caddy с GitHub (для сайта-прикрытия
# и TLS 1.3-фронта Reality). Не зависит от репозиториев дистрибутива.
install_caddy() {
    local dest="${1:-/usr/local/bin/caddy}" tag arch tmp
    [ -n "${PKG:-}" ] || detect_os
    "$dest" version >/dev/null 2>&1 && return 0
    command -v curl >/dev/null 2>&1 || ensure_cmd curl curl || true
    command -v tar >/dev/null 2>&1 || ensure_cmd tar tar || true
    tag=$(curl -fsSLI -o /dev/null -w '%{url_effective}' https://github.com/caddyserver/caddy/releases/latest 2>/dev/null | sed -n 's#.*/tag/\(v[0-9][^/]*\)$#\1#p' || true)
    [ -n "$tag" ] || { _lib_log "$(_t "ERROR: не удалось узнать версию Caddy" "ERROR: failed to determine Caddy version")"; return 1; }
    case "$(uname -m)" in x86_64|amd64) arch=amd64 ;; aarch64|arm64) arch=arm64 ;; armv7l) arch=armv7 ;; *) arch=amd64 ;; esac
    tmp=$(mktemp -d)
    _lib_log "$(_t "Качаю Caddy $tag ($arch)" "Downloading Caddy $tag ($arch)")"
    if curl -fsSL --retry 3 -o "$tmp/c.tgz" "https://github.com/caddyserver/caddy/releases/download/$tag/caddy_${tag#v}_linux_${arch}.tar.gz" && tar -xzf "$tmp/c.tgz" -C "$tmp" caddy; then
        install -m 0755 "$tmp/caddy" "$dest"
        rm -rf "$tmp"
        "$dest" version >/dev/null 2>&1 && return 0
    fi
    rm -rf "$tmp"
    _lib_log "$(_t "ERROR: не удалось установить Caddy" "ERROR: failed to install Caddy")"
    return 1
}

install_wireguard_tools() {
    [ -n "${PKG:-}" ] || detect_os
    command -v wg-quick >/dev/null 2>&1 && return 0
    _lib_log "$(_t "Ставлю wireguard-tools..." "Installing wireguard-tools...")"
    case "$PKG" in
        apt) pkg_install wireguard-tools || pkg_install wireguard ;;
        dnf|yum)
            pkg_install wireguard-tools || {
                ensure_epel
                # EL7/EL8 со старым ядром: модуль ядра из ELRepo
                if [ "${OS_MAJOR:-0}" -le 8 ] 2>/dev/null && [ "$OS_ID" != "fedora" ]; then
                    pkg_install elrepo-release || pkg_install "https://www.elrepo.org/elrepo-release-${OS_MAJOR}.el${OS_MAJOR}.elrepo.noarch.rpm" || true
                    pkg_install kmod-wireguard || true
                fi
                pkg_install wireguard-tools
            } ;;
        *) pkg_install wireguard-tools ;;
    esac
    command -v wg-quick >/dev/null 2>&1
}

# AmneziaWG нет в стандартных репозиториях — подключаем официальные:
# Ubuntu — PPA amnezia/ppa, Debian — тот же PPA (сборка под focal),
# RHEL/Fedora — COPR amneziavpn/amneziawg. Нужны заголовки ядра (DKMS).
install_amneziawg() {
    [ -n "${PKG:-}" ] || detect_os
    command -v awg-quick >/dev/null 2>&1 && return 0
    _lib_log "$(_t "Ставлю AmneziaWG (модуль ядра + amneziawg-tools)..." "Installing AmneziaWG (kernel module + amneziawg-tools)...")"
    case "$PKG" in
        apt)
            pkg_install ca-certificates gnupg curl "linux-headers-$(uname -r)" >/dev/null 2>&1 \
                || pkg_install ca-certificates gnupg curl || true
            if [ "$OS_ID" = "ubuntu" ] && pkg_install software-properties-common >/dev/null 2>&1; then
                add-apt-repository -y ppa:amnezia/ppa || true
            else
                mkdir -p /etc/apt/keyrings
                curl -fsSL 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x57290828' \
                    | gpg --dearmor --yes -o /etc/apt/keyrings/amnezia.gpg 2>/dev/null || true
                echo "deb [signed-by=/etc/apt/keyrings/amnezia.gpg] https://ppa.launchpadcontent.net/amnezia/ppa/ubuntu focal main" \
                    > /etc/apt/sources.list.d/amnezia.list
            fi
            _APT_UPDATED=0
            pkg_install amneziawg amneziawg-tools || pkg_install amneziawg-tools || true ;;
        dnf)
            pkg_install dnf-plugins-core "kernel-devel-$(uname -r)" >/dev/null 2>&1 || pkg_install dnf-plugins-core || true
            ensure_epel
            dnf copr enable -y amneziavpn/amneziawg || true
            pkg_install amneziawg-dkms amneziawg-tools || pkg_install amneziawg-tools || true ;;
        yum)
            pkg_install yum-plugin-copr "kernel-devel-$(uname -r)" >/dev/null 2>&1 || true
            ensure_epel
            yum copr enable -y amneziavpn/amneziawg || true
            pkg_install amneziawg-dkms amneziawg-tools || pkg_install amneziawg-tools || true ;;
        *) pkg_install amneziawg-tools || true ;;
    esac
    modprobe amneziawg 2>/dev/null || _lib_log "$(_t "WARN: модуль ядра amneziawg не загрузился (нужны заголовки ядра / перезагрузка после обновления ядра)" "WARN: amneziawg kernel module did not load (needs kernel headers / reboot after a kernel update)")"
    command -v awg-quick >/dev/null 2>&1 || {
        _lib_log "$(_t "ERROR: не удалось установить AmneziaWG на $OS_ID $OS_VER — поставьте вручную по https://github.com/amnezia-vpn/amneziawg-linux-kernel-module" "ERROR: failed to install AmneziaWG on $OS_ID $OS_VER — install manually per https://github.com/amnezia-vpn/amneziawg-linux-kernel-module")"
        return 1
    }
}
# ------------------------------- конец _lib.sh -------------------------------
