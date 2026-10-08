<?php

namespace App\Singbox;

/**
 * «Умный DNS»: sing-box перехватывает DNS-запросы клиентов (hijack-dns) и
 * домены, идущие через exit, резолвит DoH-запросом ЧЕРЕЗ сам exit — так
 * YouTube/Google отдают адреса для внешнего региона, а не заблокированные
 * локально. Остальное резолвится локально.
 *
 * Модуль самодостаточный: получает накопленные RouteBuilder'ом доменные
 * назначения exit-групп, регистрирует нужные локальные rule-set'ы в общий
 * RuleSetRegistry и возвращает готовую dns-секцию + сопутствующие правило
 * route (hijack) и default_domain_resolver. Вызывающий код решает, включать ли
 * его (см. SingboxConfigBuilder::smartDnsEnabled) и куда вставлять правило.
 */
class DnsBuilder
{
    /**
     * @param array $dnsExitDomains outboundTag => ['domain'=>[], 'domain_suffix'=>[], 'domain_keyword'=>[]]
     * @param array $dnsExitGeosite outboundTag => [geositeTag, ...]
     * @return array{dns:array,hijackRule:array,defaultDomainResolver:array}
     */
    public static function build(array $dnsExitDomains, array $dnsExitGeosite, RuleSetRegistry $registry): array
    {
        $dnsServers = [['type' => 'local', 'tag' => 'dns-local']];
        $dnsRules = [];
        $exitTags = array_values(array_unique(
            array_merge(array_keys($dnsExitDomains), array_keys($dnsExitGeosite))
        ));
        foreach ($exitTags as $outboundTag) {
            $safe = preg_replace('/[^a-z0-9_-]/i', '', $outboundTag);
            $serverTag = 'dns-exit-' . $safe;
            // DoH к 1.1.1.1 через сам exit: домен резолвится с внешней
            // точки — YouTube/Google отдают адреса для нужного региона.
            $dnsServers[] = [
                'type' => 'https',
                'tag' => $serverTag,
                'server' => '1.1.1.1',
                'detour' => $outboundTag,
            ];
            $refTags = [];
            if (!empty($dnsExitDomains[$outboundTag])) {
                $domTag = 'dns-dom-' . $safe;
                $headlessDom = [];
                foreach ($dnsExitDomains[$outboundTag] as $dk => $vals) {
                    if ($vals) {
                        $headlessDom[$dk] = array_values(array_unique($vals));
                    }
                }
                if ($headlessDom) {
                    $registry->addLocal($domTag, $headlessDom);
                    $refTags[] = $domTag;
                }
            }
            if (!empty($dnsExitGeosite[$outboundTag])) {
                $refTags = array_merge($refTags, array_values(array_unique($dnsExitGeosite[$outboundTag])));
            }
            if ($refTags) {
                $dnsRules[] = ['rule_set' => $refTags, 'server' => $serverTag];
            }
        }

        return [
            'dns' => [
                'servers' => $dnsServers,
                'rules' => $dnsRules,
                'final' => 'dns-local',
                'strategy' => 'prefer_ipv4',
            ],
            // Перехват DNS-запросов клиентов, чтобы их обрабатывал модуль dns
            // (иначе устройство за роутером резолвит через «грязный» локальный DNS).
            'hijackRule' => ['protocol' => 'dns', 'action' => 'hijack-dns'],
            // sing-box 1.12+ требует явный резолвер доменов для исходящих
            // подключений, когда есть dns-секция. Хосты exit-endpoint'ов
            // достижимы напрямую — резолвим их локальным DNS.
            'defaultDomainResolver' => ['server' => 'dns-local', 'strategy' => 'prefer_ipv4'],
        ];
    }
}
