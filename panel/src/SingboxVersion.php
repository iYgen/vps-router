<?php

namespace App;

/**
 * Определяет установленную версию sing-box (`sing-box version`) — чтобы панель
 * могла предупредить о несовместимости сгенерированного конфига с движком после
 * его обновления/отката (см. ConfigValidator::versionWarnings). Наш конфиг всегда
 * пересобирается из БД под текущий билдер, поэтому «миграция» здесь — это
 * проверка «версия движка ≥ требуемой для используемых возможностей».
 */
class SingboxVersion
{
    /** Возвращает версию вида "1.14.1" или null, если определить не удалось. */
    public static function installed(): ?string
    {
        static $done = false;
        static $ver = null;
        if ($done) {
            return $ver;
        }
        $done = true;
        if (!function_exists('shell_exec')) {
            return $ver;
        }
        foreach (['sing-box', '/usr/local/bin/sing-box', '/usr/bin/sing-box'] as $bin) {
            $out = @shell_exec(escapeshellarg($bin) . ' version 2>/dev/null');
            if (is_string($out) && preg_match('/version\s+v?(\d+\.\d+\.\d+)/i', $out, $m)) {
                return $ver = $m[1];
            }
        }
        return $ver;
    }
}
