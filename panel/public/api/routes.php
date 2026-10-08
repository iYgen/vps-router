<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\DomainSensitivity;
use App\Http;
use App\IpListImporter;
use App\Models\AuditLog;
use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\ServerSet;
use App\RouterContext;

/**
 * Чувствительность маршрута к прогону через exit: худший уровень среди правил +
 * надбавка, если exit недоверенный (source=free). Для бейджа в routes-tree.js.
 */
function routeSensitivity(array $group, array $rules): array
{
    // Идёт ли маршрут через бесплатный (недоверенный) exit?
    $resolvedExitId = null;
    if (!empty($group['server_set_id'])) {
        $resolvedExitId = ServerSet::resolveExitServerId((int) $group['server_set_id']);
    } elseif (!empty($group['exit_server_id'])) {
        $resolvedExitId = (int) $group['exit_server_id'];
    }
    $viaUntrusted = false;
    if ($resolvedExitId) {
        $es = ExitServer::find($resolvedExitId);
        $viaUntrusted = $es && ($es['source'] ?? 'own') === 'free';
    }

    $worst = ['level' => 'safe', 'score' => 0, 'category' => ''];
    $rank = ['safe' => 0, 'neutral' => 1, 'sensitive' => 2];
    foreach ($rules as $r) {
        $d = DomainSensitivity::dangerForRoute($r['value'] ?? '', $r['type'] ?? 'domain_suffix', $viaUntrusted);
        if ($d['score'] > $worst['score'] || $rank[$d['level']] > $rank[$worst['level']]) {
            $worst = ['level' => $d['level'], 'score' => $d['score'], 'category' => $d['category']];
        }
    }
    $worst['via_untrusted'] = $viaUntrusted;
    return $worst;
}

$method = Http::guard(['GET', 'POST', 'PUT', 'DELETE']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;

// Мультироутерность: список/создание маршрутов — в контексте текущего роутера.
$routerId = RouterContext::currentId();
$includeNull = RouterContext::includeNull($routerId);

function routeWithRules(int $id): array
{
    $group = RuleGroup::find($id);
    if (!$group) {
        Http::error('Route не найден', 404);
    }
    $group['rules'] = Rule::forGroup($id);
    $group['sensitivity'] = routeSensitivity($group, $group['rules']);
    return $group;
}

try {
    if ($method === 'GET' && $action === 'presets') {
        Http::json(['presets' => \App\RoutePresets::all()]);
    }

    if ($method === 'GET' && $action === 'list-sources') {
        Http::json(['sources' => \App\ExternalListImporter::sources()]);
    }

    if ($method === 'POST' && $action === 'import-list') {
        $body = Http::jsonInput();
        $src = trim((string) ($body['source'] ?? $body['url'] ?? ''));
        if ($src === '') {
            Http::error('Укажите источник или URL', 400);
        }
        $exitServerId = !empty($body['exit_server_id']) ? (int) $body['exit_server_id'] : null;
        $serverSetId = !empty($body['server_set_id']) ? (int) $body['server_set_id'] : null;
        try {
            $res = \App\ExternalListImporter::import($src, $exitServerId, $serverSetId, $routerId);
        } catch (\InvalidArgumentException $e) {
            Http::error($e->getMessage(), 400);
        }
        AuditLog::record('route.import_list', $res['name'] . " d={$res['domains']} ip={$res['ips']} (router=$routerId)");
        Http::json($res, 201);
    }

    if ($method === 'GET' && $action === 'inspect') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            Http::error('Укажите домен или IP', 400);
        }
        Http::json(\App\RouteInspector::inspect($q, $routerId, $includeNull));
    }

    if ($method === 'POST' && $action === 'create-preset') {
        $body = Http::jsonInput();
        $preset = \App\RoutePresets::get((string) ($body['preset'] ?? ''));
        if (!$preset) {
            Http::error('Неизвестный пресет', 400);
        }
        $exitServerId = !empty($body['exit_server_id']) ? (int) $body['exit_server_id'] : null;
        $serverSetId = !empty($body['server_set_id']) ? (int) $body['server_set_id'] : null;
        if (!$exitServerId && !$serverSetId) {
            Http::error('Выберите выходной сервер или набор', 400);
        }
        // origin='preset' + import_source_path='preset:<id>' — чтобы группа была
        // отслеживаемой и ре-синхронизируемой (RoutePresets::sync), по аналогии
        // с импортированными списками.
        $gid = RuleGroup::create($preset['name'], $exitServerId, $serverSetId, 'preset:' . $preset['id'], null, 'preset', \App\RoutePresets::SOURCE_PREFIX . $preset['id'], $routerId);
        foreach ($preset['geosite'] as $g) {
            Rule::create($gid, 'geosite', $g);
        }
        AuditLog::record('route.preset', $preset['id'] . " (router=$routerId)");
        Http::json(['created' => $gid], 201);
    }

    if ($method === 'GET') {
        if ($id) {
            Http::json(routeWithRules($id));
        }
        $groups = RuleGroup::all($routerId, $includeNull);
        foreach ($groups as &$g) {
            $g['rules'] = Rule::forGroup((int) $g['id']);
            $g['sensitivity'] = routeSensitivity($g, $g['rules']);
        }
        Http::json($groups);
    }

    if ($method === 'POST' && $action === 'sync') {
        // Десятки последовательных HTTP-запросов к GitHub — может занять
        // до минуты, чем длиннее список файлов. Для регулярного обновления
        // предпочтительнее bin/sync-ip-lists.php по cron (см. документацию);
        // этот эндпоинт — для разового запуска руками из UI.
        set_time_limit(300);
        $result = IpListImporter::sync();
        // По аналогии синхронизируем и группы-пресеты (geosite под актуальный каталог).
        $presets = \App\RoutePresets::sync($routerId, $includeNull);
        $result = array_merge($result, $presets);
        AuditLog::record('routes.sync', "imported={$result['imported']} skipped={$result['skipped']} "
            . "presets_updated={$presets['presets_updated']} errors=" . count($result['errors']));
        Http::json($result);
    }

    if ($method === 'POST' && $action === 'bulk') {
        $body = Http::jsonInput();
        $destinations = array_values(array_filter(array_map('trim', (array) ($body['destinations'] ?? []))));
        if (empty($destinations)) {
            Http::error('Список destination пуст');
        }
        $type = $body['type'] ?? 'domain_suffix';
        if (!in_array($type, Rule::TYPES, true)) {
            Http::error('Неизвестный тип destination', 422);
        }
        $serverSetId = !empty($body['server_set_id']) ? (int) $body['server_set_id'] : null;
        $exitServerId = !empty($body['exit_server_id']) ? (int) $body['exit_server_id'] : null;
        $parentId = !empty($body['parent_id']) ? (int) $body['parent_id'] : null;

        $createdIds = [];
        foreach ($destinations as $dest) {
            $groupId = RuleGroup::create($dest, $exitServerId, $serverSetId, null, $parentId, 'manual', null, $routerId);
            Rule::create($groupId, $type, $dest);
            $createdIds[] = $groupId;
        }
        AuditLog::record('route.bulk_create', count($createdIds) . ' routes');
        Http::json(['created' => $createdIds], 201);
    }

    if ($method === 'POST' && $action === 'add-rule') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $body = Http::jsonInput();
        Rule::create($id, $body['type'] ?? '', trim($body['value'] ?? ''));
        AuditLog::record('rule.add', "group=$id");
        Http::json(routeWithRules($id));
    }

    if ($method === 'POST' && $action === 'delete-rule') {
        $body = Http::jsonInput();
        Rule::delete((int) ($body['rule_id'] ?? 0));
        AuditLog::record('rule.delete', "rule=" . ($body['rule_id'] ?? '?'));
        Http::json(routeWithRules($id ?? (int) ($body['group_id'] ?? 0)));
    }

    if ($method === 'POST') {
        $body = Http::jsonInput();
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            Http::error('Название route обязательно');
        }
        $groupId = RuleGroup::create(
            $name,
            !empty($body['exit_server_id']) ? (int) $body['exit_server_id'] : null,
            !empty($body['server_set_id']) ? (int) $body['server_set_id'] : null,
            $body['comment'] ?? null,
            !empty($body['parent_id']) ? (int) $body['parent_id'] : null,
            'manual',
            null,
            $routerId
        );
        if (!empty($body['type']) && !empty($body['value'])) {
            Rule::create($groupId, $body['type'], $body['value']);
        }
        AuditLog::record('route.create', $name);
        Http::json(routeWithRules($groupId), 201);
    }

    if ($method === 'PUT') {
        if (!$id) {
            Http::error('id обязателен');
        }
        $body = Http::jsonInput();
        if (isset($body['name'])) {
            RuleGroup::rename($id, $body['name']);
        }
        if (array_key_exists('server_set_id', $body)) {
            RuleGroup::setServerSet($id, $body['server_set_id'] ? (int) $body['server_set_id'] : null);
        } elseif (array_key_exists('exit_server_id', $body)) {
            RuleGroup::setExitServer($id, $body['exit_server_id'] ? (int) $body['exit_server_id'] : null);
        }
        if (isset($body['enabled'])) {
            RuleGroup::setEnabled($id, (bool) $body['enabled']);
        }
        if (array_key_exists('comment', $body)) {
            RuleGroup::setComment($id, $body['comment']);
        }
        if (array_key_exists('parent_id', $body)) {
            $newParentId = $body['parent_id'] ? (int) $body['parent_id'] : null;
            if ($newParentId === $id) {
                Http::error('Группа не может быть родителем сама себе', 422);
            }
            // Проверяем весь путь вверх от нового родителя — иначе можно
            // создать цикл (A -> B -> A), который RuleGroup::tree() не умеет
            // корректно развернуть.
            $cursor = $newParentId;
            $depth = 0;
            while ($cursor !== null && $depth < 200) {
                if ($cursor === $id) {
                    Http::error('Нельзя переместить группу в одну из её собственных подгрупп', 422);
                }
                $parent = RuleGroup::find($cursor);
                $cursor = $parent ? ($parent['parent_id'] ? (int) $parent['parent_id'] : null) : null;
                $depth++;
            }
            RuleGroup::setParent($id, $newParentId);
        }
        AuditLog::record('route.update', "id=$id");
        Http::json(routeWithRules($id));
    }

    if ($method === 'DELETE') {
        if (!$id) {
            Http::error('id обязателен');
        }
        RuleGroup::delete($id);
        AuditLog::record('route.delete', "id=$id");
        Http::json(['ok' => true]);
    }

    Http::error('Не удалось обработать запрос', 400);
} catch (\InvalidArgumentException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
