<?php

namespace App\Singbox;

use App\DeviceInbounds;
use App\Models\Client;

/**
 * Собирает inbound'ы подключения устройств: VLESS+Reality (если включён) и
 * остальные протоколы (Shadowsocks/Trojan/Hysteria2/WireGuard/AmneziaWG) через
 * App\DeviceInbounds. Возвращает inbounds + endpoints устройств (последние
 * вызывающий код домержит к endpoint'ам exit-серверов).
 */
class InboundsBuilder
{
    /**
     * @param array $reality ['enabled'=>bool,'listen_ip'=>,'listen_port'=>,'server_name'=>,
     *                         'private_key'=>,'short_id'=>,'handshake'=>array]
     * @return array{inbounds:array,endpoints:array}
     */
    public static function build(?int $scopeId, bool $includeNull, array $reality): array
    {
        Client::backfillCredentials();
        $activeClients = Client::active($scopeId, $includeNull);

        $inbounds = [];
        if (!empty($reality['enabled'])) {
            $users = [];
            foreach ($activeClients as $client) {
                // name = c<id>: sing-box пишет его в журнал, по нему TrafficCollector узнаёт устройство.
                $users[] = ['uuid' => $client['uuid'], 'flow' => 'xtls-rprx-vision', 'name' => 'c' . $client['id']];
            }
            $inbounds[] = [
                'type' => 'vless',
                'tag' => 'reality-in',
                'listen' => $reality['listen_ip'],
                'listen_port' => $reality['listen_port'],
                'users' => $users,
                'tls' => [
                    'enabled' => true,
                    'server_name' => $reality['server_name'],
                    'reality' => [
                        'enabled' => true,
                        'handshake' => $reality['handshake'],
                        'private_key' => $reality['private_key'],
                        'short_id' => [$reality['short_id']],
                    ],
                ],
            ];
        }

        // Остальные протоколы входа устройств (Shadowsocks/Trojan/Hysteria2/
        // WireGuard/AmneziaWG) — см. App\DeviceInbounds.
        $extra = DeviceInbounds::singboxInbounds($activeClients);

        return [
            'inbounds' => array_merge($inbounds, $extra['inbounds']),
            'endpoints' => $extra['endpoints'],
        ];
    }
}
