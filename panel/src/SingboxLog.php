<?php

namespace App;

/**
 * Чтение рантайм-журнала sing-box (systemd) для страницы «Логи». Пользователь
 * панели обычно состоит в группе systemd-journal и читает журнал юнита без root
 * (проверено на бою). Это НЕ журнал действий панели (тот — App\Models\AuditLog).
 */
class SingboxLog
{
    private const UNIT = 'sing-box';

    /**
     * Последние $lines строк журнала юнита. $grep — необязательный фильтр
     * (подстрока, экранируется). Возвращает ['ok'=>bool,'lines'=>string[],'error'=>?string].
     *
     * @return array{ok:bool,lines:string[],error:?string}
     */
    public static function tail(int $lines = 200, string $grep = ''): array
    {
        $lines = max(1, min(2000, $lines));
        if (!function_exists('proc_open')) {
            return ['ok' => false, 'lines' => [], 'error' => 'proc_open отключён на сервере'];
        }
        $cmd = ['journalctl', '-u', self::UNIT, '-n', (string) $lines, '--no-pager', '-q', '-o', 'short-iso'];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'lines' => [], 'error' => 'не удалось запустить journalctl'];
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 && trim((string) $out) === '') {
            return ['ok' => false, 'lines' => [], 'error' => trim((string) $err) ?: 'журнал недоступен (нет прав на чтение systemd-journal?)'];
        }

        $rows = array_values(array_filter(explode("\n", (string) $out), fn($l) => trim($l) !== ''));
        if ($grep !== '') {
            $needle = mb_strtolower($grep);
            $rows = array_values(array_filter($rows, fn($l) => mb_strpos(mb_strtolower($l), $needle) !== false));
        }
        return ['ok' => true, 'lines' => $rows, 'error' => null];
    }
}
