<?php

namespace App;

/**
 * Определение страны по IP — ТОЛЬКО офлайн, без внешних API (иначе адреса
 * назначения пользователей утекали бы третьей стороне). Работает, если в PHP
 * есть расширение geoip; иначе возвращает null, а флаг рисуется как глобус.
 * Чтобы включить флаги: установить php-geoip + базу GeoLite2/Country.
 */
class GeoIp
{
    public static function available(): bool
    {
        return function_exists('geoip_country_code_by_name');
    }

    /** ISO 3166-1 alpha-2 код страны (в верхнем регистре) или null. */
    public static function country(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || !self::available()) {
            return null;
        }
        $cc = @geoip_country_code_by_name($ip);
        return (is_string($cc) && strlen($cc) === 2 && ctype_alpha($cc)) ? strtoupper($cc) : null;
    }
}
