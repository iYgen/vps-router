<?php

namespace App\Singbox;

use App\Models\Rule;
use App\Models\ServerSet;

/**
 * «Без российского IP»: сайты, которые банят/ограничивают за российский адрес
 * (ChatGPT/OpenAI, Claude, Gemini…). Их трафик НИКОГДА не уходит напрямую с
 * РФ-сервера (direct-rf):
 *   - если домен явно попадает в группу с exit-сервером — правило ставится первым
 *     и ведёт прямо в этот exit (обычный путь, ничего не меняется);
 *   - иначе (нет группы, группа «напрямую», набор серверов без живого exit)
 *     соединение отклоняется (reject) — до первого правила, ведущего в direct-rf,
 *     и до финального direct-rf. Группы с exit выше по списку (например geosite)
 *     по-прежнему срабатывают раньше блокировки.
 * Лучше «сайт не открылся», чем бан аккаунта за вход с российского IP.
 */
class NoRfGuard
{
    public const DEFAULT_DOMAINS = [
        // OpenAI / ChatGPT / Sora
        'openai.com', 'chatgpt.com', 'oaistatic.com', 'oaiusercontent.com', 'sora.com',
        // Anthropic / Claude
        'anthropic.com', 'claude.ai', 'claude.com',
        // Google AI (сам google.com не трогаем)
        'gemini.google.com', 'aistudio.google.com', 'generativelanguage.googleapis.com', 'notebooklm.google.com',
        // Прочие AI-сервисы, ограничивающие РФ
        'perplexity.ai', 'x.ai', 'grok.com', 'copilot.microsoft.com', 'midjourney.com', 'poe.com', 'mistral.ai',
    ];

    /** Разбор списка из настроек: по одному домену в строке/через запятую, «#» — комментарий. */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,]+/', $text) as $line) {
            $d = strtolower(trim(preg_replace('/#.*$/', '', $line)));
            $d = preg_replace('#^[a-z]+://#', '', $d);
            $d = trim(explode('/', $d)[0], ". \t");
            if ($d !== '' && preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $d)) {
                $out[$d] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * @param array $routeRules  правила из RouteBuilder (порядок как в sing-box)
     * @param array $groups      RuleGroup::all — в том же порядке
     * @param array $exitServers exit-серверы, ключ = id
     * @param string[] $domains  домены-суффиксы «без РФ»
     */
    public static function apply(array $routeRules, array $groups, array $exitServers, array $domains): array
    {
        if (!$domains) {
            return $routeRules;
        }
        $toExit = [];   // outboundTag => [domain…]
        $blocked = [];
        foreach ($domains as $d) {
            $tag = self::staticExitFor($d, $groups, $exitServers);
            if ($tag !== null) {
                $toExit[$tag][] = $d;
            } else {
                $blocked[] = $d;
            }
        }

        $head = [];
        foreach ($toExit as $tag => $list) {
            $head[] = ['domain_suffix' => $list, 'outbound' => $tag];
        }
        $rules = array_merge($head, $routeRules);

        if ($blocked) {
            $reject = ['domain_suffix' => $blocked, 'action' => 'reject'];
            $pos = count($rules);
            foreach ($rules as $i => $r) {
                if (($r['outbound'] ?? null) === 'direct-rf') {
                    $pos = $i;
                    break;
                }
            }
            array_splice($rules, $pos, 0, [$reject]);
        }
        return $rules;
    }

    /**
     * Первая (по порядку) включённая группа, чьи доменные правила покрывают $domain.
     * Возвращает tag её exit-outbound'а или null (группа «напрямую»/без живого exit/нет группы).
     * geosite статически не разбираем — такие группы сработают раньше reject, если стоят выше.
     */
    private static function staticExitFor(string $domain, array $groups, array $exitServers): ?string
    {
        foreach ($groups as $g) {
            if (empty($g['enabled'])) {
                continue;
            }
            $hit = false;
            foreach (Rule::forGroup((int) $g['id']) as $r) {
                $v = strtolower((string) $r['value']);
                $hit = match ($r['type']) {
                    'domain_full' => $v === $domain,
                    'domain_suffix' => $domain === $v || str_ends_with($domain, '.' . $v),
                    'domain_keyword' => $v !== '' && str_contains($domain, $v),
                    default => false,
                };
                if ($hit) {
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $exitId = null;
            if (!empty($g['server_set_id'])) {
                $exitId = ServerSet::resolveExitServerId((int) $g['server_set_id']);
            } elseif (!empty($g['exit_server_id'])) {
                $exitId = (int) $g['exit_server_id'];
            }
            return $exitId && isset($exitServers[$exitId]) ? ExitOutboundBuilder::tag($exitId) : null;
        }
        return null;
    }
}
