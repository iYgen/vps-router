<?php

namespace App;

/**
 * Шифрование чувствительных полей (SSH-приватные ключи и т.п.) перед
 * записью в SQLite. Ключ выводится из app_secret в config.php (32 байта,
 * "openssl rand -hex 32" уже так и документирован в config.php.example).
 * Использует libsodium (ядро PHP с 7.2, доп. зависимостей не требует).
 *
 * Значения читаются моделями только узкими методами, никогда не попадают
 * в *::all()/find(), которые отдают данные для рендеринга страниц.
 */
class Secrets
{
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key === null) {
            $hex = App::config()['app_secret'] ?? '';
            if (strlen($hex) < 64) {
                throw new \RuntimeException(
                    'app_secret в config.php должен быть 32-байтным hex-значением (openssl rand -hex 32)'
                );
            }
            self::$key = sodium_hex2bin(substr($hex, 0, 64));
        }
        return self::$key;
    }

    /** Возвращает бинарную строку nonce.ciphertext, пригодную для BLOB-колонки. */
    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key());
        return $nonce . $cipher;
    }

    public static function decrypt(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($blob) <= $nonceLen) {
            throw new \RuntimeException('Повреждённое зашифрованное значение');
        }
        $nonce = substr($blob, 0, $nonceLen);
        $cipher = substr($blob, $nonceLen);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Не удалось расшифровать значение (неверный app_secret или данные повреждены)');
        }
        return $plain;
    }

    /**
     * Как decrypt(), но при неудаче (значение ещё не зашифровано — старая
     * plaintext-строка до миграции данных, см. bin/encrypt-exit-secrets.php)
     * возвращает исходную строку как есть, а не бросает исключение. Пустая
     * строка/null проходят насквозь без попытки расшифровки — это плейсхолдер,
     * не шифротекст (иначе decrypt() превратил бы '' в null).
     */
    public static function decryptOrPlain(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return self::decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
