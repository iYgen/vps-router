<?php

// Отдаёт готовый wg-quick .conf для конкретного WireGuard-пира
// (App\Models\ExitServerPeer) файлом на скачивание — тем же самым файлом,
// который можно перетащить в Keenetic (Интернет → Другие подключения →
// WireGuard → Импорт) или в любой другой стандартный WireGuard-клиент.

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\ExitServer;
use App\Models\ExitServerPeer;

Auth::requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$peer = ExitServerPeer::find($id);
if (!$peer || $peer['revoked']) {
    http_response_code(404);
    die('Пир не найден или отозван');
}

$es = ExitServer::find((int) $peer['exit_server_id']);
if (!$es || ($es['protocol'] ?? '') !== 'wireguard') {
    http_response_code(422);
    die('Exit-сервер не найден или не настроен как protocol=wireguard');
}

$params = !empty($es['protocol_params']) ? (json_decode($es['protocol_params'], true) ?: []) : [];
$serverPubkey = $params['peer_pubkey'] ?? null;
if (!$serverPubkey) {
    http_response_code(422);
    die('У exit-сервера ещё не сгенерирован серверный ключ — сначала «Установить и настроить» связь');
}

$conf = "[Interface]\n"
    . "PrivateKey = {$peer['private_key']}\n"
    . "Address = {$peer['tunnel_address']}\n"
    . "DNS = 1.1.1.1, 8.8.8.8\n"
    . "\n"
    . "[Peer]\n"
    . "PublicKey = {$serverPubkey}\n"
    . "Endpoint = {$es['endpoint_host']}:{$es['endpoint_port']}\n"
    . "AllowedIPs = 0.0.0.0/0\n"
    . "PersistentKeepalive = 25\n";

$safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $peer['name']) ?: 'device';
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeName . '.conf"');
header('Content-Length: ' . strlen($conf));
echo $conf;
