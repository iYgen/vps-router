<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Diagnostics;
use App\Http;
use App\LocalSystem;
use App\Models\AuditLog;
use App\Models\RiskScanResult;
use App\Models\Server;
use App\Provisioner;
use App\ServerTraffic;
use App\ServerIncidents;
use App\RiskScanner;
use App\Ssh;

/**
 * Перед SSH-логином: отвечает ли вообще SSH на порту. Если нет — вместо
 * невнятного таймаута phpseclib возвращаем разбор: скан портов локально,
 * проверку извне и вывод (сервер выключен / SSH на другом порту / похоже
 * на блокировку Active Blocking System). null — SSH отвечает, можно логиниться.
 */
function sshPrecheck(string $host, int $port): ?array
{
    if (!preg_match('/^(?!-)[A-Za-z0-9.:_-]{1,255}$/', $host) || $port < 1 || $port > 65535) {
        return null; // дальше Ssh:: сам вернёт понятную ошибку валидации
    }
    $probe = Diagnostics::probeSsh($host, $port);
    // Одного приветствия мало: Active Blocking System пропускает баннер и рвёт обмен ключами.
    if ($probe['banner'] && Diagnostics::probeSshKex($host, $port)['ok']) {
        return null;
    }
    $diagnosis = Diagnostics::analyzeSshFailure($host, $port);
    if ($diagnosis['verdict'] === 'ssh_ok') {
        return null; // со второй попытки ответил — пусть логин решает
    }
    AuditLog::record('server.ssh_diagnosis', "$host:$port " . $diagnosis['verdict']);
    return ['ok' => false, 'raw_error' => $diagnosis['message'], 'diagnosis' => $diagnosis];
}

$method = Http::guard(['GET', 'POST', 'PUT', 'DELETE']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET' && $action === 'risk-scan-history') {
        Http::json($id ? RiskScanResult::historyFor($id) : RiskScanResult::latestPerServer());
    }

    // Журнал простоев узла: список инцидентов недоступности (down→up) с логами.
    if ($method === 'GET' && $action === 'incidents' && $id) {
        Http::json(ServerIncidents::listFor($id));
    }
    if ($method === 'GET' && $action === 'incident-log') {
        $incId = isset($_GET['incident']) ? (int) $_GET['incident'] : 0;
        Http::json(['log' => $incId ? ServerIncidents::logFor($incId) : null]);
    }

    if ($method === 'GET' && $action === null) {
        Http::json($id ? (Server::find($id) ?? Http::error('Не найдено', 404)) : Server::all());
    }

    // Проверка входа по логину/паролю ДО создания сервера (мастер, шаг 2).
    // Ничего не сохраняет — ни пароль, ни ключ.
    if ($method === 'POST' && $action === 'test-password') {
        $body = Http::jsonInput();
        if ($pre = sshPrecheck(trim((string) ($body['host'] ?? '')), (int) ($body['ssh_port'] ?? 22))) {
            Http::json($pre);
        }
        $result = Ssh::testConnectionWithPassword(
            trim((string) ($body['host'] ?? '')),
            (int) ($body['ssh_port'] ?? 22),
            trim((string) ($body['ssh_user'] ?? 'root')) ?: 'root',
            (string) ($body['password'] ?? '')
        );
        AuditLog::record('server.test_password', ($body['host'] ?? '?') . ': ' . ($result['ok'] ? 'ok' : 'failed'));
        Http::json($result);
    }

    // Вход по паролю -> панель сама создаёт SSH-ключ, кладёт публичный в
    // authorized_keys на сервере и дальше работает только по ключу.
    if ($method === 'POST' && $action === 'bootstrap-ssh') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        if (!$server['host']) {
            Http::error('У сервера не задан host', 422);
        }
        $body = Http::jsonInput();
        Provisioner::knockIfConfigured($server);
        if ($pre = sshPrecheck($server['host'], (int) $server['ssh_port'])) {
            Server::setCheckResult($id, 'offline', $pre);
            Http::json($pre);
        }
        $boot = Ssh::bootstrapKeyWithPassword(
            $server['host'],
            (int) $server['ssh_port'],
            $server['ssh_user'],
            (string) ($body['password'] ?? '')
        );
        if (!$boot['ok']) {
            AuditLog::record('server.bootstrap_ssh', $server['name'] . ': failed');
            Http::json(['ok' => false, 'raw_error' => $boot['raw_error']]);
        }
        Server::setSshKeyPair($id, $boot['private_key'], $boot['public_key']);
        $result = Ssh::testConnection($server['host'], (int) $server['ssh_port'], $server['ssh_user'], $boot['private_key']);
        Server::setCheckResult($id, $result['ok'] ? 'online' : 'offline', $result);
        AuditLog::record('server.bootstrap_ssh', $server['name'] . ': ok');
        Http::json($result + ['public_key' => $boot['public_key']]);
    }

    if ($method === 'POST' && ($action === 'test-connection' || $action === 'metrics') && $id) {
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        if ($server['is_self']) {
            // Сервер самой панели — данные читаем локально, SSH не нужен.
            $info = LocalSystem::info();
            Server::setCheckResult($id, 'online', $info);
            Http::json($action === 'metrics' ? $info['metrics'] : $info);
        }
    }

    if ($method === 'POST' && $action === 'test-connection') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        $privateKey = Server::sshPrivateKey($id);
        if (!$privateKey) {
            Http::error('Для этого сервера не сохранён приватный ключ SSH');
        }
        Provisioner::knockIfConfigured($server);
        if ($pre = sshPrecheck($server['host'], (int) $server['ssh_port'])) {
            Server::setCheckResult($id, 'offline', $pre);
            Http::json($pre);
        }
        $result = Ssh::testConnection($server['host'], (int) $server['ssh_port'], $server['ssh_user'], $privateKey);
        Server::setCheckResult($id, $result['ok'] ? 'online' : 'offline', $result);
        AuditLog::record('server.test_connection', $server['name'] . ': ' . ($result['ok'] ? 'ok' : 'failed'));
        Http::json($result);
    }

    if ($method === 'POST' && $action === 'metrics') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        $privateKey = Server::sshPrivateKey($id);
        if (!$privateKey) {
            Http::error('Для этого сервера не сохранён приватный ключ SSH');
        }
        Provisioner::knockIfConfigured($server);
        $result = Ssh::fetchMetrics($server['host'], (int) $server['ssh_port'], $server['ssh_user'], $privateKey);
        if ($result['ok']) {
            Server::storeMetrics($id, $result);
        }
        Http::json($result);
    }

    if ($method === 'POST' && $action === 'diagnostics') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        if (!$server['host']) {
            Http::error('У сервера не задан host', 422);
        }
        $body = Http::jsonInput();
        $port = (int) ($body['port'] ?? 443);
        $report = Diagnostics::run($server['host'], $port);
        AuditLog::record('server.diagnostics', $server['name'] . ": port=$port");
        Http::json($report);
    }

    if ($method === 'POST' && $action === 'risk-scan') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        if (!$server['host']) {
            Http::error('У сервера не задан host', 422);
        }
        $result = RiskScanner::scan($server['host'], $server['name'], RiskScanner::camouflageFor($server));
        RiskScanResult::record($id, $server['host'], $result['score'], $result['findings'], 'manual');
        AuditLog::record('server.risk_scan', $server['name'] . ': score=' . $result['score']);
        Http::json($result);
    }

    if ($method === 'POST' && $action === 'harden-portknock') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        $body = Http::jsonInput();
        $protectedPort = (int) ($body['protected_port'] ?? 22);
        $knockPorts = array_map('intval', $body['knock_ports'] ?? []);
        try {
            $result = Provisioner::hardenPortKnock($id, $protectedPort, $knockPorts);
        } catch (\InvalidArgumentException $e) {
            Http::error($e->getMessage(), 422);
        }
        AuditLog::record('server.harden_portknock', $server['name'] . ": port=$protectedPort knocks=" . implode(',', $knockPorts));
        Http::json($result);
    }

    if ($method === 'POST' && $action === 'harden-portscan-ban') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        $body = Http::jsonInput();
        $decoyPorts = array_map('intval', $body['decoy_ports'] ?? []);
        $banSeconds = (int) ($body['ban_seconds'] ?? 86400);
        try {
            $result = Provisioner::hardenPortscanBan($id, $decoyPorts, $banSeconds);
        } catch (\InvalidArgumentException $e) {
            Http::error($e->getMessage(), 422);
        }
        AuditLog::record('server.harden_portscan_ban', $server['name'] . ': decoys=' . implode(',', $decoyPorts));
        Http::json($result);
    }

    if ($method === 'POST' && $action === 'provision') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        if (!$server['is_self']) {
            Http::error('Автоустановка ПО поддерживается только для собственного (self) сервера панели — входной VPS', 422);
        }
        $result = Provisioner::provisionRfVps();
        AuditLog::record('server.provision', $server['name'] . ': ' . ($result['ok'] ? 'ok' : 'failed'));
        Http::json($result);
    }

    // «Сейчас хостер показывает X ГБ» — поправка к собственному замеру панели.
    if ($method === 'POST' && $action === 'traffic-sync') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $server = Server::find($id) ?? Http::error('Сервер не найден', 404);
        $body = Http::jsonInput();
        $gb = (float) str_replace(',', '.', (string) ($body['hoster_gb'] ?? ''));
        if ($gb < 0) {
            Http::error('Некорректное значение', 422);
        }
        ServerTraffic::sync($id, $gb);
        AuditLog::record('server.traffic_sync', $server['name'] . ": {$gb} GB");
        Http::json(ServerTraffic::usage(Server::find($id)));
    }

    if ($method === 'POST' && $action === 'position') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $body = Http::jsonInput();
        Server::setPosition($id, (float) ($body['x'] ?? 0), (float) ($body['y'] ?? 0));
        Http::json(['ok' => true]);
    }

    if ($method === 'POST') {
        $body = Http::jsonInput();
        $newId = Server::create($body);
        if (array_key_exists('traffic_limit_gb', $body)) {
            Server::update($newId, $body); // лимит трафика и пр. — через update(), create() их не знает
        }
        AuditLog::record('server.create', $body['name'] ?? "id=$newId");
        Http::json(Server::find($newId), 201);
    }

    if ($method === 'PUT') {
        if (!$id) {
            Http::error('id обязателен');
        }
        Server::update($id, Http::jsonInput());
        AuditLog::record('server.update', "id=$id");
        Http::json(Server::find($id));
    }

    if ($method === 'DELETE') {
        if (!$id) {
            Http::error('id обязателен');
        }
        Server::delete($id);
        AuditLog::record('server.delete', "id=$id");
        Http::json(['ok' => true]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
