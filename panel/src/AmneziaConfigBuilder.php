<?php

namespace App;

use App\Models\ExitServer;

/**
 * Генерирует wg-quick/awg-quick конфиги для туннелей входной VPS -> exit-сервер.
 * Table = off — чтобы awg-quick не трогал таблицы маршрутизации: весь выбор
 * "через какой интерфейс слать пакет" делает sing-box через bind_interface,
 * а не системная таблица маршрутов по умолчанию.
 */
class AmneziaConfigBuilder
{
    private const DEFAULT_AMNEZIA_PARAMS = [
        'Jc' => 4, 'Jmin' => 40, 'Jmax' => 70,
        'S1' => 0, 'S2' => 0,
        'H1' => 1, 'H2' => 2, 'H3' => 3, 'H4' => 4,
    ];

    /** @return array<string, string> interface_name => conf file content */
    public function buildAll(): array
    {
        $out = [];
        foreach (ExitServer::all() as $es) {
            // wireguard/vless/shadowsocks — нативные sing-box outbound'ы,
            // без внешнего awg-quick интерфейса (см. SingboxConfigBuilder).
            if (($es['protocol'] ?? 'amneziawg') !== 'amneziawg') {
                continue;
            }
            if ($es['status'] === 'disabled') {
                continue;
            }
            $out[$es['interface_name']] = $this->buildOne($es);
        }
        return $out;
    }

    private function buildOne(array $es): string
    {
        $amnezia = self::DEFAULT_AMNEZIA_PARAMS;
        if (!empty($es['amnezia_params'])) {
            $decoded = json_decode($es['amnezia_params'], true);
            if (is_array($decoded)) {
                $amnezia = array_merge($amnezia, $decoded);
            }
        }

        $lines = [];
        $lines[] = '# Сгенерировано панелью автоматически. Ручные правки будут перезаписаны.';
        $lines[] = '[Interface]';
        $lines[] = 'PrivateKey = ' . $es['wg_local_privkey'];
        $lines[] = 'Address = ' . $es['wg_local_address'];
        $lines[] = 'Table = off';
        foreach ($amnezia as $key => $value) {
            $lines[] = "$key = $value";
        }
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = ' . $es['wg_peer_pubkey'];
        if (!empty($es['wg_peer_psk'])) {
            $lines[] = 'PresharedKey = ' . $es['wg_peer_psk'];
        }
        $lines[] = 'Endpoint = ' . $es['endpoint_host'] . ':' . $es['endpoint_port'];
        $lines[] = 'AllowedIPs = 0.0.0.0/0, ::/0';
        $lines[] = 'PersistentKeepalive = 25';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
