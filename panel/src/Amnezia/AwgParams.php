<?php

namespace App\Amnezia;

/**
 * Валидация параметров обфускации AmneziaWG (Jc/Jmin/Jmax, S1/S2, H1-H4).
 * Кривые параметры не дают явной ошибки при поднятии интерфейса, но туннель
 * либо не встаёт, либо становится легко детектируемым — ловим заранее (перед
 * Apply). Правила соответствуют генератору DeviceInbounds::randomAmneziaParams.
 */
class AwgParams
{
    /**
     * @param array $p параметры (Jc, Jmin, Jmax, S1, S2, H1..H4)
     * @return string[] список проблем (пусто = ок)
     */
    public static function validate(array $p): array
    {
        $errs = [];

        foreach (['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'H1', 'H2', 'H3', 'H4'] as $k) {
            if (isset($p[$k]) && !is_numeric($p[$k])) {
                $errs[] = "параметр $k должен быть числом";
            }
        }

        $jc = (int) ($p['Jc'] ?? 0);
        $jmin = (int) ($p['Jmin'] ?? 0);
        $jmax = (int) ($p['Jmax'] ?? 0);
        if ($jc < 0 || $jmin < 0 || $jmax < 0) {
            $errs[] = 'Jc/Jmin/Jmax не могут быть отрицательными';
        }
        if ($jmax < $jmin) {
            $errs[] = "Jmax ($jmax) меньше Jmin ($jmin)";
        }

        $s1 = (int) ($p['S1'] ?? 0);
        $s2 = (int) ($p['S2'] ?? 0);
        if ($s1 < 0 || $s2 < 0) {
            $errs[] = 'S1/S2 не могут быть отрицательными';
        }
        // Известное ограничение Amnezia: S2 не должно равняться S1+56 (иначе handshake ломается).
        if ($s1 + 56 === $s2) {
            $errs[] = 'S2 = S1 + 56 — недопустимая комбинация (handshake не пройдёт)';
        }

        // H1..H4 должны быть заданы и различны (иначе типы сообщений схлопываются).
        $h = [(int) ($p['H1'] ?? 0), (int) ($p['H2'] ?? 0), (int) ($p['H3'] ?? 0), (int) ($p['H4'] ?? 0)];
        if (count(array_unique($h)) !== 4) {
            $errs[] = 'H1..H4 должны быть различны';
        }

        return $errs;
    }
}
