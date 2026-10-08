<?php

namespace App;

/**
 * Панель ставится на разные серверы с разной сетевой топологией (один
 * публичный IP, несколько IP, Docker/AmneziaWG/туннельные интерфейсы на
 * машине и т.д.) — поэтому Reality-настройки (слушающий IP, порт) нельзя
 * зашивать как константу ни в код, ни в документацию "обычно второй IPv4".
 * Вместо этого детектируем реальное состояние конкретного сервера на месте.
 */
class NetworkInfo
{
    /** Порты-кандидаты для Reality, если 443 занят (сам sing-box, nginx/apache под панелью и т.п.). */
    public const CANDIDATE_PORTS = [443, 8443, 2053, 2083, 2087, 2096];

    private const VIRTUAL_IFACE_PREFIXES = ['lo', 'docker', 'veth', 'br-', 'amn', 'wg', 'tun', 'virbr'];

    /**
     * Первый публичный (не приватный/loopback/локальный) IPv4-адрес сервера,
     * найденный среди его сетевых интерфейсов. Требует sockets-расширение
     * (net_get_interfaces() — PHP 7.3+); без него возвращает null, и панель
     * просто не подставляет IP автоматически — ручной ввод остаётся рабочим
     * запасным путём.
     */
    public static function detectPublicIp(): ?string
    {
        if (!function_exists('net_get_interfaces')) {
            return null;
        }
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return null;
        }
        foreach ($interfaces as $name => $iface) {
            foreach (self::VIRTUAL_IFACE_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }
            foreach ($iface['unicast'] ?? [] as $addr) {
                $ip = $addr['address'] ?? null;
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    /**
     * Пытается сама забиндиться на $ip:$port на мгновение — надёжнее, чем
     * пробовать подключиться (это ловит "порт уже занят другим процессом",
     * а не "есть ли там что-то отвечающее"). Именно так же будет биндиться
     * sing-box при старте, так что проверка отражает реальную ситуацию.
     */
    public static function isPortFree(string $ip, int $port): bool
    {
        $server = @stream_socket_server("tcp://$ip:$port", $errno, $errstr);
        if ($server) {
            fclose($server);
            return true;
        }
        return false;
    }

    /** Первый свободный порт из списка кандидатов (или переданного списка), либо null. */
    public static function findFreePort(string $ip, ?array $candidates = null): ?int
    {
        foreach ($candidates ?? self::CANDIDATE_PORTS as $port) {
            if (self::isPortFree($ip, $port)) {
                return $port;
            }
        }
        return null;
    }
}
