<?php

namespace App;

/**
 * Экспорт маршрутов (назначения, идущие через exit) под разные роутеры — рядом с
 * Keenetic .bat/HydraRoute. Генерирует только текст скриптов; применяет их
 * пользователь на своём роутере. Источник адресов — App\KeeneticExport::routedCidrs
 * (ip_cidr-правила + домены, резолвленные в IPv4).
 *
 * Поддержано:
 *   • MikroTik RouterOS — /ip route add … gateway=<iface>
 *   • OpenWrt — скрипт `ip route add … dev <iface>` (+ подсказка про таблицы/ip rule)
 *
 * Имя интерфейса/шлюза на роутере задаёт пользователь (там свой WG/туннель к входному VPS).
 */
class RouterExport
{
    /** Белый список целей экспорта: id => человекочитаемое имя. */
    public static function targets(): array
    {
        return [
            'mikrotik' => 'MikroTik (RouterOS script)',
            'openwrt' => 'OpenWrt (ip route script)',
        ];
    }

    private static function sanitizeIface(string $iface, string $default): string
    {
        $iface = trim($iface);
        // Имя интерфейса роутера: буквы/цифры/._- , до 32 символов.
        return preg_match('/^[A-Za-z0-9._-]{1,32}$/', $iface) ? $iface : $default;
    }

    /**
     * MikroTik RouterOS: статические маршруты назначений через VPN-интерфейс.
     * gateway — имя WG/интерфейса на MikroTik (по умолчанию «wg-vpn»).
     */
    public static function mikrotik(?int $routerId, bool $includeNull, string $gateway = 'wg-vpn'): string
    {
        $gateway = self::sanitizeIface($gateway, 'wg-vpn');
        $cidrs = KeeneticExport::routedCidrs($routerId, $includeNull);

        $out = [];
        $out[] = '# MikroTik RouterOS — маршруты vps_router через VPN';
        $out[] = '# Эти назначения в панели идут через exit-сервер. Вставьте в терминал';
        $out[] = '# роутера (gateway = имя вашего WG/туннельного интерфейса к входному VPS).';
        $out[] = '# Сгенерировано: ' . date('c') . ' · назначений: ' . count($cidrs);
        $out[] = '/ip route';
        foreach ($cidrs as $cidr) {
            $out[] = 'add dst-address=' . $cidr . ' gateway=' . $gateway . ' comment="vps_router"';
        }
        $out[] = '';
        $out[] = '# Удалить все маршруты vps_router: /ip route remove [find comment="vps_router"]';
        return implode("\n", $out) . "\n";
    }

    /**
     * OpenWrt: shell-скрипт `ip route add … dev <iface>`. iface — имя WG/туннельного
     * интерфейса на OpenWrt (по умолчанию «wg-vpn»). Для policy-routing (таблицы,
     * ip rule) — см. подсказку в шапке; это базовый вариант «через интерфейс».
     */
    public static function openwrt(?int $routerId, bool $includeNull, string $iface = 'wg-vpn'): string
    {
        $iface = self::sanitizeIface($iface, 'wg-vpn');
        $cidrs = KeeneticExport::routedCidrs($routerId, $includeNull);

        $out = [];
        $out[] = '#!/bin/sh';
        $out[] = '# OpenWrt — маршруты vps_router через VPN-интерфейс "' . $iface . '".';
        $out[] = '# Запустите на роутере (или добавьте в /etc/rc.local / hotplug).';
        $out[] = '# Для выборочной маршрутизации по policy-routing используйте';
        $out[] = '# отдельную таблицу и "ip rule"; здесь — простой вариант через dev.';
        $out[] = '# Сгенерировано: ' . date('c') . ' · назначений: ' . count($cidrs);
        $out[] = 'IFACE="' . $iface . '"';
        $out[] = '';
        foreach ($cidrs as $cidr) {
            $out[] = 'ip route add ' . $cidr . ' dev "$IFACE" 2>/dev/null || ip route replace ' . $cidr . ' dev "$IFACE"';
        }
        $out[] = '';
        $out[] = '# Откат: замените "add/replace" на "del" и выполните снова.';
        return implode("\n", $out) . "\n";
    }
}
