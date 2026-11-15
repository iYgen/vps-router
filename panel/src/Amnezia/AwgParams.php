<?php

namespace App\Amnezia;

/**
 * Валидация параметров обфускации AmneziaWG: 1.x (Jc/Jmin/Jmax, S1/S2, H1-H4) и 3.1
 * (S3/S4, H-диапазоны, I1-I5, HeaderProtectionKey, ContentPaddingAddition, таймеры).
 * Кривые параметры не дают явной ошибки при поднятии интерфейса, но туннель
 * либо не встаёт, либо становится легко детектируемым — ловим заранее (перед
 * Apply). Правила соответствуют генератору AwgProfile::random31 и проверкам amneziawg-go.
 */
class AwgParams
{
    /** Ключи, которые можно писать в [Interface] (в таком написании их ждёт awg-quick). */
    public const KEYS = [
        'Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'S3', 'S4', 'H1', 'H2', 'H3', 'H4',
        'I1', 'I2', 'I3', 'I4', 'I5', 'HeaderProtectionKey', 'ContentPaddingAddition',
        'RekeyAfterTime', 'RekeyTimeout', 'RejectAfterTime', 'KeepaliveTimeout', 'MaxHandshakeAttempts',
        'RandomTrailers', 'DisableCookies',
    ];

    private const RANGE = '/^\d+(-\d+)?$/';

    /**
     * @param array $p параметры
     * @return string[] список проблем (пусто = ок)
     */
    public static function validate(array $p): array
    {
        $errs = [];

        foreach (array_keys($p) as $k) {
            if (!in_array($k, self::KEYS, true)) {
                $errs[] = "неизвестный параметр $k";
            }
        }
        foreach (['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'S3', 'S4'] as $k) {
            if (isset($p[$k]) && !preg_match('/^\d+$/', (string) $p[$k])) {
                $errs[] = "параметр $k должен быть целым числом ≥ 0";
            }
        }
        foreach (['H1', 'H2', 'H3', 'H4', 'ContentPaddingAddition', 'RekeyAfterTime', 'RekeyTimeout', 'RejectAfterTime', 'KeepaliveTimeout', 'MaxHandshakeAttempts'] as $k) {
            if (isset($p[$k]) && !preg_match(self::RANGE, (string) $p[$k])) {
                $errs[] = "параметр $k должен быть числом или диапазоном «мин-макс»";
            }
        }

        $jmin = (int) ($p['Jmin'] ?? 0);
        $jmax = (int) ($p['Jmax'] ?? 0);
        if ($jmax < $jmin) {
            $errs[] = "Jmax ($jmax) меньше Jmin ($jmin)";
        }
        if ((int) ($p['Jc'] ?? 0) > 122) {
            $errs[] = 'Jc больше 122 — amneziawg-go не отправит такую серию';
        }

        $s1 = (int) ($p['S1'] ?? 0);
        $s2 = (int) ($p['S2'] ?? 0);
        // Размеры init (148+S1) и response (92+S2) не должны совпадать, иначе handshake ломается.
        if ($s1 + 56 === $s2) {
            $errs[] = 'S2 = S1 + 56 — недопустимая комбинация (handshake не пройдёт)';
        }

        // H1..H4 должны быть различны и не пересекаться (иначе типы сообщений схлопываются).
        // Не задан ни один — AmneziaConfigBuilder подставит 1..4.
        $ranges = [];
        if (!isset($p['H1']) && !isset($p['H2']) && !isset($p['H3']) && !isset($p['H4'])) {
            $p += ['H1' => 1, 'H2' => 2, 'H3' => 3, 'H4' => 4];
        }
        foreach (['H1', 'H2', 'H3', 'H4'] as $k) {
            $v = (string) ($p[$k] ?? '0');
            [$lo, $hi] = array_map('intval', explode('-', $v) + [1 => $v]);
            $ranges[] = [$lo, $hi];
        }
        for ($i = 0; $i < 4; $i++) {
            for ($j = $i + 1; $j < 4; $j++) {
                if ($ranges[$i][0] <= $ranges[$j][1] && $ranges[$j][0] <= $ranges[$i][1]) {
                    $errs[] = 'H1..H4 должны быть различны и не пересекаться';
                    break 2;
                }
            }
        }

        foreach (['I1', 'I2', 'I3', 'I4', 'I5'] as $k) {
            if (isset($p[$k]) && $p[$k] !== '' && !preg_match('/^(<(b 0x[0-9a-fA-F]+|r \d+|rc \d+|rd \d+|t|d|ds|dz \d+)>)+$/', (string) $p[$k])) {
                $errs[] = "$k: неверный формат (ожидаются теги вида <b 0x..>, <r N>, <t>)";
            }
        }

        if (!empty($p['HeaderProtectionKey'])) {
            $raw = base64_decode((string) $p['HeaderProtectionKey'], true);
            if ($raw === false || strlen($raw) !== 32) {
                $errs[] = 'HeaderProtectionKey должен быть 32 байтами в base64 (как ключи WireGuard)';
            }
            foreach (['S1', 'S2', 'S3', 'S4'] as $k) {
                if ((int) ($p[$k] ?? 0) < 12) {
                    $errs[] = "$k меньше 12 — с HeaderProtectionKey AmneziaWG 3.1 так не работает";
                }
            }
        }

        foreach (['RandomTrailers', 'DisableCookies'] as $k) {
            if (isset($p[$k]) && !in_array(strtolower((string) $p[$k]), ['true', 'false', '1', '0', 'on', 'off', 'yes', 'no'], true)) {
                $errs[] = "$k должен быть true или false";
            }
        }

        return $errs;
    }
}
