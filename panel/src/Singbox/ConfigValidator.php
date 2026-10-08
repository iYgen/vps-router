<?php

namespace App\Singbox;

/**
 * Пред-валидация СГЕНЕРИРОВАННОГО конфига sing-box до применения. Ловит класс
 * ошибок, из-за которых sing-box падает при старте сырым FATAL (висячие ссылки:
 * rule_set без файла/определения, outbound/DNS-сервер, которого нет). Цель —
 * не перезапускать рабочий sing-box в заведомо битую конфигурацию, а показать
 * понятное сообщение заранее (Preview) или прервать Apply чисто.
 *
 * Проверяет только внутреннюю целостность конфига, НЕ сетевую доступность и НЕ
 * структуру БД (это делает SingboxConfigBuilder::validate()).
 */
class ConfigValidator
{
    /**
     * @param array $config   собранный конфиг (['route'=>..., 'outbounds'=>..., ...])
     * @param array $ruleSets tag => содержимое локального rule-set (то, что запишет Applier)
     * @return string[] список человекочитаемых ошибок (пусто = всё в порядке)
     */
    public static function validate(array $config, array $ruleSets): array
    {
        $errors = [];
        $route = $config['route'] ?? [];

        // Множество валидных outbound-целей: обычные outbound'ы + endpoint'ы (WireGuard).
        $outboundTags = array_merge(
            array_column($config['outbounds'] ?? [], 'tag'),
            array_column($config['endpoints'] ?? [], 'tag')
        );

        // Определённые rule-set'ы + проверка, что local реально будет записан.
        $ruleSetDefs = $route['rule_set'] ?? [];
        $definedRuleSetTags = array_column($ruleSetDefs, 'tag');
        foreach ($ruleSetDefs as $rs) {
            $tag = $rs['tag'] ?? '';
            if (($rs['type'] ?? '') === 'local' && $tag !== '' && !array_key_exists($tag, $ruleSets)) {
                $errors[] = "rule-set «{$tag}» типа local объявлен, но его содержимое не будет записано на диск.";
            }
        }

        // Правила маршрутизации: ссылки на rule_set и outbound должны существовать.
        foreach (($route['rules'] ?? []) as $i => $rule) {
            foreach ((array) ($rule['rule_set'] ?? []) as $ref) {
                if (!in_array($ref, $definedRuleSetTags, true)) {
                    $errors[] = "route.rules[$i] ссылается на неизвестный rule_set «{$ref}».";
                }
            }
            if (isset($rule['outbound']) && !in_array($rule['outbound'], $outboundTags, true)) {
                $errors[] = "route.rules[$i] ссылается на неизвестный outbound «{$rule['outbound']}».";
            }
        }

        // final маршрута обязан указывать на существующий outbound.
        if (isset($route['final']) && !in_array($route['final'], $outboundTags, true)) {
            $errors[] = "route.final = «{$route['final']}» — такого outbound нет.";
        }

        // DNS-секция (если есть): сервера, правила, детуры, резолвер по умолчанию.
        if (!empty($config['dns'])) {
            $dns = $config['dns'];
            $serverTags = array_column($dns['servers'] ?? [], 'tag');

            foreach (($dns['servers'] ?? []) as $s) {
                $detour = $s['detour'] ?? null;
                if ($detour !== null && !in_array($detour, $outboundTags, true)) {
                    $errors[] = "dns-сервер «{$s['tag']}» использует detour «{$detour}», которого нет среди outbound-целей.";
                }
            }
            foreach (($dns['rules'] ?? []) as $i => $r) {
                if (isset($r['server']) && !in_array($r['server'], $serverTags, true)) {
                    $errors[] = "dns.rules[$i] ссылается на неизвестный dns-сервер «{$r['server']}».";
                }
            }
            if (isset($dns['final']) && !in_array($dns['final'], $serverTags, true)) {
                $errors[] = "dns.final = «{$dns['final']}» — такого dns-сервера нет.";
            }
            $ddr = $route['default_domain_resolver']['server'] ?? null;
            if ($ddr !== null && !in_array($ddr, $serverTags, true)) {
                $errors[] = "route.default_domain_resolver указывает на неизвестный dns-сервер «{$ddr}».";
            }
        }

        return $errors;
    }

    /**
     * Предупреждения о несовместимости конфига с установленной версией sing-box.
     * Наш конфиг пересобирается под текущий билдер, но после обновления/отката
     * движка возможности могут требовать более новой версии — предупреждаем до
     * применения, чтобы не ловить молчаливый FATAL. $version = null → пропускаем.
     *
     * @return string[]
     */
    public static function versionWarnings(array $config, ?string $version): array
    {
        if ($version === null) {
            return [];
        }
        // возможность => минимальная версия sing-box.
        $need = [];
        if (!empty($config['endpoints'])) {
            $need['WireGuard endpoints'] = '1.11.0';
        }
        if (!empty($config['dns'])) {
            $need['DNS-секция (hijack-dns / DoH через exit)'] = '1.12.0';
        }
        if (isset($config['route']['default_domain_resolver'])) {
            $need['route.default_domain_resolver'] = '1.12.0';
        }

        $warnings = [];
        foreach ($need as $feature => $minVersion) {
            if (version_compare($version, $minVersion, '<')) {
                $warnings[] = "Установлен sing-box $version, но конфиг использует «{$feature}» (нужен ≥ $minVersion). Обновите sing-box, иначе применение упадёт.";
            }
        }
        return $warnings;
    }
}
