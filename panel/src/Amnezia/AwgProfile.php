<?php

namespace App\Amnezia;

/**
 * Профиль обфускации AmneziaWG 3.1 — то же, что AwgFactory.randomObfs() в Android-приложении.
 *
 * - HeaderProtectionKey: заголовки пакетов шифруются (32 байта, base64), общий для обеих сторон.
 * - S1..S4 ≥ 12: из начала паддинга берётся nonce шифра заголовков (amneziawg-go это проверяет).
 * - Размеры сообщений рукопожатия (148+S1, 92+S2, 64+S3) не совпадают.
 * - H1..H4 = 1..4: тип сообщения всё равно скрыт шифрованием.
 * - ContentPaddingAddition: случайный паддинг пакетов с данными.
 * - I1: пакет перед рукопожатием в виде ответа DNS (из одних случайных байт часть мобильных сетей отбрасывает).
 * - RandomTrailers не включаем: в тестах он снижал скорость до ~0.1 МБ/с.
 *
 * Сервер с этим профилем понимают только клиенты 3.1: AmneziaVPN ≥ 5.0.1.5, AmneziaWG ≥ 3.1.4, VPS Router ≥ 0.9.0.
 */
class AwgProfile
{
    private const DNS_NAMES = ['yandex.ru', 'vk.com', 'mail.ru', 'ozon.ru', 'avito.ru', 'ya.ru', 'dzen.ru', 'rutube.ru'];

    public static function random31(): array
    {
        do {
            $s1 = random_int(15, 150);
            $s2 = random_int(15, 150);
            $s3 = random_int(12, 56);
        } while (count(array_unique([148 + $s1, 92 + $s2, 64 + $s3])) < 3);
        do {
            $s4 = random_int(12, 32);
        } while ($s4 === 16);
        $jmin = random_int(40, 69);
        $padLo = random_int(16, 47);
        return [
            'Jc' => random_int(3, 6), 'Jmin' => $jmin, 'Jmax' => $jmin + random_int(30, 149),
            'S1' => $s1, 'S2' => $s2, 'S3' => $s3, 'S4' => $s4,
            'H1' => 1, 'H2' => 2, 'H3' => 3, 'H4' => 4,
            'I1' => self::dnsLikeI1(),
            'HeaderProtectionKey' => base64_encode(random_bytes(32)),
            'ContentPaddingAddition' => $padLo . '-' . ($padLo + random_int(64, 111)),
        ];
    }

    public static function isV3(array $p): bool
    {
        return !empty($p['HeaderProtectionKey']);
    }

    /** Интервал keepalive клиента: у 3.1 случайный в диапазоне (ровный интервал — примета для DPI). */
    public static function keepalive(array $p): string
    {
        return self::isV3($p) ? '22-30' : '25';
    }

    /** I1: ответ DNS на A-запрос популярного домена — две A-записи и EDNS, ID и адреса случайные. */
    public static function dnsLikeI1(): string
    {
        $name = self::DNS_NAMES[random_int(0, count(self::DNS_NAMES) - 1)];
        $q = '';
        foreach (explode('.', $name) as $label) {
            $q .= sprintf('%02x', strlen($label)) . bin2hex($label);
        }
        $q .= '00' . '0001' . '0001';
        $ttl = fn() => sprintf('%08x', random_int(60, 3599));
        return '<r 2><b 0x81800001000200000001' . $q . '>'
            . '<b 0xc00c00010001' . $ttl() . '0004><r 4>'
            . '<b 0xc00c00010001' . $ttl() . '0004><r 4>'
            . '<b 0x0000291000000000000000>';
    }
}
