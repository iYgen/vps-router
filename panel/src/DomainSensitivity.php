<?php

namespace App;

/**
 * Оценка «чувствительности» назначения маршрута к прогону через НЕДОВЕРЕННЫЙ
 * (чужой бесплатный) exit. Через такие ноды трафик виден их операторам, поэтому:
 *   - safe      — видео/стриминг/CDN, где нет персональных данных (YouTube и т.п.);
 *                 гонять через бесплатные ноды приемлемо;
 *   - sensitive — банки, платежи, госуслуги, почта, вход/аккаунты, крипто-биржи;
 *                 через чужие ноды НЕЛЬЗЯ (риск кражи данных/сессий);
 *   - neutral   — всё остальное (умеренный риск).
 *
 * dangerForRoute() добавляет «процент опасности» когда маршрут идёт через
 * бесплатный exit (запрос пользователя): базовая чувствительность + надбавка.
 */
class DomainSensitivity
{
    /** Видео/стриминг/CDN — без персональных данных, безопасно для чужих нод. */
    private const SAFE = [
        'youtube.com', 'youtu.be', 'googlevideo.com', 'ytimg.com', 'ggpht.com', 'yt3.ggpht.com',
        'netflix.com', 'nflxvideo.net', 'twitch.tv', 'ttvnw.net', 'vimeo.com', 'rutube.ru',
        'dailymotion.com', 'dzen.ru', 'vk.com/video', 'ok.ru/video', 'twitch.tv',
        'cloudfront.net', 'akamaized.net', 'fastly.net', 'cdn77.com', 'jsdelivr.net',
        'wikipedia.org', 'archive.org',
    ];

    /** geosite-категории, считающиеся безопасными для чужих нод. */
    private const SAFE_GEOSITE = ['youtube', 'google-play', 'netflix', 'twitch', 'category-video', 'category-media'];

    /** Банки/платежи/госуслуги/почта/вход/крипто — НЕЛЬЗЯ через чужие ноды. */
    private const SENSITIVE = [
        // Локальные банки/госуслуги/финансы
        'sberbank.ru', 'sber.ru', 'online.sberbank.ru', 'tinkoff.ru', 'tbank.ru', 'vtb.ru',
        'alfabank.ru', 'alfabank.com', 'gazprombank.ru', 'raiffeisen.ru', 'open.ru', 'psbank.ru',
        'gosuslugi.ru', 'nalog.ru', 'nalog.gov.ru', 'mos.ru', 'pfr.gov.ru', 'gov.ru',
        'mir.ru', 'nspk.ru', 'sbp.nspk.ru',
        // Глобальные платежи/финансы
        'paypal.com', 'stripe.com', 'wise.com', 'revolut.com', 'coinbase.com', 'binance.com',
        'bybit.com', 'kraken.com', 'blockchain.com',
        // Почта/вход/аккаунты
        'mail.ru', 'gmail.com', 'mail.google.com', 'accounts.google.com', 'login.microsoftonline.com',
        'outlook.com', 'proton.me', 'protonmail.com', 'id.apple.com', 'appleid.apple.com',
        'passport.yandex.ru', 'id.vk.com', 'auth', 'login', 'account',
    ];

    private const SENSITIVE_GEOSITE = ['category-finance', 'category-banking', 'category-gov'];

    /**
     * @return array{level:string,category:string,base_score:int}
     */
    public static function classify(string $value, string $type = 'domain_suffix'): array
    {
        $v = strtolower(trim($value));

        if ($type === 'geosite') {
            $g = preg_replace('/[^a-z0-9_-]/', '', $v);
            foreach (self::SENSITIVE_GEOSITE as $s) {
                if (str_contains($g, $s)) {
                    return ['level' => 'sensitive', 'category' => $g, 'base_score' => 70];
                }
            }
            foreach (self::SAFE_GEOSITE as $s) {
                if (str_contains($g, $s)) {
                    return ['level' => 'safe', 'category' => $g, 'base_score' => 5];
                }
            }
            return ['level' => 'neutral', 'category' => $g, 'base_score' => 25];
        }

        foreach (self::SENSITIVE as $s) {
            if ($v === $s || str_contains($v, $s)) {
                return ['level' => 'sensitive', 'category' => 'finance/auth', 'base_score' => 70];
            }
        }
        foreach (self::SAFE as $s) {
            if ($v === $s || str_ends_with($v, '.' . $s) || str_contains($v, $s)) {
                return ['level' => 'safe', 'category' => 'video/cdn', 'base_score' => 5];
            }
        }
        return ['level' => 'neutral', 'category' => 'general', 'base_score' => 25];
    }

    /**
     * Итоговая опасность маршрута с учётом того, идёт ли он через недоверенный
     * (бесплатный/сторонний) exit. Надбавка за чужую ноду — запрос пользователя.
     *
     * @return array{level:string,category:string,score:int,via_untrusted:bool}
     */
    public static function dangerForRoute(string $value, string $type, bool $viaUntrustedExit): array
    {
        $c = self::classify($value, $type);
        $score = $c['base_score'];
        if ($viaUntrustedExit) {
            // Через чужую ноду опаснее: sensitive становится критичным, neutral — заметным.
            $boost = match ($c['level']) {
                'sensitive' => 30,
                'neutral' => 25,
                default => 5,
            };
            $score = min(100, $score + $boost);
        }
        return [
            'level' => $c['level'],
            'category' => $c['category'],
            'score' => $score,
            'via_untrusted' => $viaUntrustedExit,
        ];
    }

    /**
     * Сводка по списку назначений (для предупреждений мастера/импорта):
     * сколько safe/neutral/sensitive.
     *
     * @param array<int,array{value:string,type:string}> $items
     * @return array{safe:int,neutral:int,sensitive:int,sensitive_examples:array<int,string>}
     */
    public static function summarize(array $items): array
    {
        $out = ['safe' => 0, 'neutral' => 0, 'sensitive' => 0, 'sensitive_examples' => []];
        foreach ($items as $it) {
            $c = self::classify($it['value'] ?? '', $it['type'] ?? 'domain_suffix');
            $out[$c['level']]++;
            if ($c['level'] === 'sensitive' && count($out['sensitive_examples']) < 8) {
                $out['sensitive_examples'][] = $it['value'];
            }
        }
        return $out;
    }
}
