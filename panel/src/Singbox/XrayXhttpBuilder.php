<?php

namespace App\Singbox;

use App\DeviceInbounds;

/**
 * Конфиг Xray для входа VLESS+XHTTP (см. DeviceInbounds::xhttp*).
 *
 * Xray здесь — только «приёмник» транспорта, которого нет в sing-box: VLESS+XHTTP
 * на 127.0.0.1 (TLS и путь — у nginx сайта). Каждый клиент по email = cN
 * уходит отдельным SOCKS-outbound'ом в sing-box (inbound xhttp-bridge) с
 * логином cN — вся маршрутизация, exit'ы и учёт остаются в sing-box.
 * Неизвестный пользователь → blackhole (первый outbound = по умолчанию).
 */
class XrayXhttpBuilder
{
    /**
     * @param array<int,array> $clients активные устройства (uuid, id)
     * @return array|null null — XHTTP выключен/не настроен или нет устройств
     */
    public static function build(array $clients): ?array
    {
        if (!DeviceInbounds::xhttpReady() || !$clients) {
            return null;
        }
        $xrayClients = [];
        $outbounds = [['tag' => 'block', 'protocol' => 'blackhole']];
        $rules = [];
        foreach ($clients as $c) {
            $name = 'c' . $c['id'];
            $xrayClients[] = ['id' => $c['uuid'], 'email' => $name];
            $outbounds[] = [
                'tag' => 'to-' . $name,
                'protocol' => 'socks',
                'settings' => [
                    'servers' => [[
                        'address' => '127.0.0.1',
                        'port' => DeviceInbounds::XHTTP_BRIDGE_PORT,
                        'users' => [['user' => $name, 'pass' => DeviceInbounds::xhttpBridgePassword($c)]],
                    ]],
                ],
            ];
            $rules[] = ['type' => 'field', 'user' => [$name], 'outboundTag' => 'to-' . $name];
        }

        return [
            'log' => ['loglevel' => 'warning'],
            'inbounds' => [[
                'tag' => 'xhttp-in',
                'listen' => '127.0.0.1',
                'port' => DeviceInbounds::XHTTP_LOCAL_PORT,
                'protocol' => 'vless',
                'settings' => ['clients' => $xrayClients, 'decryption' => 'none'],
                'streamSettings' => [
                    'network' => 'xhttp',
                    'xhttpSettings' => ['path' => DeviceInbounds::xhttpPath(), 'mode' => 'auto'],
                ],
            ]],
            'outbounds' => $outbounds,
            'routing' => ['domainStrategy' => 'AsIs', 'rules' => $rules],
        ];
    }
}
