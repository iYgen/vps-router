<?php

namespace App\Modules;

/**
 * Встроенные модули, поставляемые с панелью. ModuleManager::ensureBuiltins()
 * создаёт из них архивы (.vmod) в module-archives/ и при первом запуске
 * ставит+активирует, чтобы текущие возможности не пропали.
 *
 * Роутер-модули декларативны: `provides.exports[].format` маппится на встроенные
 * экспортеры (App\KeeneticExport / HydraRouteExport / RouterExport), которые
 * отдаёт api/keenetic-export.php. Добавить новый роутер = добавить модуль
 * (встроенный здесь или сторонний .vmod), код ядра не трогая.
 */
class ModuleCatalog
{
    /** @return array<int,array> манифесты встроенных модулей */
    public static function builtins(): array
    {
        return [
            [
                'id' => 'router-keenetic',
                'name' => 'Keenetic',
                'version' => '1.0.0',
                'type' => 'router',
                'description' => 'Keenetic: dedicated routing tab (policies/devices) + route export (.bat, .txt list, HydraRoute). Turning it off removes the Keenetic tab and export.',
                'provides' => [
                    'features' => ['keenetic-page'],
                    'exports' => [
                        ['format' => 'bat', 'label' => 'Export: Keenetic — .bat (route ADD)'],
                        ['format' => 'txt', 'label' => 'Export: Keenetic — list (.txt)'],
                        ['format' => 'hydra', 'label' => 'Export: Keenetic — HydraRoute (ZIP)'],
                    ],
                ],
            ],
            [
                'id' => 'router-mikrotik',
                'name' => 'MikroTik',
                'version' => '1.0.0',
                'type' => 'router',
                'description' => 'Route export for MikroTik RouterOS (/ip route add … gateway=<iface>).',
                'provides' => ['exports' => [
                    ['format' => 'mikrotik', 'label' => 'Export: MikroTik (RouterOS .rsc)'],
                ]],
            ],
            [
                'id' => 'router-openwrt',
                'name' => 'OpenWrt',
                'version' => '1.0.0',
                'type' => 'router',
                'description' => 'Route export for OpenWrt (sh script: ip route add … dev <iface>).',
                'provides' => ['exports' => [
                    ['format' => 'openwrt', 'label' => 'Export: OpenWrt (ip route .sh)'],
                ]],
            ],

            // Optional core features as modules (active by default; can be turned off).
            [
                'id' => 'feature-update-check',
                'name' => 'Update check',
                'version' => '1.0.0',
                'type' => 'feature',
                'description' => 'Compares the panel version against the repository and shows an "update available" banner. Without this module the "Updates" section and banner are hidden.',
                'provides' => ['features' => ['update-check']],
            ],
            [
                'id' => 'feature-route-presets',
                'name' => 'Route presets',
                'version' => '1.0.0',
                'type' => 'feature',
                'description' => 'Ready-made geosite sets (YouTube, Google, etc.) and import of external lists on the "Routes" page.',
                'provides' => ['features' => ['route-presets']],
            ],
            [
                'id' => 'feature-free-exits',
                'name' => 'Free public nodes',
                'version' => '1.0.0',
                'type' => 'feature',
                'description' => 'Import of free (untrusted) public exit nodes and grouping them into a failover pool. Without this module the button is hidden.',
                'provides' => ['features' => ['free-exits']],
            ],
            [
                'id' => 'feature-traffic',
                'name' => 'Extended per-server stats',
                'version' => '1.0.0',
                'type' => 'feature',
                'description' => '"Traffic" tab: per-server traffic growth charts (in/out) by day, breakdown by resource, selectable period. Without this module the tab is hidden.',
                'provides' => ['features' => ['traffic-stats']],
            ],
            [
                'id' => 'feature-probe-intel',
                'name' => 'Active protection',
                'version' => '1.0.0',
                'type' => 'feature',
                'description' => 'Tracks exit-node reachability over time from domestic and foreign vantage points (early detection of availability loss by the divergence) plus optional collection of inbound probing (who knocks on the Reality port). The panel gathers data itself over outbound SSH — no agent on the exit. Without this module the "Active protection" tab is hidden.',
                'provides' => ['features' => ['probe-intel']],
            ],
            [
                'id' => 'feature-billing',
                'name' => 'Billing',
                'version' => '1.0.0',
                'type' => 'feature',
                'description' => 'Plans, subscribers and subscriptions with automatic device shut-off on expiry. Manual payments and online payment via gateways (YooKassa / CryptoCloud) confirmed by webhook. Renewal reminders by email. Without this module the "Billing" section is hidden.',
                'provides' => ['features' => ['billing']],
            ],
            [
                'id' => 'feature-site-builder',
                'name' => 'Site & cabinet builder',
                'version' => '1.0.0',
                'type' => 'feature',
                'default_active' => false, // paid module: installed, but enabled manually
                'description' => 'Visual builder for the public landing site and the personal cabinet: 5 structural templates, palette/typography, hero, sections, plan cards (on real billing data), preview and publish. Without this module the "Site & cabinet" section and the public site are unavailable.',
                'provides' => ['features' => ['site-builder']],
            ],
            [
                'id' => 'feature-tg-proxy',
                'name' => 'Telegram proxy',
                'version' => '1.0.0',
                'type' => 'feature',
                'description' => 'A dedicated proxy for the Telegram messenger on a chosen node: MTProto (mtg, with FakeTLS) and/or SOCKS5 (via sing-box). Managed from the server inspector: enable/disable, port, secret regeneration, ready-made tg:// link and QR. Without this module the "Telegram proxy" tab is hidden.',
                'provides' => ['features' => ['tg-proxy']],
            ],
        ];
    }
}
