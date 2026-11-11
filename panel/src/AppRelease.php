<?php

namespace App;

/**
 * Android-приложение VPS Router, которое панель раздаёт сама.
 *
 * APK собирается из android/ этого же репозитория (`./gradlew publishToPanel`) и кладётся в
 * panel/app/ вместе с version.json. Каталог app/ приходит при самообновлении панели
 * (SelfUpdater::CODE_PATHS), поэтому версия приложения всегда соответствует коду панели.
 *
 * APK лежит вне webroot: скачать его можно только из сессии панели (/api/app.php) или по
 * временной подписанной ссылке (/app-download.php) — она нужна, чтобы открыть загрузку
 * на телефоне по QR без входа в панель в браузере телефона. Публичной ссылки нет, чтобы
 * не выдавать по адресу, что на сервере стоит VPS Router.
 */
class AppRelease
{
    /** Сколько живёт ссылка для QR (секунды). */
    public const LINK_TTL = 1800;

    public static function dir(): string
    {
        return dirname(__DIR__) . '/app';
    }

    public static function apkPath(): string
    {
        return self::dir() . '/vps-router.apk';
    }

    /**
     * @return array{versionCode:int,versionName:string,size:int,sha256:string,minSdk:int}|null
     */
    public static function info(): ?array
    {
        $json = self::dir() . '/version.json';
        if (!is_file($json) || !is_file(self::apkPath())) {
            return null;
        }
        $v = json_decode((string) file_get_contents($json), true);
        if (!is_array($v) || !isset($v['versionCode'], $v['versionName'], $v['sha256'])) {
            return null;
        }
        return [
            'versionCode' => (int) $v['versionCode'],
            'versionName' => (string) $v['versionName'],
            'size' => (int) ($v['size'] ?? filesize(self::apkPath())),
            'sha256' => strtolower((string) $v['sha256']),
            'minSdk' => (int) ($v['minSdk'] ?? 24),
        ];
    }

    private static function secret(): string
    {
        $s = (string) (App::config()['app_secret'] ?? '');
        if ($s === '') {
            throw new \RuntimeException('app_secret не задан в config.php');
        }
        return $s;
    }

    private static function sign(int $expires): string
    {
        return hash_hmac('sha256', 'app-apk|' . $expires, self::secret());
    }

    /** Относительная ссылка на скачивание, действительная LINK_TTL секунд. */
    public static function signedPath(): string
    {
        $e = time() + self::LINK_TTL;
        return '/app-download.php?e=' . $e . '&s=' . self::sign($e);
    }

    public static function verify(string $e, string $s): bool
    {
        if (!ctype_digit($e) || (int) $e < time() || (int) $e > time() + self::LINK_TTL + 60) {
            return false;
        }
        return hash_equals(self::sign((int) $e), strtolower($s));
    }

    /** Отдать APK потоком (заголовки + тело). */
    public static function stream(): void
    {
        $path = self::apkPath();
        $info = self::info();
        if ($info === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'APK not published';
            return;
        }
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="vps-router-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $info['versionName']) . '.apk"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    /** Абсолютный адрес панели, по которому её открыли (для QR). */
    public static function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        return ($https ? 'https://' : 'http://') . $host;
    }

    /**
     * Скрипты установки, которые приложение может подтянуть из панели вместо встроенных
     * (чтобы исправления в deploy/provision доходили до телефона без новой версии APK).
     * Только этот список — никаких путей от клиента.
     */
    public const SCRIPTS = ['_lib.sh', 'relay-forward.sh'];

    /** @return array<string,string> имя → sha256 */
    public static function scriptHashes(): array
    {
        $out = [];
        foreach (self::SCRIPTS as $name) {
            $p = Provisioner::scriptsDir() . '/' . $name;
            if (is_file($p)) {
                $out[$name] = hash_file('sha256', $p);
            }
        }
        return $out;
    }

    public static function scriptPath(string $name): ?string
    {
        if (!in_array($name, self::SCRIPTS, true)) {
            return null;
        }
        $p = Provisioner::scriptsDir() . '/' . $name;
        return is_file($p) ? $p : null;
    }
}
