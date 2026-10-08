<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\ClientPolicyBuilder;
use App\Http;
use App\Models\AuditLog;
use App\Models\PolicyDevice;
use App\Models\PolicyProfile;
use App\Models\PolicyVersion;

$method = Http::guard(['GET', 'POST', 'PUT', 'DELETE']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET' && $id && $action === 'routes') {
        Http::json(PolicyProfile::routes($id));
    }

    if ($method === 'GET' && $id && $action === 'versions') {
        Http::json(PolicyVersion::forProfile($id));
    }

    if ($method === 'GET') {
        if ($id) {
            $profile = PolicyProfile::find($id);
            if (!$profile) {
                Http::error('Не найдено', 404);
            }
            $profile['routes'] = PolicyProfile::routes($id);
            $profile['latest_version'] = PolicyVersion::latest($id);
            Http::json($profile);
        }
        Http::json(PolicyProfile::all());
    }

    if ($method === 'POST' && $id && $action === 'routes') {
        $body = Http::jsonInput();
        $ruleGroupId = (int) ($body['rule_group_id'] ?? 0);
        $op = $body['op'] ?? 'add';
        if ($op === 'remove') {
            PolicyProfile::removeRoute($id, $ruleGroupId);
        } else {
            PolicyProfile::addRoute($id, $ruleGroupId);
        }
        AuditLog::record('policy_profile.routes', "profile=$id rule_group=$ruleGroupId op=$op");
        Http::json(['routes' => PolicyProfile::routes($id)]);
    }

    if ($method === 'POST' && $id && $action === 'render') {
        // Превью без публикации новой версии — не пишет policy_versions.
        Http::json((new ClientPolicyBuilder())->build($id));
    }

    if ($method === 'POST' && $id && $action === 'publish') {
        $result = (new ClientPolicyBuilder())->publish($id, Auth::username() ?? 'system');
        AuditLog::record('policy_profile.publish', "profile=$id version={$result['policy']['version']}");
        foreach (PolicyDevice::all() as $device) {
            if ((int) ($device['profile_id'] ?? 0) === $id && $device['enabled']) {
                try {
                    (new App\KeeneticSyncService())->sync((int) $device['id']);
                } catch (\Throwable) {
                    // sync() уже пишет статус/лог в policy_devices — publish не должен падать из-за одного недоступного устройства.
                }
            }
        }
        Http::json($result);
    }

    if ($method === 'POST') {
        $body = Http::jsonInput();
        $newId = PolicyProfile::create($body);
        AuditLog::record('policy_profile.create', $body['name'] ?? "id=$newId");
        Http::json(PolicyProfile::find($newId), 201);
    }

    if ($method === 'PUT') {
        if (!$id) {
            Http::error('id обязателен');
        }
        PolicyProfile::update($id, Http::jsonInput());
        AuditLog::record('policy_profile.update', "id=$id");
        Http::json(PolicyProfile::find($id));
    }

    if ($method === 'DELETE') {
        if (!$id) {
            Http::error('id обязателен');
        }
        PolicyProfile::delete($id);
        AuditLog::record('policy_profile.delete', "id=$id");
        Http::json(['ok' => true]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
