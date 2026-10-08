<?php

namespace App;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Узкий, самодостаточный SSH-сервис поверх phpseclib3 — специально не
 * привязан к остальной панели (только примитивные типы на входе/выходе),
 * чтобы его можно было перенести в другой проект без изменений.
 *
 * Никогда не выполняет команды, собранные из пользовательского ввода:
 * host/user/port проходят строгую валидацию и передаются в SSH2
 * как отдельные параметры (phpseclib не вызывает системную оболочку —
 * инъекция на уровне ОС здесь в принципе невозможна), а сама remote-
 * команда — фиксированная строка без подстановок.
 */
class Ssh
{
    private const CONNECT_TIMEOUT = 8;

    // Строго hostname (RFC 1123) или IPv4/IPv6, без ведущего "-" —
    // защита от опций-в-виде-хоста, даже если реализация клиента сменится.
    private const HOST_PATTERN = '/^(?!-)[A-Za-z0-9.:_-]{1,255}$/';

    public static function testConnection(
        string $host,
        int $port,
        string $user,
        string $privateKey,
        ?string $passphrase = null
    ): array {
        self::assertSafeHost($host);
        self::assertSafeUser($user);
        if ($port < 1 || $port > 65535) {
            return self::failure(I18n::t('ssh.bad_port'));
        }

        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase ?? false);
        } catch (\Throwable $e) {
            return self::failure(I18n::t('ssh.key_parse', $e->getMessage()));
        }

        $ssh = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(self::CONNECT_TIMEOUT);

        try {
            if (!$ssh->login($user, $key)) {
                return self::failure(I18n::t('ssh.auth_fail_hint'));
            }
        } catch (\Throwable $e) {
            return self::failure(I18n::t('ssh.connect_fail', $e->getMessage()));
        }

        return self::collectDiagnostics($ssh);
    }

    /**
     * То же, что testConnection(), но вход по паролю — для кнопки «Проверить»
     * до того, как ключ создан. Пароль нигде не сохраняется.
     */
    public static function testConnectionWithPassword(string $host, int $port, string $user, string $password): array
    {
        self::assertSafeHost($host);
        self::assertSafeUser($user);
        if ($port < 1 || $port > 65535) {
            return self::failure(I18n::t('ssh.bad_port'));
        }

        $ssh = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(self::CONNECT_TIMEOUT);
        try {
            if (!$ssh->login($user, $password)) {
                return self::failure(self::passwordLoginHint());
            }
        } catch (\Throwable $e) {
            return self::failure(I18n::t('ssh.connect_fail', $e->getMessage()));
        }

        return self::collectDiagnostics($ssh);
    }

    /**
     * Заходит по логину/паролю, генерирует новую Ed25519-пару и дописывает
     * публичный ключ в ~/.ssh/authorized_keys на сервере, затем проверяет
     * вход уже по ключу. Пароль используется один раз и не сохраняется —
     * дальше панель работает только по ключу.
     *
     * @return array{ok:bool,private_key:?string,public_key:?string,raw_error:?string}
     */
    public static function bootstrapKeyWithPassword(string $host, int $port, string $user, string $password): array
    {
        self::assertSafeHost($host);
        self::assertSafeUser($user);
        if ($port < 1 || $port > 65535) {
            return self::bootstrapFailure(I18n::t('ssh.bad_port'));
        }
        if ($password === '') {
            return self::bootstrapFailure(I18n::t('ssh.pw_missing'));
        }

        $ssh = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(20);
        try {
            if (!$ssh->login($user, $password)) {
                return self::bootstrapFailure(self::passwordLoginHint());
            }
        } catch (\Throwable $e) {
            return self::bootstrapFailure(I18n::t('ssh.connect_fail', $e->getMessage()));
        }

        $key = EC::createKey('Ed25519');
        $privateKey = $key->toString('OpenSSH');
        $publicKey = trim($key->getPublicKey()->toString('OpenSSH', ['comment' => 'vps-router-panel']));

        // Публичный ключ — base64 + фиксированный комментарий, но всё равно
        // экранируем. Команда идемпотентна: повторный запуск не плодит дубли.
        $quoted = escapeshellarg($publicKey);
        $cmd = 'umask 077; mkdir -p ~/.ssh && touch ~/.ssh/authorized_keys'
            . ' && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys'
            . " && (grep -qxF $quoted ~/.ssh/authorized_keys || echo $quoted >> ~/.ssh/authorized_keys)"
            . ' && (command -v restorecon >/dev/null 2>&1 && restorecon -R ~/.ssh >/dev/null 2>&1; true)'
            . ' && echo KEY_INSTALLED';
        $out = (string) $ssh->exec($cmd);
        if (!str_contains($out, 'KEY_INSTALLED')) {
            return self::bootstrapFailure(I18n::t('ssh.authkeys_fail', trim($out . ' ' . $ssh->getStdError())));
        }

        // Проверяем, что сервер реально пускает по новому ключу (sshd может
        // иметь PubkeyAuthentication no или нестандартный AuthorizedKeysFile).
        $check = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $check->setTimeout(self::CONNECT_TIMEOUT);
        try {
            if (!$check->login($user, PublicKeyLoader::load($privateKey))) {
                return self::bootstrapFailure(I18n::t('ssh.key_not_accepted'));
            }
        } catch (\Throwable $e) {
            return self::bootstrapFailure(I18n::t('ssh.key_verify_fail', $e->getMessage()));
        }

        return ['ok' => true, 'private_key' => $privateKey, 'public_key' => $publicKey, 'raw_error' => null];
    }

    private static function passwordLoginHint(): string
    {
        return I18n::t('ssh.pw_login_fail');
    }

    private static function bootstrapFailure(string $message): array
    {
        return ['ok' => false, 'private_key' => null, 'public_key' => null, 'raw_error' => $message];
    }

    /** Фиксированные read-only команды диагностики + метрики нагрузки в той же SSH-сессии. */
    private static function collectDiagnostics(SSH2 $ssh): array
    {
        // Один pipeline, без пользовательских данных внутри — только
        // фиксированные read-only команды для диагностики.
        $remoteCommand = 'hostname 2>/dev/null; echo "---"; uname -srm 2>/dev/null; echo "---"; '
            . '(uptime -p 2>/dev/null || uptime 2>/dev/null); echo "---"; '
            . '(cat /etc/os-release 2>/dev/null | head -3); echo "---"; '
            . '(ip -4 -o addr show 2>/dev/null | awk \'{print $2, $4}\'); echo "---"; '
            . '(sudo -n true 2>/dev/null && echo SUDO_OK || echo SUDO_NO)';

        $output = $ssh->exec($remoteCommand);
        $parts = array_map('trim', explode('---', (string) $output));
        $parts = array_pad($parts, 6, '');

        // Метрики — отдельным exec в той же сессии: health-check раз в минуту
        // получает нагрузку для графа без второго SSH-логина.
        $metrics = null;
        try {
            $metrics = self::parseMetrics((string) $ssh->exec(self::METRICS_COMMAND));
        } catch (\Throwable $e) {
            // метрики — необязательная часть проверки
        }
        // Счётчики основного интерфейса — для лимита трафика тарифа (App\ServerTraffic).
        $net = null;
        try {
            $net = ServerTraffic::parseCounters((string) $ssh->exec(ServerTraffic::REMOTE_COMMAND));
        } catch (\Throwable $e) {
            // необязательно
        }

        return [
            'ok' => true,
            'hostname' => $parts[0],
            'kernel' => $parts[1],
            'uptime' => $parts[2],
            'os' => $parts[3],
            'ipv4' => array_values(array_filter(explode("\n", $parts[4]))),
            'sudo' => trim($parts[5]) === 'SUDO_OK',
            'metrics' => $metrics && $metrics['ok'] ? $metrics : null,
            'net' => $net,
            'raw_error' => null,
        ];
    }

    private const METRICS_COMMAND = 'echo "---LOAD---"; cat /proc/loadavg 2>/dev/null; '
        . 'echo "---CPUS---"; (nproc 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null); '
        . 'echo "---MEM---"; free -m 2>/dev/null; '
        . 'echo "---DISK---"; df -Pk / 2>/dev/null';

    /**
     * "Стучит" в последовательность портов по порядку — та же логика, что
     * deploy/provision/knock-client.sh (TCP SYN на каждый порт, соединяться
     * не обязательно: iptables recent-модуль на сервере видит сам пакет).
     * Порт не обязан отвечать — таймаут/отказ соединения ожидаемы и не
     * являются ошибкой, поэтому исключений не бросает.
     */
    public static function knock(string $host, array $ports, float $delaySeconds = 0.5, int $timeoutSeconds = 2): void
    {
        self::assertSafeHost($host);
        foreach ($ports as $port) {
            $port = (int) $port;
            if ($port < 1 || $port > 65535) {
                continue;
            }
            $conn = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
            if ($conn) {
                fclose($conn);
            }
            usleep((int) ($delaySeconds * 1_000_000));
        }
    }

    /**
     * Текущая загрузка CPU/RAM/диска — отдельный SSH-вызов от testConnection(),
     * чтобы периодический health-check (который должен быть быстрым и лёгким)
     * не тянул за собой эти команды каждый раз; метрики запрашиваются только
     * по явному клику пользователя на карточку сервера.
     *
     * @return array{ok:bool,load:?array{1min:float,5min:float,15min:float},mem:?array{total_mb:int,used_mb:int,available_mb:int,used_percent:int},disk:?array{total_kb:int,used_kb:int,avail_kb:int,used_percent:int},raw_error:?string}
     */
    public static function fetchMetrics(
        string $host,
        int $port,
        string $user,
        string $privateKey,
        ?string $passphrase = null
    ): array {
        self::assertSafeHost($host);
        self::assertSafeUser($user);
        if ($port < 1 || $port > 65535) {
            return self::metricsFailure(I18n::t('ssh.bad_port'));
        }

        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase ?? false);
        } catch (\Throwable $e) {
            return self::metricsFailure(I18n::t('ssh.key_parse', $e->getMessage()));
        }

        $ssh = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(self::CONNECT_TIMEOUT);

        try {
            if (!$ssh->login($user, $key)) {
                return self::metricsFailure(I18n::t('ssh.auth_fail'));
            }
        } catch (\Throwable $e) {
            return self::metricsFailure(I18n::t('ssh.connect_fail', $e->getMessage()));
        }

        return self::parseMetrics((string) $ssh->exec(self::METRICS_COMMAND));
    }

    /**
     * Выполняет ФИКСИРОВАННУЮ команду на удалённом узле по SSH-ключу и возвращает
     * её вывод. $command должен быть литералом вызывающего кода (не пользовательский
     * ввод) — используется, например, для `systemctl is-active sing-box` в health-check.
     *
     * @return array{ok:bool,output:string,error:?string}
     */
    public static function runCommand(
        string $host,
        int $port,
        string $user,
        string $privateKey,
        string $command,
        ?string $passphrase = null
    ): array {
        self::assertSafeHost($host);
        self::assertSafeUser($user);
        if ($port < 1 || $port > 65535) {
            return ['ok' => false, 'output' => '', 'error' => I18n::t('ssh.bad_port')];
        }
        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase ?? false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => I18n::t('ssh.key_prefix', $e->getMessage())];
        }
        $ssh = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(self::CONNECT_TIMEOUT);
        try {
            if (!$ssh->login($user, $key)) {
                return ['ok' => false, 'output' => '', 'error' => I18n::t('ssh.auth_fail')];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => I18n::t('ssh.connect_prefix', $e->getMessage())];
        }
        return ['ok' => true, 'output' => trim((string) $ssh->exec($command)), 'error' => null];
    }

    public static function parseMetrics(string $output): array
    {
        $sections = [];
        $current = null;
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^---(LOAD|CPUS|MEM|DISK)---$/', trim($line), $m)) {
                $current = $m[1];
                $sections[$current] = [];
                continue;
            }
            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        $load = null;
        if (!empty($sections['LOAD'][0]) && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', trim($sections['LOAD'][0]), $m)) {
            $load = ['1min' => (float) $m[1], '5min' => (float) $m[2], '15min' => (float) $m[3]];
        }

        $mem = null;
        foreach ($sections['MEM'] ?? [] as $line) {
            if (preg_match('/^Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+\d+\s+\d+\s+(\d+)/', trim($line), $m)) {
                $total = (int) $m[1];
                $available = (int) $m[4];
                $used = max(0, $total - $available);
                $mem = [
                    'total_mb' => $total,
                    'used_mb' => $used,
                    'available_mb' => $available,
                    'used_percent' => $total > 0 ? (int) round($used / $total * 100) : 0,
                ];
                break;
            }
        }

        $disk = null;
        foreach (array_slice($sections['DISK'] ?? [], 1) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) >= 5 && is_numeric($cols[1])) {
                $disk = [
                    'total_kb' => (int) $cols[1],
                    'used_kb' => (int) $cols[2],
                    'avail_kb' => (int) $cols[3],
                    'used_percent' => (int) rtrim($cols[4], '%'),
                ];
                break;
            }
        }

        $cpus = null;
        if (!empty($sections['CPUS'][0]) && ctype_digit(trim($sections['CPUS'][0]))) {
            $cpus = max(1, (int) trim($sections['CPUS'][0]));
        }

        return [
            'ok' => $load !== null || $mem !== null || $disk !== null,
            'load' => $load,
            'cpus' => $cpus,
            // Нагрузка CPU в % ≈ load average за 1 мин / число ядер — то, что
            // показывается на узле графа; без числа ядер считаем как 1 ядро.
            'cpu_percent' => $load !== null ? (int) min(100, round($load['1min'] / ($cpus ?: 1) * 100)) : null,
            'mem' => $mem,
            'disk' => $disk,
            'raw_error' => ($load === null && $mem === null && $disk === null)
                ? I18n::t('ssh.parse_metrics_fail')
                : null,
        ];
    }

    private static function metricsFailure(string $message): array
    {
        return ['ok' => false, 'load' => null, 'cpus' => null, 'cpu_percent' => null, 'mem' => null, 'disk' => null, 'raw_error' => $message];
    }

    /**
     * Суммарные rx+tx байты по всем интерфейсам кроме lo — не нужно знать
     * имя конкретного публичного интерфейса на exit-сервере. Используется
     * bin/collect_exit_load.php: сырой счётчик + метка времени, скорость
     * считается снаружи как дельта между двумя последовательными замерами.
     *
     * @return array{ok:bool,bytes_total:?int,raw_error:?string}
     */
    public static function fetchNetworkTotals(
        string $host,
        int $port,
        string $user,
        string $privateKey,
        ?string $passphrase = null
    ): array {
        self::assertSafeHost($host);
        self::assertSafeUser($user);
        if ($port < 1 || $port > 65535) {
            return self::networkFailure(I18n::t('ssh.bad_port'));
        }

        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase ?? false);
        } catch (\Throwable $e) {
            return self::networkFailure(I18n::t('ssh.key_parse', $e->getMessage()));
        }

        $ssh = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(self::CONNECT_TIMEOUT);

        try {
            if (!$ssh->login($user, $key)) {
                return self::networkFailure(I18n::t('ssh.auth_fail'));
            }
        } catch (\Throwable $e) {
            return self::networkFailure(I18n::t('ssh.connect_fail', $e->getMessage()));
        }

        return self::parseNetworkTotals((string) $ssh->exec('cat /proc/net/dev 2>/dev/null'));
    }

    private static function parseNetworkTotals(string $output): array
    {
        $total = 0;
        $found = false;
        foreach (explode("\n", $output) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$iface, $rest] = explode(':', $line, 2);
            $iface = trim($iface);
            if ($iface === '' || $iface === 'lo') {
                continue;
            }
            $cols = preg_split('/\s+/', trim($rest));
            if (count($cols) < 9 || !is_numeric($cols[0]) || !is_numeric($cols[8])) {
                continue;
            }
            $total += (int) $cols[0] + (int) $cols[8]; // rx_bytes + tx_bytes
            $found = true;
        }

        return [
            'ok' => $found,
            'bytes_total' => $found ? $total : null,
            'raw_error' => $found ? null : I18n::t('ssh.parse_netdev_fail'),
        ];
    }

    private static function networkFailure(string $message): array
    {
        return ['ok' => false, 'bytes_total' => null, 'raw_error' => $message];
    }

    /**
     * Заливает и выполняет ФИКСИРОВАННЫЙ скрипт из репозитория на удалённом
     * сервере. $scriptPath — ВСЕГДА путь, зашитый вызывающим кодом (одна из
     * констант deploy/provision/*.sh), никогда не строка от пользователя/API.
     * Параметры передаются скрипту через отдельный JSON-файл (права 0600),
     * путь к которому — единственное, что попадает в командную строку —
     * не через подстановку значений в shell-текст: скрипт сам читает и
     * парсит файл (jq) уже находясь внутри контролируемого bash-процесса.
     * Значения параметров физически не могут быть интерпретированы shell'ом.
     *
     * @param array<string,mixed> $params
     * @return array{ok:bool,exit_code:?int,stdout:string,stderr:string,raw_error:?string}
     */
    public static function runProvisionScript(
        string $host,
        int $port,
        string $user,
        string $privateKey,
        string $scriptPath,
        array $params,
        ?string $passphrase = null
    ): array {
        self::assertSafeHost($host);
        self::assertSafeUser($user);
        if (!is_file($scriptPath)) {
            throw new \InvalidArgumentException(I18n::t('ssh.script_not_found', $scriptPath));
        }

        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase ?? false);
        } catch (\Throwable $e) {
            return self::scriptFailure(I18n::t('ssh.key_parse', $e->getMessage()));
        }

        $sftp = new SFTP($host, $port, self::CONNECT_TIMEOUT);
        try {
            if (!$sftp->login($user, $key)) {
                return self::scriptFailure(I18n::t('ssh.auth_fail'));
            }
        } catch (\Throwable $e) {
            return self::scriptFailure(I18n::t('ssh.connect_fail', $e->getMessage()));
        }

        $token = bin2hex(random_bytes(8));
        $remoteScript = "/tmp/panel-provision-$token.sh";
        $remoteParams = "/tmp/panel-provision-$token.json";

        $script = (string) file_get_contents($scriptPath);
        // Общие функции (определение ОС, установка пакетов/sing-box/AmneziaWG
        // под Debian/Ubuntu/CentOS/Rocky/Alma/Fedora/...) — лежат рядом со
        // скриптами в _lib.sh и приклеиваются перед каждым из них.
        $lib = dirname($scriptPath) . '/_lib.sh';
        // Прокидываем язык панели в логи провижининга (_lib.sh/_t читают VPSR_LANG).
        // Экспорт ставим ПЕРЕД _lib.sh, чтобы его "${VPSR_LANG:-ru}" взял наш выбор.
        $langExport = 'export VPSR_LANG=' . (I18n::lang() === 'en' ? 'en' : 'ru') . "\n";
        if (is_file($lib)) {
            $script = "#!/usr/bin/env bash\n" . $langExport . file_get_contents($lib) . "\n" . $script;
        } else {
            $script = "#!/usr/bin/env bash\n" . $langExport . $script;
        }
        $json = json_encode($params, JSON_UNESCAPED_SLASHES);
        if (!$sftp->put($remoteScript, $script) || !$sftp->put($remoteParams, $json)) {
            return self::scriptFailure(I18n::t('ssh.upload_fail'));
        }
        $sftp->chmod(0700, $remoteScript);
        $sftp->chmod(0600, $remoteParams);

        $ssh = new SSH2($host, $port, self::CONNECT_TIMEOUT);
        $ssh->setTimeout(120); // установка пакетов может занять больше времени, чем диагностика
        $exitCode = null;
        $stdout = '';
        try {
            if (!$ssh->login($user, $key)) {
                return self::scriptFailure(I18n::t('ssh.auth_fail'));
            }
            $stdout = $ssh->exec('bash ' . escapeshellarg($remoteScript) . ' ' . escapeshellarg($remoteParams));
            $exitCode = $ssh->getExitStatus();
        } finally {
            // Убираем временные файлы независимо от результата выполнения.
            $cleanup = new SSH2($host, $port, self::CONNECT_TIMEOUT);
            if ($cleanup->login($user, $key)) {
                $cleanup->exec('rm -f ' . escapeshellarg($remoteScript) . ' ' . escapeshellarg($remoteParams));
            }
        }

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $ssh->getStdError(),
            'raw_error' => null,
        ];
    }

    private static function scriptFailure(string $message): array
    {
        return ['ok' => false, 'exit_code' => null, 'stdout' => '', 'stderr' => '', 'raw_error' => $message];
    }

    private static function assertSafeHost(string $host): void
    {
        if (!preg_match(self::HOST_PATTERN, $host)) {
            throw new \InvalidArgumentException(I18n::t('ssh.bad_host'));
        }
    }

    private static function assertSafeUser(string $user): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $user)) {
            throw new \InvalidArgumentException(I18n::t('ssh.bad_user'));
        }
    }

    private static function failure(string $message): array
    {
        return [
            'ok' => false,
            'hostname' => null,
            'kernel' => null,
            'uptime' => null,
            'os' => null,
            'ipv4' => [],
            'sudo' => false,
            'metrics' => null,
            'raw_error' => $message,
        ];
    }
}
