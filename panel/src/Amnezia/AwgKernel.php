<?php

namespace App\Amnezia;

/**
 * Детект поддержки AmneziaWG на ТЕКУЩЕМ узле (где выполняется код панели): есть
 * ли kernel-модуль amneziawg или userspace-реализация, и инструменты awg/awg-quick.
 * Нужно, чтобы предупредить «интерфейс не поднимется» до Apply. Для удалённых
 * роутеров проверка неприменима (там своё окружение) — только для self.
 */
class AwgKernel
{
    private static function which(string $bin): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }

    /** Загружен/доступен kernel-модуль amneziawg. */
    public static function moduleAvailable(): bool
    {
        if (@is_dir('/sys/module/amneziawg')) {
            return true;
        }
        if (function_exists('shell_exec')) {
            $out = @shell_exec('modinfo amneziawg 2>/dev/null');
            if (is_string($out) && trim($out) !== '') {
                return true;
            }
        }
        return false;
    }

    /** Есть инструменты awg-quick/awg. */
    public static function toolAvailable(): bool
    {
        return self::which('awg-quick') || self::which('awg');
    }

    /** Есть userspace-реализация (amneziawg-go). */
    public static function userspaceAvailable(): bool
    {
        return self::which('amneziawg-go');
    }

    /** Можно ли реально поднять AmneziaWG-интерфейс на этом узле. */
    public static function usable(): bool
    {
        return self::toolAvailable() && (self::moduleAvailable() || self::userspaceAvailable());
    }

    /** @return array{tool:bool,module:bool,userspace:bool,usable:bool} */
    public static function summary(): array
    {
        return [
            'tool' => self::toolAvailable(),
            'module' => self::moduleAvailable(),
            'userspace' => self::userspaceAvailable(),
            'usable' => self::usable(),
        ];
    }
}
