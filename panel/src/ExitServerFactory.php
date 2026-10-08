<?php

namespace App;

use App\Models\ExitServer;

/**
 * Собирает exit_servers-строку из тела запроса api/connections.php под
 * конкретный протокол (amneziawg/wireguard/vless/shadowsocks). Вынесено из
 * API-скрипта отдельным классом, чтобы валидация полей на протокол была
 * unit-тестируемой без HTTP-слоя.
 *
 * Ключевой материал (WG-ключи, Reality keypair) НЕ обязателен при создании
 * связи — если оставлен пустым, соединение создаётся как "черновик", а
 * реальные значения появляются после нажатия «Установить и настроить»
 * (Provisioner сам генерирует пары ключей и настраивает обе стороны).
 * Обязательны только структурные поля, которые нельзя выбрать автоматически
 * (например тип transport для VLESS — от него зависит форма остальных полей).
 */
class ExitServerFactory
{
    public static function fromRequest(string $type, array $body, array $target): int
    {
        switch ($type) {
            case 'amneziawg':
                return self::amneziawg($body, $target);
            case 'wireguard':
                return self::wireguard($body, $target);
            case 'vless':
                return self::vless($body, $target);
            case 'shadowsocks':
                return self::shadowsocks($body, $target);
            case 'hysteria2':
                return self::hysteria2($body, $target);
            case 'tuic':
                return self::tuic($body, $target);
            case 'trojan':
                return self::trojan($body, $target);
            default:
                throw new \InvalidArgumentException('Неизвестный протокол: ' . $type);
        }
    }

    private static function amneziawg(array $body, array $target): int
    {
        $wg = $body['wireguard'] ?? [];

        return ExitServer::create([
            'name' => $body['label'] ?? $target['name'],
            'endpoint_host' => $wg['endpoint_host'] ?? $target['host'],
            'endpoint_port' => (int) ($wg['endpoint_port'] ?? 51820),
            'wg_peer_pubkey' => $wg['wg_peer_pubkey'] ?? '',
            'wg_peer_psk' => $wg['wg_peer_psk'] ?? null,
            'wg_local_privkey' => $wg['wg_local_privkey'] ?? '',
            'wg_local_address' => $wg['wg_local_address'] ?? '',
            'interface_name' => $wg['interface_name'] ?? null,
            'amnezia_params' => $wg['amnezia_params'] ?? null,
            'status' => $wg['status'] ?? 'active',
            'protocol' => 'amneziawg',
        ]);
    }

    /** Нативный sing-box wireguard outbound — без внешнего интерфейса/awg-quick. */
    private static function wireguard(array $body, array $target): int
    {
        $wg = $body['wireguard'] ?? [];

        return ExitServer::create([
            'name' => $body['label'] ?? $target['name'],
            'endpoint_host' => $wg['endpoint_host'] ?? $target['host'],
            'endpoint_port' => (int) ($wg['endpoint_port'] ?? 51820),
            'status' => $wg['status'] ?? 'active',
            'protocol' => 'wireguard',
            'protocol_params' => [
                'peer_pubkey' => $wg['wg_peer_pubkey'] ?? '',
                'local_privkey' => $wg['wg_local_privkey'] ?? '',
                'local_address' => $wg['wg_local_address'] ?? '',
                'mtu' => (int) ($wg['mtu'] ?? 1420),
            ],
        ]);
    }

    private static function vless(array $body, array $target): int
    {
        $v = $body['vless'] ?? [];
        $transport = $v['transport'] ?? 'reality';
        if (!in_array($transport, ['reality', 'ws', 'grpc'], true)) {
            throw new \InvalidArgumentException('Неизвестный transport для VLESS: ' . $transport);
        }

        // uuid — обычный идентификатор, не требует согласования с сервером,
        // генерируем сразу, если не передан явно.
        $params = ['uuid' => $v['uuid'] ?: self::uuidV4(), 'flow' => $v['flow'] ?? null, 'transport' => $transport];

        switch ($transport) {
            case 'reality':
                // public_key/short_id — серверный keypair, появится после
                // Provisioner::provisionConnection() (или если пользователь
                // подключает уже существующий внешний сервер — вписывается вручную).
                $params['reality'] = [
                    'public_key' => $v['reality_public_key'] ?? '',
                    'short_id' => $v['reality_short_id'] ?? '',
                    'server_name' => $v['reality_server_name'] ?? '',
                ];
                break;
            case 'ws':
                $params['ws'] = ['path' => $v['ws_path'] ?? '/'];
                break;
            case 'grpc':
                $params['grpc'] = ['service_name' => $v['grpc_service_name'] ?? ''];
                break;
        }

        return ExitServer::create([
            'name' => $body['label'] ?? $target['name'],
            'endpoint_host' => $v['endpoint_host'] ?? $target['host'],
            'endpoint_port' => (int) ($v['endpoint_port'] ?? 443),
            'status' => $v['status'] ?? 'active',
            'protocol' => 'vless',
            'protocol_params' => $params,
        ]);
    }

    private static function shadowsocks(array $body, array $target): int
    {
        $ss = $body['shadowsocks'] ?? [];

        return ExitServer::create([
            'name' => $body['label'] ?? $target['name'],
            'endpoint_host' => $ss['endpoint_host'] ?? $target['host'],
            'endpoint_port' => (int) ($ss['endpoint_port'] ?? 8388),
            'status' => $ss['status'] ?? 'active',
            'protocol' => 'shadowsocks',
            'protocol_params' => [
                'method' => $ss['method'] ?: 'chacha20-ietf-poly1305',
                'password' => $ss['password'] ?: bin2hex(random_bytes(16)),
            ],
        ]);
    }

    /**
     * hysteria2/tuic/trojan требуют РЕАЛЬНОГО TLS-сертификата на реальный
     * домен (в отличие от VLESS+Reality, который чужой сертификат не
     * предъявляет вообще — заимствует TLS-отпечаток сайта-донора без
     * необходимости иметь свой). Домен обязателен структурно — без него
     * провижининг не сможет получить сертификат через certbot.
     */
    private static function hysteria2(array $body, array $target): int
    {
        $h = $body['hysteria2'] ?? [];
        if (empty($h['domain'])) {
            throw new \InvalidArgumentException('Поле domain обязательно для Hysteria2 (нужен для TLS-сертификата)');
        }

        return ExitServer::create([
            'name' => $body['label'] ?? $target['name'],
            'endpoint_host' => $h['endpoint_host'] ?? $target['host'],
            'endpoint_port' => (int) ($h['endpoint_port'] ?? 443),
            'status' => $h['status'] ?? 'active',
            'protocol' => 'hysteria2',
            'protocol_params' => [
                'domain' => $h['domain'],
                'password' => $h['password'] ?: bin2hex(random_bytes(16)),
                'obfs_password' => $h['obfs_password'] ?: bin2hex(random_bytes(16)),
            ],
        ]);
    }

    private static function tuic(array $body, array $target): int
    {
        $t = $body['tuic'] ?? [];
        if (empty($t['domain'])) {
            throw new \InvalidArgumentException('Поле domain обязательно для TUIC (нужен для TLS-сертификата)');
        }

        return ExitServer::create([
            'name' => $body['label'] ?? $target['name'],
            'endpoint_host' => $t['endpoint_host'] ?? $target['host'],
            'endpoint_port' => (int) ($t['endpoint_port'] ?? 443),
            'status' => $t['status'] ?? 'active',
            'protocol' => 'tuic',
            'protocol_params' => [
                'domain' => $t['domain'],
                'uuid' => $t['uuid'] ?: self::uuidV4(),
                'password' => $t['password'] ?: bin2hex(random_bytes(16)),
                'congestion_control' => $t['congestion_control'] ?: 'bbr',
            ],
        ]);
    }

    private static function trojan(array $body, array $target): int
    {
        $t = $body['trojan'] ?? [];
        if (empty($t['domain'])) {
            throw new \InvalidArgumentException('Поле domain обязательно для Trojan (нужен для TLS-сертификата)');
        }

        return ExitServer::create([
            'name' => $body['label'] ?? $target['name'],
            'endpoint_host' => $t['endpoint_host'] ?? $target['host'],
            'endpoint_port' => (int) ($t['endpoint_port'] ?? 443),
            'status' => $t['status'] ?? 'active',
            'protocol' => 'trojan',
            'protocol_params' => [
                'domain' => $t['domain'],
                'password' => $t['password'] ?: bin2hex(random_bytes(16)),
            ],
        ]);
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
