<?php

namespace App;

use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\Server;
use App\Models\ServerSet;

/**
 * Экспорт/импорт графа инфраструктуры (servers/connections/exit_servers/
 * server_sets) в переносимый JSON — НЕ включает Маршруты (rule_groups/rules,
 * у них свой CSV/текстовый импорт на странице «Маршруты») и НЕ включает ни
 * одного секрета: ssh_private_key, WG-ключи, VLESS/Shadowsocks-параметры
 * исключены полностью, а не просто скрыты — так экспорт безопасно положить
 * в git/переслать, и он в принципе переносим между инсталляциями (secrets
 * зашифрованы под app_secret конкретной панели и всё равно не расшифровались
 * бы на другой). После импорта exit-серверы оказываются в том же
 * "черновом" состоянии, что и свежесозданные через UI — довести до рабочих
 * через «Установить и настроить», как обычно.
 *
 * Импорт только ДОБАВЛЯЕТ: при конфликте имён (servers.name не уникально в
 * схеме — сравнение по точному совпадению; exit_servers.name/server_sets.name
 * уникальны в БД) существующая запись не трогается, конфликт просто
 * попадает в отчёт как "пропущено". Ничего не обновляется и не удаляется —
 * это осознанно самый безопасный вариант для операции, которую нельзя
 * отменить кнопкой "назад".
 */
class InfrastructureExport
{
    private const FORMAT_VERSION = 1;

    public static function export(): array
    {
        $servers = Server::all();
        $serverNameById = [];
        foreach ($servers as $s) {
            $serverNameById[$s['id']] = $s['name'];
        }

        $exitServers = ExitServer::all();
        $exitNameById = [];
        foreach ($exitServers as $es) {
            $exitNameById[$es['id']] = $es['name'];
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => date('c'),
            'servers' => array_map(static fn (array $s) => [
                'name' => $s['name'],
                'role' => $s['role'],
                'host' => $s['host'],
                'ssh_port' => (int) $s['ssh_port'],
                'ssh_user' => $s['ssh_user'],
                'country' => $s['country'],
                'region' => $s['region'],
                'description' => $s['description'],
                'tags' => json_decode($s['tags'] ?? '[]', true) ?: [],
                'enabled' => (bool) $s['enabled'],
                'position_x' => (float) $s['position_x'],
                'position_y' => (float) $s['position_y'],
                'is_self' => (bool) $s['is_self'],
            ], $servers),
            'connections' => array_map(static function (array $c) use ($exitNameById) {
                return [
                    'source_name' => $c['source_name'],
                    'target_name' => $c['target_name'],
                    'type' => $c['type'],
                    'label' => $c['label'],
                    'config' => $c['config'] !== null ? json_decode($c['config'], true) : null,
                    'exit_server_name' => $c['exit_server_id'] ? ($exitNameById[$c['exit_server_id']] ?? null) : null,
                ];
            }, Connection::all()),
            'exit_servers' => array_map(static fn (array $es) => [
                'name' => $es['name'],
                'endpoint_host' => $es['endpoint_host'],
                'endpoint_port' => (int) $es['endpoint_port'],
                'protocol' => $es['protocol'] ?? 'amneziawg',
                'status' => $es['status'],
                'amnezia_params' => $es['amnezia_params'] !== null ? json_decode($es['amnezia_params'], true) : null,
            ], $exitServers),
            'server_sets' => array_map(static function (array $set) use ($serverNameById) {
                return [
                    'name' => $set['name'],
                    'description' => $set['description'],
                    'strategy' => $set['strategy'],
                    'enabled' => (bool) $set['enabled'],
                    'tags' => json_decode($set['tags'] ?? '[]', true) ?: [],
                    'members' => array_map(static fn (array $m) => [
                        'server_name' => $serverNameById[$m['server_id']] ?? null,
                        'priority' => (int) $m['priority'],
                    ], $set['members']),
                ];
            }, ServerSet::all()),
        ];
    }

    /**
     * @return array{created: array<string,int>, skipped: array<string,int>, notes: string[]}
     */
    public static function import(array $data): array
    {
        if (!isset($data['format_version'], $data['servers'], $data['connections'], $data['exit_servers'], $data['server_sets'])) {
            throw new \InvalidArgumentException('Файл не похож на экспорт инфраструктуры этой панели (нет ожидаемых полей).');
        }

        $created = ['servers' => 0, 'connections' => 0, 'exit_servers' => 0, 'server_sets' => 0];
        $skipped = ['servers' => 0, 'connections' => 0, 'exit_servers' => 0, 'server_sets' => 0];
        $notes = [];

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            // --- servers: self-узел не переносится (на целевой панели уже свой) ---
            $existingServers = Server::all();
            $nameToServerId = [];
            foreach ($existingServers as $s) {
                if (!isset($nameToServerId[$s['name']])) {
                    $nameToServerId[$s['name']] = (int) $s['id'];
                }
            }
            $selfServer = Server::self();

            foreach ($data['servers'] as $row) {
                if (!empty($row['is_self'])) {
                    $notes[] = "Сервер «{$row['name']}» — self-узел исходной панели, пропущен (на этой панели уже есть свой).";
                    continue;
                }
                if (isset($nameToServerId[$row['name']])) {
                    $skipped['servers']++;
                    continue;
                }
                $id = Server::create([
                    'name' => $row['name'],
                    'role' => in_array($row['role'], Server::ROLES, true) ? $row['role'] : 'generic',
                    'host' => $row['host'] ?? '',
                    'ssh_port' => $row['ssh_port'] ?? 22,
                    'ssh_user' => $row['ssh_user'] ?? 'root',
                    'country' => $row['country'] ?? null,
                    'region' => $row['region'] ?? null,
                    'description' => $row['description'] ?? null,
                    'tags' => $row['tags'] ?? [],
                    'enabled' => $row['enabled'] ?? true,
                    'position_x' => $row['position_x'] ?? 0,
                    'position_y' => $row['position_y'] ?? 0,
                ]);
                $nameToServerId[$row['name']] = $id;
                $created['servers']++;
            }

            // --- exit_servers: name UNIQUE в БД — проверяем заранее, чтобы не ловить PDOException ---
            $existingExitNames = array_column(ExitServer::all(), 'name');
            $nameToExitId = [];
            foreach (ExitServer::all() as $es) {
                $nameToExitId[$es['name']] = (int) $es['id'];
            }

            foreach ($data['exit_servers'] as $row) {
                if (in_array($row['name'], $existingExitNames, true)) {
                    $skipped['exit_servers']++;
                    continue;
                }
                $id = ExitServer::create([
                    'name' => $row['name'],
                    'endpoint_host' => $row['endpoint_host'] ?? '',
                    'endpoint_port' => $row['endpoint_port'] ?? 51820,
                    // ExitServer::PROTOCOLS неполон (нет hysteria2/tuic/trojan) —
                    // сверяемся с Connection::EXECUTABLE_TYPES, реальным
                    // источником правды для того, что умеет SingboxConfigBuilder.
                    'protocol' => in_array($row['protocol'] ?? '', Connection::EXECUTABLE_TYPES, true) ? $row['protocol'] : 'amneziawg',
                    'status' => $row['status'] ?? 'active',
                    'amnezia_params' => $row['amnezia_params'] !== null ? json_encode($row['amnezia_params'], JSON_UNESCAPED_SLASHES) : null,
                ]);
                $nameToExitId[$row['name']] = $id;
                $existingExitNames[] = $row['name'];
                $created['exit_servers']++;
                $notes[] = "Exit-сервер «{$row['name']}» создан без ключей (секреты не экспортируются) — настройте через «Установить и настроить».";
            }

            // --- connections: резолвим source/target/exit по имени; self исходной панели -> self этой панели ---
            foreach ($data['connections'] as $row) {
                $sourceId = self::resolveServerRef($row['source_name'], $data['servers'], $nameToServerId, $selfServer);
                $targetId = self::resolveServerRef($row['target_name'], $data['servers'], $nameToServerId, $selfServer);
                if ($sourceId === null || $targetId === null) {
                    $skipped['connections']++;
                    continue;
                }
                $exitId = $row['exit_server_name'] ? ($nameToExitId[$row['exit_server_name']] ?? null) : null;
                try {
                    Connection::create($sourceId, $targetId, $row['type'], $exitId, $row['config'] ?? null, $row['label'] ?? null);
                    $created['connections']++;
                } catch (\InvalidArgumentException) {
                    $skipped['connections']++;
                }
            }

            // --- server_sets: name UNIQUE — пропускаем целиком при конфликте (без частичного слияния участников) ---
            $existingSetNames = array_column(ServerSet::all(), 'name');
            foreach ($data['server_sets'] as $row) {
                if (in_array($row['name'], $existingSetNames, true)) {
                    $skipped['server_sets']++;
                    continue;
                }
                $setId = ServerSet::create([
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'strategy' => in_array($row['strategy'] ?? '', ServerSet::STRATEGIES, true) ? $row['strategy'] : 'manual',
                    'enabled' => $row['enabled'] ?? true,
                    'tags' => $row['tags'] ?? [],
                ]);
                foreach ($row['members'] ?? [] as $m) {
                    $memberServerId = $m['server_name'] ? ($nameToServerId[$m['server_name']] ?? null) : null;
                    if ($memberServerId !== null) {
                        ServerSet::addMember($setId, $memberServerId, (int) ($m['priority'] ?? 0));
                    }
                }
                $created['server_sets']++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }

    /** @param array<int,array{name:string,is_self:bool}> $exportedServers */
    private static function resolveServerRef(string $name, array $exportedServers, array $nameToServerId, ?array $selfServer): ?int
    {
        foreach ($exportedServers as $s) {
            if ($s['name'] === $name && !empty($s['is_self'])) {
                return $selfServer ? (int) $selfServer['id'] : null;
            }
        }
        return $nameToServerId[$name] ?? null;
    }
}
