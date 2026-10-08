<?php

namespace App\Singbox;

use App\Models\Rule;
use App\Models\ServerSet;

/**
 * Превращает включённые группы маршрутов (RuleGroup + их Rule) в правила
 * route.rules sing-box, попутно регистрируя нужные rule-set'ы в RuleSetRegistry
 * и накапливая доменные назначения exit-групп для «умного DNS» (см. DnsBuilder).
 */
class RouteBuilder
{
    /**
     * @param array<int,array> $groups     включённые/все группы (RuleGroup::all)
     * @param array<int,array> $exitServers exit-серверы, ключ = id
     * @return array{rules:array,dnsExitDomains:array,dnsExitGeosite:array}
     */
    public static function build(array $groups, array $exitServers, RuleSetRegistry $registry): array
    {
        $routeRules = [];
        // По каждому exit-outbound копим доменные правила и geosite-теги, чтобы
        // резолвить эти домены через сам exit (DoH). См. DnsBuilder.
        $dnsExitDomains = [];  // outboundTag => ['domain'=>[], 'domain_suffix'=>[], 'domain_keyword'=>[]]
        $dnsExitGeosite = [];  // outboundTag => [geositeTag, ...]

        foreach ($groups as $group) {
            if (!$group['enabled']) {
                continue;
            }
            $rules = Rule::forGroup((int) $group['id']);
            if (empty($rules)) {
                continue;
            }

            $resolvedExitId = null;
            if (!empty($group['server_set_id'])) {
                // Route привязан к Server Set — разрешаем набор в конкретный
                // exit по его стратегии (manual/priority/failover).
                $resolvedExitId = ServerSet::resolveExitServerId((int) $group['server_set_id']);
            } elseif (!empty($group['exit_server_id'])) {
                $resolvedExitId = (int) $group['exit_server_id'];
            }

            $outboundTag = $resolvedExitId && isset($exitServers[$resolvedExitId])
                ? ExitOutboundBuilder::tag($resolvedExitId)
                : 'direct-rf';

            $localTag = 'group-' . $group['id'];
            $headless = ['domain' => [], 'domain_suffix' => [], 'domain_keyword' => [], 'ip_cidr' => []];
            $geositeTagsForGroup = [];

            foreach ($rules as $rule) {
                switch ($rule['type']) {
                    case 'domain_full':
                        $headless['domain'][] = $rule['value'];
                        break;
                    case 'domain_suffix':
                        $headless['domain_suffix'][] = $rule['value'];
                        break;
                    case 'domain_keyword':
                        $headless['domain_keyword'][] = $rule['value'];
                        break;
                    case 'ip_cidr':
                        $headless['ip_cidr'][] = $rule['value'];
                        break;
                    case 'geosite':
                        $geositeTagsForGroup[] = $registry->addGeosite($rule['value']);
                        break;
                }
            }

            // Копим доменные назначения exit-групп для «умного DNS» (резолв через exit).
            if ($outboundTag !== 'direct-rf') {
                foreach (['domain', 'domain_suffix', 'domain_keyword'] as $dk) {
                    if (!empty($headless[$dk])) {
                        $dnsExitDomains[$outboundTag][$dk]
                            = array_merge($dnsExitDomains[$outboundTag][$dk] ?? [], $headless[$dk]);
                    }
                }
                if ($geositeTagsForGroup) {
                    $dnsExitGeosite[$outboundTag]
                        = array_merge($dnsExitGeosite[$outboundTag] ?? [], $geositeTagsForGroup);
                }
            }

            $headless = array_filter($headless, fn($v) => !empty($v));
            $refTags = [];

            if (!empty($headless)) {
                $registry->addLocal($localTag, $headless);
                $refTags[] = $localTag;
            }
            $refTags = array_merge($refTags, $geositeTagsForGroup);

            if (empty($refTags)) {
                continue;
            }

            $routeRules[] = [
                'rule_set' => $refTags,
                'outbound' => $outboundTag,
            ];
        }

        return [
            'rules' => $routeRules,
            'dnsExitDomains' => $dnsExitDomains,
            'dnsExitGeosite' => $dnsExitGeosite,
        ];
    }
}
