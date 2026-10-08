<?php

namespace App\Singbox;

/**
 * Накапливает rule-set'ы для sing-box: удалённые geosite (с дедупликацией по
 * тегу) и локальные (source, пишутся на диск Applier'ом). Порядок регистрации
 * сохраняется в defs() — как раньше в монолите. Вынесено, чтобы разные модули
 * (маршруты, adblock, DNS) складывали rule-set'ы в один реестр.
 */
class RuleSetRegistry
{
    /** Официальные remote rule-set'ы sing-box (geosite) от SagerNet. */
    private const GEOSITE_RULESET_URL = 'https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-%s.srs';

    /** @var array<int,array> определения rule_set для route.rule_set */
    private array $defs = [];
    /** @var array<string,array> tag => содержимое локального rule-set файла */
    private array $localRuleSets = [];
    /** @var array<string,string> tag => исходное значение (дедуп geosite) */
    private array $geositeSeen = [];

    public function __construct(private string $rulesetDir)
    {
        $this->rulesetDir = rtrim($rulesetDir, '/');
    }

    /**
     * Регистрирует локальный rule-set (source) и возвращает его тег.
     * @param array $headless одно headless-правило (domain/ip_cidr/...)
     */
    public function addLocal(string $tag, array $headless): void
    {
        $this->localRuleSets[$tag] = ['version' => 1, 'rules' => [$headless]];
        $this->defs[] = [
            'tag' => $tag,
            'type' => 'local',
            'format' => 'source',
            'path' => $this->rulesetDir . "/{$tag}.json",
        ];
    }

    /**
     * Регистрирует remote geosite-категорию (если ещё не была) и возвращает её тег.
     * Дедупликация по тегу — повторный вызов с тем же значением ничего не добавляет.
     */
    public function addGeosite(string $value): string
    {
        $tag = 'geosite-' . preg_replace('/[^a-z0-9_-]/', '', strtolower($value));
        if (!isset($this->geositeSeen[$tag])) {
            $this->geositeSeen[$tag] = $value;
            $this->defs[] = [
                'tag' => $tag,
                'type' => 'remote',
                'format' => 'binary',
                'url' => sprintf(self::GEOSITE_RULESET_URL, strtolower($value)),
                'download_detour' => 'direct-rf',
            ];
        }
        return $tag;
    }

    /** @return array<int,array> определения rule_set (в порядке регистрации) */
    public function defs(): array
    {
        return $this->defs;
    }

    /** @return array<string,array> tag => содержимое локального rule-set файла */
    public function localRuleSets(): array
    {
        return $this->localRuleSets;
    }
}
