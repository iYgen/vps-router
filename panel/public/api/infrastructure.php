<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Applier;
use App\Auth;
use App\Http;
use App\LocalSystem;
use App\Models\AuditLog;
use App\Models\ConfigVersion;
use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use App\Models\ServerSet;
use App\ServerTraffic;
use App\SingboxConfigBuilder;

$method = Http::guard(['GET', 'POST']);
$action = $_GET['action'] ?? null;

try {
    // Для плашки «Изменения не применены» в шапке любой страницы (View::footer).
    if ($method === 'GET' && $action === 'pending') {
        Http::json(Applier::pendingState(\App\RouterContext::currentId()));
    }

    if ($method === 'GET') {
        // Сервер самой панели проверяем прямо здесь (локальное чтение /proc —
        // миллисекунды): его статус и нагрузка на графе не должны зависеть от
        // того, настроен ли cron health_check_servers.php.
        $self = Server::self();
        if ($self && (!$self['last_checked_at'] || strtotime($self['last_checked_at'] . ' UTC') < time() - 20)) {
            Server::setCheckResult((int) $self['id'], 'online', LocalSystem::info());
        }

        // Exit-серверы, заведённые без узла на графе, показываем как узлы.
        \App\Provisioner::syncOrphanExitServers();

        $routes = RuleGroup::all();
        foreach ($routes as &$r) {
            $r['rules'] = Rule::forGroup((int) $r['id']);
            // Для UI (бейдж "N routes" на связи графа): куда route реально
            // пойдёт прямо сейчас, с учётом Server Set/failover-резолвинга.
            $r['resolved_exit_server_id'] = $r['server_set_id']
                ? ServerSet::resolveExitServerId((int) $r['server_set_id'])
                : (isset($r['exit_server_id']) ? (int) $r['exit_server_id'] ?: null : null);
        }
        unset($r);

        $connections = Connection::all();
        foreach ($connections as &$c) {
            if (empty($c['exit_server_id'])) {
                continue;
            }
            $es = ExitServer::find((int) $c['exit_server_id']);
            if ($es) {
                $c['protocol'] = $es['protocol'];
                $c['provision_status'] = $es['provision_status'];
                $c['last_provision_at'] = $es['last_provision_at'];
                $c['last_provision_log'] = $es['last_provision_log'];
                $c['exit_source'] = $es['source'] ?? 'own';
                $c['exit_country'] = $es['country'] ?? null;
            }
        }
        unset($c);

        // Израсходованный трафик за расчётный период — для лимита тарифа (App\ServerTraffic).
        $servers = Server::all();
        $selfId = ($sf = Server::self()) ? (int) $sf['id'] : null;
        foreach ($servers as &$srv) {
            $srv['traffic'] = ServerTraffic::usage($srv);
            // Для инспектора узла-роутера: сколько у него устройств и маршрутов.
            if (($srv['role'] ?? '') === 'router') {
                $rid = (int) $srv['id'];
                $incNull = $selfId !== null && $rid === $selfId;
                $srv['device_count'] = count(\App\Models\Client::all($rid, $incNull));
                $srv['route_count'] = count(RuleGroup::all($rid, $incNull));
            }
        }
        unset($srv);

        Http::json([
            'servers' => $servers,
            'connections' => $connections,
            'server_sets' => ServerSet::all(),
            'routes' => $routes,
            'executable_connection_types' => Connection::EXECUTABLE_TYPES,
        ]);
    }

    if ($method === 'POST' && $action === 'validate') {
        Http::json(['issues' => (new SingboxConfigBuilder())->validate(\App\RouterContext::currentId())]);
    }

    if ($method === 'POST' && $action === 'preview') {
        Http::json((new Applier())->preview(\App\RouterContext::currentId()));
    }

    if ($method === 'POST' && $action === 'apply') {
        $body = Http::jsonInput();
        // Явный router_id (кнопка «Применить» в инспекторе узла графа) имеет
        // приоритет над текущим роутером сессии; валидируем, что это роутер.
        $routerId = \App\RouterContext::currentId();
        if (!empty($body['router_id'])) {
            $reqId = (int) $body['router_id'];
            if (in_array($reqId, array_map(fn($r) => (int) $r['id'], \App\RouterContext::routers()), true)) {
                $routerId = $reqId;
            }
        }
        $issues = (new SingboxConfigBuilder())->validate($routerId);
        $hasErrors = (bool) array_filter($issues, fn($i) => $i['level'] === 'error');
        if ($hasErrors) {
            Http::error('Есть блокирующие ошибки валидации — Apply отменён', 422);
        }
        $result = (new Applier())->apply(Auth::username() ?? 'system', $body['description'] ?? 'Apply из графа инфраструктуры', $routerId);
        AuditLog::record('infrastructure.apply', "router=$routerId exit_code=" . $result['exit_code']);
        Http::json(['result' => $result, 'version' => ConfigVersion::latest()]);
    }

    if ($method === 'POST' && $action === 'rollback') {
        $body = Http::jsonInput();
        $versionId = (int) ($body['version_id'] ?? 0);
        $result = (new Applier())->rollback($versionId, Auth::username() ?? 'system');
        AuditLog::record('infrastructure.rollback', "version=$versionId exit_code=" . $result['exit_code']);
        Http::json(['result' => $result]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
