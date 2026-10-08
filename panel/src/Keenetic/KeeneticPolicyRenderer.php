<?php

namespace App\Keenetic;

/**
 * Проецирует canonical policy (App\ClientPolicyBuilder::build()) на КОНКРЕТНОЕ
 * устройство: оставляет только правила, чей target (exit_server/server_set)
 * реально достижим через WG-туннель этого устройства (policy_devices.
 * exit_server_peer_id -> конкретный exit_server_id) — маршрут на ДРУГОЙ exit
 * через этот тоннель физически не пройдёт, поэтому такие правила пропускаются
 * с warning, а не молча игнорируются или ошибочно применяются.
 *
 * Формат самих RCI-объектов (route/policy) — по документированной схеме
 * Keenetic RCI (дерево команд CLI как JSON), НЕ подтверждён вживую на
 * момент написания — App\KeeneticSyncService::sync() логирует то, что
 * реально прислал роутер на verify-шаге, и это первое, что нужно сверить
 * при живом тесте (см. план в C:\Users\Lenovo\.claude\plans).
 */
class KeeneticPolicyRenderer
{
    /**
     * @param array $policy Результат ClientPolicyBuilder::build()['policy']
     * @param int   $deviceExitServerId exit_server_id, к которому реально привязан WG-туннель устройства
     * @param string $interfaceName Имя WireGuard-подключения на самом Keenetic (см. sync-шаг 1 — читается из живого конфига роутера)
     *
     * @return array{routes: array<int,array{matcher_type:string,value:string,description:string}>, warnings: string[]}
     */
    public function render(array $policy, int $deviceExitServerId, string $interfaceName): array
    {
        $warnings = [];
        $routes = [];

        foreach ($policy['rules'] as $rule) {
            $target = $rule['action']['target'] ?? '';
            $resolvedExitId = $this->resolveTargetExitServerId($target);

            if ($resolvedExitId === null) {
                $warnings[] = "Правило «{$rule['matcher']['value']}»: target «{$target}» — набор серверов, разрешение в конкретный exit здесь не делается (это задача рантайма входной VPS, не Keenetic) — пропущено для устройства.";
                continue;
            }
            if ($resolvedExitId !== $deviceExitServerId) {
                $warnings[] = "Правило «{$rule['matcher']['value']}» ведёт на exit-сервер #{$resolvedExitId}, а у этого устройства туннель к exit-серверу #{$deviceExitServerId} — недостижимо через этот тоннель, пропущено.";
                continue;
            }

            $routes[] = [
                'matcher_type' => $rule['matcher']['type'],
                'value' => $rule['matcher']['value'],
                'interface' => $interfaceName,
                'description' => 'panel:' . ($rule['source_route'] ?? ''),
            ];
        }

        return ['routes' => $routes, 'warnings' => $warnings];
    }

    /** target вида "exit_server:5" -> 5; "server_set:2" -> null (набор — не прямой exit, резолвится только рантаймом входного узла). */
    private function resolveTargetExitServerId(string $target): ?int
    {
        if (str_starts_with($target, 'exit_server:')) {
            return (int) substr($target, strlen('exit_server:'));
        }
        return null;
    }
}
