<?php

namespace App;

/**
 * VLESS+Reality X25519 keypair — тот же алгоритм (Curve25519), что
 * `sing-box generate reality-keypair`, и в той же текстовой кодировке:
 * base64 URL-safe БЕЗ padding (RFC 4648 §5). Обычный base64_encode() (со
 * стандартным алфавитом +/ и паддингом =) даёт байт-в-байт тот же ключ, но
 * в формате, который строгие Reality-клиенты (Xray-core и производные —
 * v2rayNG, NekoBox и т.п.) отклоняют как невалидный при парсинге vless://
 * ссылки ("invalid password: ...") — само REALITY-рукопожатие эти клиенты
 * даже не пытаются начать.
 */
class RealityKeys
{
    /** @return array{private: string, public: string} */
    public static function generateKeypair(): array
    {
        $kp = sodium_crypto_box_keypair();
        return [
            'private' => self::toBase64Url(sodium_crypto_box_secretkey($kp)),
            'public' => self::toBase64Url(sodium_crypto_box_publickey($kp)),
        ];
    }

    public static function shortId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function toBase64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Принимает и старый (стандартный base64, +/=) и правильный (url-safe) формат — для миграции уже сохранённых ключей. */
    public static function normalizeBase64Url(string $value): string
    {
        return rtrim(strtr($value, '+/', '-_'), '=');
    }
}
