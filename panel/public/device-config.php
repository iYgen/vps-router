<?php

// Отдаёт готовый конфиг подключения к входной VPS (VLESS+Reality inbound,
// panel/src/SingboxConfigBuilder.php) для конкретного устройства (App\Models\Client)
// файлом на скачивание — вместо того чтобы пользователь копировал ссылку руками
// с devices.php. Формат:
//   format=uri     — сама vless:// ссылка, .txt (для v2rayNG/Shadowrocket/NekoBox — есть
//                     прямой импорт по ссылке/QR, файл не нужен, но удобно сохранить).
//   format=singbox — полноценный клиентский конфиг sing-box (.json) — локальный
//                     mixed-inbound на 127.0.0.1:2080 + vless-outbound с этими же
//                     параметрами, для официальных sing-box GUI/CLI клиентов.

//   format=<протокол> (shadowsocks|trojan|hysteria2|wireguard|amneziawg|vless) —
//                     ссылка или .conf этого протокола (App\DeviceInbounds::clientConfigs).
//                     &inline=1 — отдать как текст без скачивания (для QR на devices.php).

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\DeviceInbounds;
use App\Models\Client;
use App\Models\Setting;

Auth::requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$format = $_GET['format'] ?? 'uri';
if (!in_array($format, ['uri', 'singbox'], true) && !isset(DeviceInbounds::PROTOCOLS[$format])) {
    http_response_code(400);
    die('Неизвестный формат');
}

Client::backfillCredentials();
$client = Client::find($id);
if (!$client) {
    http_response_code(404);
    die('Устройство не найдено');
}
if ($client['revoked']) {
    http_response_code(409);
    die('Устройство отозвано — конфиг больше не действителен. Разрешите его на странице «Устройства», если это ошибка.');
}

if (isset(DeviceInbounds::PROTOCOLS[$format])) {
    $configs = DeviceInbounds::clientConfigs($client);
    if (!isset($configs[$format])) {
        http_response_code(422);
        die('Протокол «' . DeviceInbounds::PROTOCOLS[$format]['label'] . '» выключен или ещё не настроен (Настройки → «Протоколы подключения устройств»).');
    }
    $entry = $configs[$format];
    $body = $entry['uri'] ?? $entry['file'];
    header('Content-Type: text/plain; charset=utf-8');
    if (empty($_GET['inline'])) {
        header('Content-Disposition: attachment; filename="' . $entry['filename'] . '"');
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$settings = Setting::all();
$config = \App\App::config();
$connectHost = $settings['reality_public_host'] ?? $config['reality_listen_ip'] ?? null;
$port = (int) ($settings['reality_listen_port'] ?? $config['reality_listen_port'] ?? 443);
$pbk = $settings['reality_public_key'] ?? null;
$sid = $settings['reality_short_id'] ?? null;
$sni = $settings['reality_server_name'] ?? null;

if (!$connectHost || !$pbk || !$sid || !$sni) {
    http_response_code(422);
    die('Reality-настройки сервера не заполнены (страница «Настройки») — конфиг сгенерировать нельзя.');
}

$safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $client['name']) ?: 'device';
$uri = sprintf(
    'vless://%s@%s:%d?encryption=none&flow=xtls-rprx-vision&security=reality&sni=%s&fp=chrome&pbk=%s&sid=%s&type=tcp#%s',
    $client['uuid'],
    $connectHost,
    $port,
    rawurlencode($sni),
    rawurlencode($pbk),
    rawurlencode($sid),
    rawurlencode($client['name'])
);

if ($format === 'uri') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '-vless.txt"');
    header('Content-Length: ' . strlen($uri));
    echo $uri;
    exit;
}

// format === 'singbox': минимальный клиентский конфиг — локальный mixed-прокси
// на 127.0.0.1:2080 (SOCKS+HTTP) + один vless+reality outbound на этот входной VPS.
// Поля reality на клиентской стороне отличаются от серверной (private_key ->
// public_key, нет handshake) — см. sing-box.sagernet.org/configuration/outbound/vless/.
$singboxConfig = [
    'log' => ['level' => 'info'],
    'inbounds' => [[
        'type' => 'mixed',
        'tag' => 'mixed-in',
        'listen' => '127.0.0.1',
        'listen_port' => 2080,
    ]],
    'outbounds' => [
        [
            'type' => 'vless',
            'tag' => 'rf-vps',
            'server' => $connectHost,
            'server_port' => $port,
            'uuid' => $client['uuid'],
            'flow' => 'xtls-rprx-vision',
            'tls' => [
                'enabled' => true,
                'server_name' => $sni,
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'reality' => [
                    'enabled' => true,
                    'public_key' => $pbk,
                    'short_id' => $sid,
                ],
            ],
        ],
        ['type' => 'direct', 'tag' => 'direct'],
    ],
    'route' => ['final' => 'rf-vps'],
];

$json = json_encode($singboxConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeName . '-singbox.json"');
header('Content-Length: ' . strlen($json));
echo $json;
