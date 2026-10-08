<?php

namespace App;

/**
 * Запускает СТРОГО ОГРАНИЧЕННЫЙ набор привилегированных скриптов установщика и
 * даёт читать их вывод построчно (для живого показа в мастере через SSE).
 *
 * Безопасность:
 *  - только действия из белого списка ACTIONS (имя → абсолютный путь скрипта);
 *  - НИКАКИХ аргументов от пользователя в команду не попадает;
 *  - скрипт запускается ОТСОЕДИНЁННО (setsid) и пишет в лог-файл, поэтому обрыв
 *    HTTP-соединения его не убивает — при переподключении мастер до-читывает лог;
 *  - www-data получает право только на эти скрипты через /etc/sudoers.d/panel-install
 *    (см. deploy/install.sh); сам PHP root не получает.
 */
class InstallRunner
{
    /**
     * Белый список: действие => абсолютный путь привилегированного скрипта (ставит install.sh).
     * ВАЖНО: скрипты запускаются БЕЗ аргументов. Поэтому share-443 здесь работает
     * только в режиме dry-run (показать план) — разрушающий `--apply` требует
     * аргументов и доступен исключительно из консоли. Мастер не может сам себя
     * отрезать от 443.
     */
    private const ACTIONS = [
        'preflight'  => '/usr/local/sbin/vpsrouter-preflight.sh',
        'share443'   => '/usr/local/sbin/vpsrouter-share-443.sh', // без аргументов = dry-run (только план)
        // Тяжёлые шаги тонкого bootstrap'а. Аргументов не принимают: параметры
        // (AmneziaWG да/нет, домен/e-mail) панель пишет в /var/lib/panel/*.conf,
        // а скрипты строго их валидируют. См. App\Installer::writeComponentsConf/writeCertConf.
        'components' => '/usr/local/sbin/vpsrouter-install-components.sh',
        'cert'       => '/usr/local/sbin/vpsrouter-issue-cert.sh',
    ];

    public static function isAllowed(string $action): bool
    {
        return isset(self::ACTIONS[$action]);
    }

    private static function runDir(): string
    {
        $dir = rtrim(dirname((string) App::config()['db_path']), '/') . '/install-run';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public static function logFile(string $action): string
    {
        return self::runDir() . "/$action.log";
    }

    private static function doneFile(string $action): string
    {
        return self::runDir() . "/$action.done";
    }

    /** Запущен ли сейчас (лог есть, маркер завершения ещё нет). */
    public static function isRunning(string $action): bool
    {
        return is_file(self::logFile($action)) && !is_file(self::doneFile($action));
    }

    /** Код завершения, если скрипт закончил, иначе null. */
    public static function exitCode(string $action): ?int
    {
        $d = self::doneFile($action);
        return is_file($d) ? (int) trim((string) @file_get_contents($d)) : null;
    }

    /**
     * Запускает действие отсоединённо (если ещё не запущено/не завершено).
     * @throws \InvalidArgumentException при неизвестном действии
     */
    public static function start(string $action): void
    {
        if (!self::isAllowed($action)) {
            throw new \InvalidArgumentException("Неизвестное действие установщика: $action");
        }
        // Уже идёт — не запускаем второй раз.
        if (self::isRunning($action)) {
            return;
        }
        $script = self::ACTIONS[$action]; // константа, не пользовательский ввод
        $log = self::logFile($action);
        $done = self::doneFile($action);
        @unlink($log);
        @unlink($done);

        // Язык логов провижининга: прокидываем выбранный в панели язык в скрипты
        // (_lib.sh читает VPSR_LANG). Через sudo переменная проходит благодаря
        // `Defaults env_keep += "VPSR_LANG"` в /etc/sudoers.d/panel-install.
        $lang = \App\I18n::lang() === 'en' ? 'en' : 'ru';
        // Внутренняя команда: sudo -n <script> > log 2>&1; код возврата -> done.
        $inner = sprintf(
            'export VPSR_LANG=%s; sudo -n %s > %s 2>&1; echo $? > %s',
            escapeshellarg($lang),
            escapeshellarg($script),
            escapeshellarg($log),
            escapeshellarg($done)
        );
        // Отсоединяем от текущего запроса, чтобы обрыв браузера не убил процесс.
        $cmd = sprintf('setsid sh -c %s >/dev/null 2>&1 &', escapeshellarg($inner));
        exec($cmd);
    }

    /**
     * Читает лог с байтового смещения $offset. Возвращает новый кусок текста,
     * новое смещение, флаг завершения и код возврата. Для SSE-дочитывания.
     *
     * @return array{chunk:string,offset:int,done:bool,code:?int}
     */
    public static function readFrom(string $action, int $offset): array
    {
        $log = self::logFile($action);
        $chunk = '';
        if (is_file($log)) {
            $fh = @fopen($log, 'rb');
            if ($fh) {
                if ($offset > 0) {
                    @fseek($fh, $offset);
                }
                $data = stream_get_contents($fh);
                if ($data !== false) {
                    $chunk = $data;
                    $offset += strlen($data);
                }
                @fclose($fh);
            }
        }
        return [
            'chunk' => $chunk,
            'offset' => $offset,
            'done' => is_file(self::doneFile($action)),
            'code' => self::exitCode($action),
        ];
    }
}
