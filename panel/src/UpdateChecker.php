<?php

namespace App;

use App\Models\Setting;

/**
 * Проверка обновлений панели: сверяет локальную версию (App\Version) с версией,
 * опубликованной в репозитории оператора по адресу из настроек.
 *
 * Адрес (`update_check_url`) задаёт администратор в «Настройках» — это RAW-URL
 * файла версии в репозитории или JSON релиза. Поддерживаемые ответы:
 *   • простой текст: «1.2.3» (или «v1.2.3»);
 *   • JSON: {"version":"1.2.3"} либо GitHub Releases API {"tag_name":"v1.2.3"}.
 *
 * Проверка НЕ выполняется на каждый запрос: результат кэшируется в настройках
 * (`update_latest`/`update_checked_at`/`update_error`) кроном `bin/check_updates.php`
 * или кнопкой «Проверить сейчас». Само обновление кода — отдельный шаг деплоя;
 * миграции БД после выкладки нового кода накатываются автоматически.
 */
class UpdateChecker
{
    private const TIMEOUT = 12;
    private const MAX_BYTES = 65536; // ответ версии крошечный; больше — подозрительно

    public static function repoUrl(): string
    {
        return trim((string) Setting::get('update_repo_url', ''));
    }

    public static function checkUrl(): string
    {
        return trim((string) Setting::get('update_check_url', ''));
    }

    public static function configured(): bool
    {
        return self::checkUrl() !== '';
    }

    /**
     * Текущее состояние без сетевого запроса (для UI/баннера) — из кэша настроек.
     * @return array{current:string,latest:?string,update_available:bool,checked_at:?string,error:?string,configured:bool,repo_url:string}
     */
    public static function status(): array
    {
        $current = Version::current();
        $latest = Setting::get('update_latest', '') ?: null;
        $error = Setting::get('update_error', '') ?: null;
        return [
            'current' => $current,
            'latest' => $latest,
            'update_available' => $latest !== null && Version::isUpdateAvailable($current, $latest),
            'checked_at' => Setting::get('update_checked_at', '') ?: null,
            'error' => $error,
            'configured' => self::configured(),
            'repo_url' => self::repoUrl(),
        ];
    }

    /**
     * Выполняет сетевую проверку и кэширует результат. Возвращает status().
     * $fetcher — необязательный загрузчик (для тестов); по умолчанию httpGet().
     *
     * @param null|callable(string):?string $fetcher
     */
    public static function check(?callable $fetcher = null): array
    {
        $url = self::checkUrl();
        if ($url === '') {
            self::store(null, 'Не задан адрес проверки обновлений (укажите в Настройках).');
            return self::status();
        }
        if (!preg_match('#^https?://#i', $url)) {
            self::store(null, 'Адрес проверки должен начинаться с http:// или https://');
            return self::status();
        }

        $body = $fetcher ? $fetcher($url) : self::httpGet($url);
        if ($body === null || $body === '') {
            self::store(null, 'Не удалось получить версию по адресу (таймаут/блокировка/сеть). '
                . 'Если входной сервер, а репозиторий на GitHub — включите загрузку через exit.');
            return self::status();
        }

        $remote = self::parseRemote($body);
        if ($remote === null) {
            self::store(null, 'Не удалось разобрать версию из ответа (ожидается «1.2.3» или JSON с version/tag_name).');
            return self::status();
        }

        self::store($remote, null);
        return self::status();
    }

    /** Извлекает версию из тела ответа: plain «1.2.3», JSON version/tag_name. */
    public static function parseRemote(string $body): ?string
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        // JSON (файл version.json или GitHub Releases API).
        if ($body[0] === '{' || $body[0] === '[') {
            $j = json_decode($body, true);
            if (is_array($j)) {
                foreach (['version', 'tag_name', 'tag', 'name'] as $k) {
                    if (!empty($j[$k]) && is_string($j[$k]) && preg_match('/\d/', $j[$k])) {
                        return Version::normalize($j[$k]);
                    }
                }
                return null;
            }
        }
        // Простой текст: первая строка, ожидаем что-то версиеподобное.
        $first = trim(strtok($body, "\r\n"));
        if ($first !== '' && strlen($first) <= 64 && preg_match('/^v?\d+(\.\d+)*/i', $first)) {
            return Version::normalize($first);
        }
        return null;
    }

    private static function store(?string $latest, ?string $error): void
    {
        Setting::set('update_latest', $latest ?? '');
        Setting::set('update_error', $error ?? '');
        Setting::set('update_checked_at', gmdate('Y-m-d H:i:s') . ' UTC');
    }

    /** Загрузка тела: через exit (SOCKS) если включено, иначе напрямую. */
    private static function httpGet(string $url): ?string
    {
        if (Setting::get('list_fetch_via_exit', '0') === '1' && function_exists('curl_init')) {
            $viaExit = self::curlViaSocks($url, '127.0.0.1:' . SingboxConfigBuilder::LIST_FETCH_PORT);
            if ($viaExit !== null) {
                return $viaExit;
            }
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 3,
                'header' => "User-Agent: vps_router-update-checker\r\nAccept: text/plain, application/json\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        return $data === false ? null : $data;
    }

    private static function curlViaSocks(string $url, string $socks): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROXY => $socks,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_USERAGENT => 'vps_router-update-checker',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $data = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        return ($err !== 0 || !is_string($data) || $data === '') ? null : substr($data, 0, self::MAX_BYTES);
    }
}
