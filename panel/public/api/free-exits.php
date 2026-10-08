<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\FreeSubscriptions;
use App\Http;
use App\Models\AuditLog;
use App\Models\ExitServer;
use App\Models\ServerSet;
use App\Provisioner;

$method = Http::guard(['GET', 'POST']);
$action = $_GET['action'] ?? null;

try {
    // Список протестированных бесплатных нод (опц. по стране).
    if ($method === 'GET' && $action === 'list') {
        $country = isset($_GET['country']) && $_GET['country'] !== '' ? (string) $_GET['country'] : null;
        $nodes = FreeSubscriptions::fetch($country);
        // Наружу — без raw (он большой и с секретами); только для показа списка.
        $list = array_map(fn($n) => [
            'tag' => $n['tag'],
            'type' => $n['type'],
            'server' => $n['server'],
            'port' => $n['port'],
            'country' => $n['country'],
        ], $nodes);
        Http::json([
            'nodes' => $list,
            'countries' => FreeSubscriptions::COUNTRIES,
            'untrusted' => true, // всегда: это чужие публичные прокси
        ]);
    }

    if ($method === 'POST' && $action === 'import') {
        $body = Http::jsonInput();
        $country = isset($body['country']) && $body['country'] !== '' ? (string) $body['country'] : null;
        $wantTags = array_flip(array_map('strval', (array) ($body['tags'] ?? [])));
        if (empty($wantTags)) {
            Http::error('Не выбрано ни одной ноды');
        }
        // Тянем свежий список и берём raw только для выбранных тегов.
        $nodes = FreeSubscriptions::fetch($country);
        $created = [];
        foreach ($nodes as $n) {
            if (!isset($wantTags[$n['tag']])) {
                continue;
            }
            $name = uniqueName($n);
            $id = ExitServer::create([
                'name' => $name,
                'endpoint_host' => $n['server'],
                'endpoint_port' => $n['port'],
                'status' => 'active',
                'protocol' => $n['type'],
                'protocol_params' => ['_raw' => $n['raw']],
                'source' => 'free',
                'country' => $n['country'],
            ]);
            ExitServer::setProvisionStatus($id, 'external', 'Импортировано из free-vpn-subscriptions (недоверенная публичная нода)');
            $created[] = ['id' => $id, 'name' => $name];
        }
        if (!$created) {
            Http::error('Выбранные ноды не найдены в свежем списке (могли ротироваться) — обновите список');
        }
        // Узлы графа + связи для новых exit'ов появятся сами (Provisioner::syncOrphanExitServers).
        $poolId = null;
        if (!empty($body['build_pool'])) {
            Provisioner::syncOrphanExitServers();
            $poolId = ensureFreePool(array_column($created, 'id'));
        }
        AuditLog::record('free_exit.import', count($created) . ' nodes' . ($poolId ? " + pool #$poolId" : ''));
        Http::json(['created' => $created, 'pool_id' => $poolId], 201);
    }

    Http::error('Неизвестное действие', 404);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error($e->getMessage(), 500);
}

/**
 * Уникальное имя для exit_servers (name UNIQUE): free-COUNTRY-TYPE-N.
 */
function uniqueName(array $node): string
{
    $base = 'free-' . ($node['country'] ? $node['country'] . '-' : '') . $node['type'];
    $existing = array_column(ExitServer::all(), 'name');
    $name = $base;
    $i = 1;
    while (in_array($name, $existing, true)) {
        $name = $base . '-' . (++$i);
    }
    return $name;
}

/**
 * Создаёт (или дополняет) failover-набор «🆓 Free pool» из графовых узлов
 * указанных free-exit'ов. Узлы к этому моменту уже созданы syncOrphanExitServers.
 */
function ensureFreePool(array $exitServerIds): int
{
    $poolName = '🆓 Free pool';
    $setId = null;
    foreach (ServerSet::all() as $s) {
        if ($s['name'] === $poolName) {
            $setId = (int) $s['id'];
            break;
        }
    }
    if ($setId === null) {
        $setId = ServerSet::create(['name' => $poolName, 'strategy' => 'failover', 'description' => 'Автопул бесплатных нод (недоверенные, только для не-персональных данных)']);
    }
    // Графовый узел exit'а — target_server_id связи, ссылающейся на этот exit_server.
    $exitToNode = [];
    foreach (\App\Models\Connection::all() as $c) {
        if (!empty($c['exit_server_id'])) {
            $exitToNode[(int) $c['exit_server_id']] = (int) $c['target_server_id'];
        }
    }
    $prio = 0;
    foreach ($exitServerIds as $esId) {
        if (!empty($exitToNode[$esId])) {
            ServerSet::addMember($setId, $exitToNode[$esId], $prio++);
        }
    }
    return $setId;
}
