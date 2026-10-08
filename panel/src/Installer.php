<?php

namespace App;

use App\Models\Setting;

/**
 * Веб-установщик панели (визуальный мастер /install.php + /api/install.php).
 *
 * Безопасность: мастер работает ТОЛЬКО пока в БД нет ни одного администратора
 * (свежая установка). Как только админ создан — мастер заблокирован и его файлы
 * удаляются (selfDestruct). Так открытый установщик нельзя использовать для
 * перехвата уже работающей панели.
 *
 * Рут-уровень (пакеты, nginx, sing-box, sudoers) ставит deploy/install.sh; этот
 * мастер конфигурирует прикладной уровень (Reality/протоколы/админ/импорт копии)
 * в БД — от рута не зависит.
 */
class Installer
{
    private const POPULAR_SNI = ['www.microsoft.com', 'www.apple.com', 'addons.mozilla.org', 'www.samsung.com', 'gateway.icloud.com'];

    /**
     * Постоянный маркер «панель уже установлена». Пишется при создании первого
     * админа и НЕ удаляется selfDestruct'ом. Благодаря нему повторная выкладка
     * файлов установщика на живую панель безопасна: даже если таблица users
     * когда-нибудь опустеет, мастер не откроется по обратной совместимости
     * (без токена), пока этот файл на месте. Снять блокировку можно только
     * вручную на сервере (удалить файл) — извне это невозможно.
     */
    public static function installedMarkerPath(): string
    {
        return rtrim(dirname((string) App::config()['db_path']), '/') . '/.installed';
    }

    /**
     * Установка завершена, если есть маркер ИЛИ хотя бы один пользователь-админ.
     * Маркер проверяем первым: он надёжнее (не зависит от доступности БД) и
     * закрывает мастер навсегда после первой установки.
     */
    public static function isInstalled(): bool
    {
        if (is_file(self::installedMarkerPath())) {
            return true;
        }
        try {
            return (int) Database::get()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Файл одноразового токена установки (пишет deploy/install.sh; удаляется после установки). */
    public static function tokenFile(): string
    {
        return rtrim(dirname((string) App::config()['db_path']), '/') . '/install-token';
    }

    /** Одноразовый токен из «уникальной ссылки», либо null если файла нет (ручная установка). */
    public static function installToken(): ?string
    {
        $f = self::tokenFile();
        if (is_file($f)) {
            $t = trim((string) @file_get_contents($f));
            return $t !== '' ? $t : null;
        }
        return null;
    }

    /**
     * Доступ к мастеру/привилегированным действиям разрешён, только пока панель
     * не установлена И совпадает токен из ссылки (если он был выдан install.sh).
     * Если токена нет вовсе (ручная установка без install.sh) — обратная
     * совместимость: пускаем, пока нет админа.
     */
    public static function accessAllowed(?string $token): bool
    {
        if (self::isInstalled()) {
            return false;
        }
        $expected = self::installToken();
        if ($expected === null) {
            return true;
        }
        return is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    /** Каталог, куда пишутся conf-файлы для привилегированных шагов (рядом с БД). */
    private static function stateDir(): string
    {
        return rtrim(dirname((string) App::config()['db_path']), '/');
    }

    public static function componentsConfPath(): string
    {
        return self::stateDir() . '/install-components.conf';
    }

    public static function certConfPath(): string
    {
        return self::stateDir() . '/install-cert.conf';
    }

    /**
     * Параметры шага «компоненты» для vpsrouter-install-components.sh.
     * Единственный флаг — ставить ли AmneziaWG (модуль ядра тяжёлый и не на всех ОС).
     * Скрипт принимает строго AMNEZIA=0|1 и игнорирует прочее.
     */
    public static function writeComponentsConf(bool $installAmnezia): void
    {
        $path = self::componentsConfPath();
        if (@file_put_contents($path, 'AMNEZIA=' . ($installAmnezia ? '1' : '0') . "\n") === false) {
            throw new \RuntimeException('Не удалось записать ' . $path);
        }
        @chmod($path, 0640);
    }

    /**
     * Параметры шага «сертификат» для vpsrouter-issue-cert.sh. Домен и e-mail
     * валидируются здесь (панель — доверенный писатель), а скрипт всё равно
     * перепроверяет формат перед вызовом certbot (defense-in-depth).
     */
    public static function writeCertConf(string $domain, ?string $email = null): void
    {
        $domain = trim($domain);
        // Строгая проверка: буквы/цифры/точки/дефис, хотя бы одна точка, ≤253, без переводов строк.
        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $domain) || !str_contains($domain, '.')) {
            throw new \InvalidArgumentException('Недопустимый домен для сертификата');
        }
        $lines = ['DOMAIN=' . $domain];
        $email = $email !== null ? trim($email) : '';
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Недопустимый e-mail для Let\'s Encrypt');
            }
            $lines[] = 'EMAIL=' . $email;
        }
        $path = self::certConfPath();
        if (@file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Не удалось записать ' . $path);
        }
        @chmod($path, 0640);
    }

    /** Автонастройка Reality/протоколов «с нуля»: IP, ключи, SNI, дефолтный VLESS. */
    public static function configureFresh(?string $sni = null): array
    {
        $notes = [];
        $ip = Setting::get('reality_listen_ip') ?: NetworkInfo::detectPublicIp();
        if ($ip) {
            Setting::set('reality_listen_ip', $ip);
            if (!Setting::get('reality_public_host')) {
                Setting::set('reality_public_host', $ip);
            }
            $notes[] = "IP: $ip";
        } else {
            $notes[] = 'IP не определён автоматически — задайте вручную в Настройках после входа.';
        }
        $port = (int) Setting::get('reality_listen_port', '0');
        if (!$port) {
            $port = ($ip && method_exists(NetworkInfo::class, 'findFreePort')) ? (NetworkInfo::findFreePort($ip) ?: 443) : 443;
            Setting::set('reality_listen_port', (string) $port);
        }
        $notes[] = "порт: $port";

        if (!Setting::get('reality_private_key')) {
            $kp = RealityKeys::generateKeypair();
            Setting::set('reality_private_key', $kp['private']);
            Setting::set('reality_public_key', $kp['public']);
            Setting::set('reality_short_id', RealityKeys::shortId());
            $notes[] = 'Reality-ключи сгенерированы';
        }
        $sni = $sni ?: (Setting::get('reality_server_name') ?: self::POPULAR_SNI[array_rand(self::POPULAR_SNI)]);
        Setting::set('reality_server_name', $sni);
        $notes[] = "камуфляж-домен: $sni";

        // VLESS включён по умолчанию; догенерируем недостающие секреты протоколов.
        DeviceInbounds::ensureSecrets();
        return $notes;
    }

    public static function createAdmin(string $username, string $password): void
    {
        $username = trim($username);
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $username)) {
            throw new \InvalidArgumentException('Логин: 2–32 символа, латиница/цифры/._-');
        }
        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('Пароль должен быть не короче 10 символов');
        }
        $pdo = Database::get();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ex = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $ex->execute([$username]);
        if ($ex->fetchColumn()) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?')->execute([$hash, $username]);
        } else {
            $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute([$username, $hash]);
        }
        // Ставим постоянный маркер: с этого момента мастер закрыт навсегда,
        // даже если файлы установщика позже снова окажутся на сервере.
        @file_put_contents(self::installedMarkerPath(), gmdate('c') . "\n");
    }

    /** Удаляет файлы установщика и одноразовый токен, чтобы его нельзя было запустить повторно. */
    public static function selfDestruct(): void
    {
        foreach ([
            __DIR__ . '/../public/install.php',
            __DIR__ . '/../public/api/install.php',
            __DIR__ . '/../public/api/install-run.php',
        ] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        // Гасим «уникальную ссылку» — токен больше не действителен.
        $tok = self::tokenFile();
        if (is_file($tok)) {
            @unlink($tok);
        }
        // Убираем временные conf-файлы шагов мастера (домен/e-mail для certbot, флаг AmneziaWG).
        foreach ([self::componentsConfPath(), self::certConfPath()] as $conf) {
            if (is_file($conf)) {
                @unlink($conf);
            }
        }
    }
}
