<?php

namespace App;

/**
 * Готовые пресеты маршрутов для популярных сервисов — добавление в один клик.
 * Используют официальные geosite-категории SagerNet sing-geosite (их скачивает
 * sing-box как remote rule-set, см. SingboxConfigBuilder/RuleSetRegistry), поэтому
 * списки доменов всегда актуальны и ничего не нужно вести вручную.
 */
class RoutePresets
{
    /** id => [name, geosite[]] — geosite: имена категорий sing-geosite. */
    private const PRESETS = [
        'youtube'   => ['name' => 'YouTube',            'geosite' => ['youtube']],
        'google'    => ['name' => 'Google',             'geosite' => ['google']],
        'telegram'  => ['name' => 'Telegram',           'geosite' => ['telegram']],
        'discord'   => ['name' => 'Discord',            'geosite' => ['discord']],
        'twitter'   => ['name' => 'X (Twitter)',        'geosite' => ['twitter']],
        'instagram' => ['name' => 'Instagram',          'geosite' => ['instagram']],
        'meta'      => ['name' => 'Facebook / Meta',    'geosite' => ['facebook']],
        'netflix'   => ['name' => 'Netflix',            'geosite' => ['netflix']],
        'openai'    => ['name' => 'ChatGPT / OpenAI',   'geosite' => ['openai']],
        'tiktok'    => ['name' => 'TikTok',             'geosite' => ['tiktok']],
        'spotify'   => ['name' => 'Spotify',            'geosite' => ['spotify']],
        'github'    => ['name' => 'GitHub',             'geosite' => ['github']],
    ];

    /** @return array<int,array{id:string,name:string,geosite:string[]}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::PRESETS as $id => $p) {
            $out[] = ['id' => $id, 'name' => $p['name'], 'geosite' => $p['geosite']];
        }
        return $out;
    }

    /** @return array{id:string,name:string,geosite:string[]}|null */
    public static function get(string $id): ?array
    {
        if (!isset(self::PRESETS[$id])) {
            return null;
        }
        return ['id' => $id, 'name' => self::PRESETS[$id]['name'], 'geosite' => self::PRESETS[$id]['geosite']];
    }

    /** Префикс import_source_path для групп, созданных из пресета. */
    public const SOURCE_PREFIX = 'preset:';

    /**
     * Синхронизация групп-пресетов с актуальным каталогом (по аналогии с
     * синхронизацией импортированных списков). Для каждой группы с origin='preset'
     * приводит её geosite-правила к текущему определению пресета: добавляет новые
     * категории, убирает устаревшие, НЕ трогая привязку к exit/набору и возможные
     * ручные правила других типов. Пресет, удалённый из каталога, не трогаем.
     *
     * @return array{presets_updated:int,presets_unchanged:int,presets_missing:string[]}
     */
    public static function sync(?int $routerId = null, bool $includeNull = true): array
    {
        $updated = 0;
        $unchanged = 0;
        $missing = [];

        foreach (\App\Models\RuleGroup::all($routerId, $includeNull) as $g) {
            if (($g['origin'] ?? '') !== 'preset') {
                continue;
            }
            $src = (string) ($g['import_source_path'] ?? '');
            $pid = str_starts_with($src, self::SOURCE_PREFIX) ? substr($src, strlen(self::SOURCE_PREFIX)) : '';
            $preset = $pid !== '' ? self::get($pid) : null;
            if (!$preset) {
                $missing[] = $pid !== '' ? $pid : ('#' . $g['id']);
                continue;
            }

            $rules = \App\Models\Rule::forGroup((int) $g['id']);
            $currentGeo = [];
            $others = [];
            foreach ($rules as $r) {
                if (($r['type'] ?? '') === 'geosite') {
                    $currentGeo[] = $r['value'];
                } else {
                    $others[] = $r;
                }
            }
            $want = $preset['geosite'];
            $a = $currentGeo;
            sort($a);
            $b = $want;
            sort($b);
            if ($a === $b) {
                $unchanged++;
                continue;
            }
            // Пересобираем правила группы: сохраняем не-geosite, обновляем geosite под каталог.
            \App\Models\Rule::deleteAllForGroup((int) $g['id']);
            foreach ($others as $r) {
                \App\Models\Rule::create((int) $g['id'], $r['type'], $r['value']);
            }
            foreach ($want as $gs) {
                \App\Models\Rule::create((int) $g['id'], 'geosite', $gs);
            }
            $updated++;
        }

        return ['presets_updated' => $updated, 'presets_unchanged' => $unchanged, 'presets_missing' => $missing];
    }
}
