<?php

namespace App;

/**
 * TOTP (RFC 6238) — второй фактор для входа в панель. Чистый PHP, без внешних
 * зависимостей: base32 + HMAC-SHA1, шаг 30с, 6 цифр. Совместимо с Google
 * Authenticator, Aegis, 1Password, FreeOTP и т.п. (стандартный otpauth://).
 */
class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGO = 'sha1';
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Новый секрет: 20 байт (160 бит) в base32 без паддинга. */
    public static function generateSecret(): string
    {
        return self::base32encode(random_bytes(20));
    }

    /** otpauth://-ссылка для QR. issuer/account попадают в имя записи в приложении. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $q = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGO),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return "otpauth://totp/$label?$q";
    }

    /** Код для конкретного момента времени (по умолчанию — сейчас). */
    public static function codeAt(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);
        return self::hotp($secret, $counter);
    }

    /**
     * Проверка кода с окном ±$window шагов (терпимость к рассинхрону часов).
     * Постоянное по времени сравнение.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $counter = intdiv(time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function hotp(string $secret, int $counter): string
    {
        $key = self::base32decode($secret);
        $bin = pack('N*', 0) . pack('N*', $counter); // 8-байтный счётчик (big-endian)
        $hash = hash_hmac(self::ALGO, $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $part = substr($hash, $offset, 4);
        $value = (unpack('N', $part)[1] & 0x7fffffff) % (10 ** self::DIGITS);
        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /** Набор одноразовых кодов восстановления (на случай потери телефона). */
    public static function generateRecoveryCodes(int $n = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $codes[] = strtolower(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        }
        return $codes;
    }
}
