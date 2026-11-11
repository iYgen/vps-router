<?php

// Ссылка-подписка устройства: приложения на ядре Xray/sing-box (v2rayTun, Happ,
// v2rayNG, Hiddify, Streisand) сами скачивают по ней список профилей и обновляют
// его. В списке — все включённые входы устройства, прямые и через каждый
// настроенный ретранслятор (App\Relay): новый ретранслятор появится в приложении
// при следующем обновлении подписки, без ручного импорта.
//
// Без логина: доступ по секретному токену устройства (HMAC от uuid и секрета
// панели, см. DeviceInbounds::subToken). Отозванное устройство получает 404.
// WireGuard/AmneziaWG (файлы .conf) в подписку не входят — только ссылки.

require __DIR__ . '/../src/bootstrap.php';

use App\DeviceInbounds;
use App\Models\Client;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$id = (int) ($_GET['id'] ?? 0);
$token = (string) ($_GET['t'] ?? '');
$client = $id > 0 ? Client::find($id) : null;
if (!$client || !empty($client['revoked']) || !hash_equals(DeviceInbounds::subToken($client), $token)) {
    http_response_code(404);
    exit('Not found');
}

$rid = $client['router_server_id'] ?? null;
DeviceInbounds::useRouter($rid !== null && !\App\RouterContext::isSelf((int) $rid) ? (int) $rid : null);

$uris = [];
foreach (DeviceInbounds::allConfigs($client) as $entry) {
    if (!empty($entry['uri'])) {
        $uris[] = $entry['uri'];
    }
}

$body = base64_encode(implode("\n", $uris));
header('Content-Type: text/plain; charset=utf-8');
header('Profile-Title: base64:' . base64_encode((string) $client['name']));
header('Profile-Update-Interval: 6'); // часов
header('Content-Disposition: inline; filename="' . (preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) $client['name']) ?: 'device') . '.txt"');
echo $body;
