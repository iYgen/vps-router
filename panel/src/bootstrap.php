<?php

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// phpseclib (SSH-клиент, см. App\Ssh) — опционально: подключаем, если
// сделан `composer install`, чтобы окружения без Composer не ломались.
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require $vendorAutoload;
}

$config = \App\App::config();

// Защитные заголовки для всех страниц панели (в CLI — cron/тесты — не нужны).
// Панель не должна индексироваться и светить версию PHP сканерам: на этом же
// IP живут обычные сайты, лишние признаки «здесь VPN-панель» им вредят.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
}

// Secure-куку браузер хранит ТОЛЬКО по HTTPS. Установщик и доступ по IP идут
// по HTTP (TLS выпускается мастером позже), поэтому принудительный secure=true
// ломал сессию: кука не сохранялась → каждый запрос = новая сессия → «Invalid
// CSRF token». Включаем secure лишь когда соединение реально по HTTPS (в т.ч.
// за обратным прокси), иначе кука не долетит и сессия рвётся.
$httpsOn = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

session_name($config['session_name'] ?? 'panel_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $httpsOn,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/**
 * Короткий глобальный хелпер перевода — t('ключ', ...аргументы sprintf).
 * Дублирует App\I18n::t(), чтобы во вьюхах писать t('...') без длинного вызова.
 */
if (!function_exists('t')) {
    function t(string $key, ...$args): string
    {
        return \App\I18n::t($key, ...$args);
    }
}

// --- Активный язык интерфейса -------------------------------------------
// Приоритет: ?lang= (явное переключение) > выбор пользователя (users.lang) >
// cookie panel_lang > ЯЗЫК ПАНЕЛИ ПО УМОЛЧАНИЮ > встроенный дефолт.
// Язык по умолчанию хранится в файле <db_dir>/lang (панель пишет его при смене
// языка) и применяется в т.ч. в CLI (cron/провижининг) — чтобы логи скриптов
// шли на выбранном языке, а не всегда по-русски.
$langFile = \dirname((string) ($config['db_path'] ?? '/var/lib/panel/panel.db')) . '/lang';
$defaultLang = \App\I18n::DEFAULT_LANG;
if (is_readable($langFile)) {
    $f = trim((string) @file_get_contents($langFile));
    if ($f !== '' && isset(\App\I18n::available()[$f])) {
        $defaultLang = $f;
    }
}

if (PHP_SAPI === 'cli') {
    // В CLI нет запроса/пользователя — берём язык панели по умолчанию.
    \App\I18n::setLang($defaultLang);
} else {
    $available = \App\I18n::available();

    // Явное переключение через ?lang=xx на любой странице.
    if (isset($_GET['lang']) && isset($available[$_GET['lang']])) {
        $chosen = $_GET['lang'];
        setcookie('panel_lang', $chosen, [
            'expires' => time() + 31536000, 'path' => '/', 'secure' => $httpsOn,
            'httponly' => false, 'samesite' => 'Lax',
        ]);
        $_COOKIE['panel_lang'] = $chosen;
        // Запоминаем выбор как язык панели по умолчанию — чтобы логи скриптов
        // провижининга (читают файл или наследуют в CLI) шли на этом языке.
        if ($chosen !== $defaultLang) {
            @file_put_contents($langFile, $chosen);
            $defaultLang = $chosen;
        }
        // Залогиненному — сохранить в профиль.
        if (!empty($_SESSION['user_id'])) {
            try {
                \App\Database::get()->prepare('UPDATE users SET lang = ? WHERE id = ?')
                    ->execute([$chosen, $_SESSION['user_id']]);
            } catch (\Throwable $e) {
                // миграция ещё не накатилась — не критично
            }
        }
    }

    $lang = null;
    if (!empty($_SESSION['user_id'])) {
        try {
            $stmt = \App\Database::get()->prepare('SELECT lang FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $lang = $stmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            $lang = null;
        }
    }
    $lang = $lang ?: ($_COOKIE['panel_lang'] ?? null) ?: $defaultLang;
    \App\I18n::setLang($lang);
}
