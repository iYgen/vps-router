<?php

namespace App;

use App\Keenetic\KeeneticPolicyRenderer;
use App\Keenetic\RciClient;
use App\Models\ExitServerPeer;
use App\Models\PolicyDevice;
use App\Models\PolicyVersion;

/**
 * sync -> diff -> apply -> verify для одного Keenetic-устройства (см.
 * docs/infrastructure-ui.md раздел 15). Никогда не пишет sync_status='ok'
 * без реального verify-шага, подтвердившего совпадение с опубликованной
 * версией — раздел 15 явно запрещает "оптимистичные" статусы.
 *
 * Статус по факту живых тестов против реального роутера (2026-09-23):
 * - auth (challenge/response) — работает, подтверждено.
 * - GET /rci/ip/route, /rci/show/interface, /rci/show/system — работают,
 *   поля подтверждены (`comment`, не `description`; `network`+`mask`,
 *   не CIDR-строка).
 * - POST добавления ip_cidr-маршрута (network+mask) — работает, подтверждено.
 * - POST добавления domain-маршрута (host) — НЕ работает (отклоняется
 *   роутером), правильный способ не найден — см. applyRoute().
 * - Удаление одной записи по index — ОПАСНО, в реальности снесло всю
 *   таблицу маршрутов пользователя; полностью отключено — см. removeRoute().
 */
class KeeneticSyncService
{
    private const DESCRIPTION_PREFIX = 'panel:';

    public function sync(int $deviceId): array
    {
        $device = PolicyDevice::find($deviceId);
        if (!$device) {
            throw new \InvalidArgumentException('Устройство не найдено');
        }
        if ($device['adapter_type'] !== 'keenetic') {
            throw new \InvalidArgumentException('Синхронизация поддерживается только для adapter_type=keenetic в этой итерации');
        }
        if (empty($device['profile_id'])) {
            throw new \InvalidArgumentException('У устройства не назначен профиль');
        }
        if (empty($device['exit_server_peer_id'])) {
            throw new \InvalidArgumentException('У устройства не назначен WG-туннель (exit_server_peer_id)');
        }
        if (empty($device['rci_host']) || empty($device['rci_username'])) {
            throw new \InvalidArgumentException('Не заполнены параметры подключения к Keenetic (host/логин)');
        }

        $peer = ExitServerPeer::find((int) $device['exit_server_peer_id']);
        if (!$peer) {
            throw new \InvalidArgumentException('WG-пир устройства не найден (возможно, удалён)');
        }

        $version = PolicyVersion::latest((int) $device['profile_id']);
        if (!$version || !$version['published_at']) {
            throw new \InvalidArgumentException('У профиля ещё нет опубликованной версии политики');
        }
        $policy = json_decode($version['policy_json'], true);

        PolicyDevice::setSyncResult($deviceId, 'syncing', null, 'Синхронизация начата…');

        try {
            $client = new RciClient(
                $device['rci_host'],
                (int) $device['rci_port'],
                $device['rci_scheme'],
                $device['rci_username'],
                $device['rci_password_enc'] ?? ''
            );
            $client->authenticate();

            $interfaceName = $device['wg_interface_name'] ?: $this->detectInterfaceName($client, $peer);
            if (!$interfaceName) {
                throw new \RuntimeException('Не удалось определить имя WireGuard-подключения на Keenetic — укажите его вручную в настройках устройства (wg_interface_name)');
            }

            $rendered = (new KeeneticPolicyRenderer())->render($policy, (int) $peer['exit_server_id'], $interfaceName);

            $current = $this->currentPanelRoutes($client);
            $desired = $rendered['routes'];

            $toRemove = $this->routesMissingFrom($current, $desired);
            $toAdd = $this->routesMissingFrom($desired, $current);

            // 2026-09-23: живой тест на реальном роутере показал, что
            // {"index": "...", "no": true} НЕ удаляет одну запись, а
            // выполняет полный сброс таблицы маршрутов ("cleared all
            // routes") — снесло всю рабочую конфигурацию пользователя.
            // Правильная семантика удаления по index пока не установлена.
            // Поэтому removeRoute() НЕ вызывается вообще — устаревшие
            // panel-маршруты только перечисляются в логе для ручного
            // удаления через веб-интерфейс Keenetic, пока это не будет
            // безопасно исследовано и явно протестировано заново.
            // Каждый route применяется независимо — ошибка на одном (например
            // домен-маршруты, см. applyRoute()) не должна блокировать
            // остальные (например уже проверенные вживую ip_cidr-маршруты).
            $applied = 0;
            $failedToApply = [];
            foreach ($toAdd as $route) {
                try {
                    $this->applyRoute($client, $route);
                    $applied++;
                } catch (\Throwable $e) {
                    $failedToApply[] = $route['value'] . ' (' . $e->getMessage() . ')';
                }
            }

            $verifyOk = $this->verify($client, $desired);
            $log = sprintf(
                "Применено: +%d/%d правил. Пропущено (см. warnings): %d. Verify: %s",
                $applied,
                count($toAdd),
                count($rendered['warnings']),
                $verifyOk ? 'OK' : 'НЕ ПОДТВЕРЖДЁН'
            );
            if ($failedToApply) {
                $log .= "\n! Не удалось применить (см. описание ошибки): " . implode('; ', $failedToApply);
            }
            if ($toRemove) {
                $log .= "\n! Устарело и НЕ удалено автоматически (удаление маршрутов пока отключено из-за инцидента 2026-09-23) — уберите вручную в Keenetic: "
                    . implode(', ', array_column($toRemove, 'value'));
            }
            if ($rendered['warnings']) {
                $log .= "\n" . implode("\n", $rendered['warnings']);
            }

            if (!$verifyOk) {
                PolicyDevice::setSyncResult($deviceId, 'failed', null, $log);
                return ['ok' => false, 'log' => $log];
            }

            PolicyDevice::setSyncResult($deviceId, 'ok', (int) $version['version'], $log);
            return ['ok' => true, 'log' => $log];
        } catch (\Throwable $e) {
            PolicyDevice::setSyncResult($deviceId, 'failed', null, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Пытается опознать WG-интерфейс на Keenetic по публичному ключу пира
     * (совпадение Peer.PublicKey на роутере с exit-сервером, к которому мы
     * подключены). Формат ответа ip/interface — предположительный, первое,
     * что нужно свериться на реальном роутере.
     */
    private function detectInterfaceName(RciClient $client, array $peer): ?string
    {
        try {
            $interfaces = $client->get('/rci/show/interface');
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($interfaces)) {
            return null;
        }
        foreach ($interfaces as $name => $iface) {
            if (($iface['type'] ?? '') === 'Wireguard') {
                return is_string($name) ? $name : ($iface['id'] ?? null);
            }
        }
        return null;
    }

    /**
     * @return array<int,array{matcher_type:string,value:string,interface:string,description:string,index:?string}>
     *
     * ВАЖНО (подтверждено вживую 2026-09-23 против реального роутера):
     * поле называется `comment`, НЕ `description`; конфигурационный список —
     * `/rci/ip/route` (не `/rci/show/ip/route` — та отдаёт резолвнутую
     * runtime-таблицу маршрутизации ядра БЕЗ comment вообще, включая сотни
     * auto-развёрнутых записей на IP из уже настроенных DNS-маршрутов).
     * Каждая запись имеет уникальный `index` (hex-строка) — по нему, а не по
     * host/interface, роутер идентифицирует запись при удалении.
     */
    private function currentPanelRoutes(RciClient $client): array
    {
        try {
            $raw = $client->get('/rci/ip/route');
        } catch (\Throwable) {
            return [];
        }
        $routes = [];
        foreach ((array) $raw as $entry) {
            $comment = $entry['comment'] ?? '';
            if (!str_starts_with($comment, self::DESCRIPTION_PREFIX)) {
                continue;
            }
            $routes[] = [
                'matcher_type' => isset($entry['host']) ? 'domain_or_ip' : 'ip_cidr',
                'value' => $entry['host'] ?? $this->networkMaskToCidr($entry['network'] ?? '', $entry['mask'] ?? ''),
                'interface' => $entry['interface'] ?? '',
                'description' => $comment,
                'index' => $entry['index'] ?? null,
            ];
        }
        return $routes;
    }

    /**
     * Подтверждено вживую 2026-09-23 против реального роутера:
     * `network`+`mask` (CIDR) через `POST /rci/ {"ip":{"route":{...}}}`
     * РЕАЛЬНО РАБОТАЕТ (роутер ответил "added static route: ...").
     * `host` (домен/одиночный IP) в этом же теле стабильно отклоняется
     * ("no input", ошибка на уровне Command::Root, ещё до логики
     * маршрутизации) — хотя такие записи и видны в GET /rci/ip/route (они
     * там появляются как-то иначе, не через этот POST). Правильный способ
     * добавить ИМЕННО domain-маршрут через RCI пока не найден — не
     * подключать `host` заново без нового явного вживую подтверждённого
     * теста (см. инцидент того же дня с удалением). До тех пор domain_*
     * правила просто не поддерживаются этим методом.
     */
    private function applyRoute(RciClient $client, array $route): void
    {
        if ($route['matcher_type'] !== 'ip_cidr') {
            throw new \RuntimeException('Domain-маршруты через RCI пока не поддержаны (см. doc-комментарий applyRoute) — только ip_cidr');
        }
        [$network, $mask] = $this->cidrToNetworkMask($route['value']);
        $client->post('/rci/', ['ip' => ['route' => [
            'network' => $network,
            'mask' => $mask,
            'interface' => $route['interface'],
            'comment' => $route['description'],
        ]]]);
    }

    /**
     * ОТКЛЮЧЕНО после реального инцидента 2026-09-23: `{"index": "...",
     * "no": true}` на живом роутере не удалил одну запись, а ответил
     * `"message": "cleared all routes."` — снёс ВСЮ таблицу маршрутов
     * пользователя (включая ручные записи, не связанные с панелью).
     * Пользователь восстановил конфигурацию вручную. Правильная безопасная
     * семантика точечного удаления одной записи по `index` НЕ установлена.
     * Намеренно не вызывается из sync() (см. комментарий там) и бросает
     * исключение при прямом вызове, чтобы случайно не быть подключённой
     * заново без нового, отдельно подтверждённого вживую теста.
     */
    private function removeRoute(RciClient $client, array $route): never
    {
        throw new \RuntimeException('Удаление маршрутов через RCI отключено после инцидента 2026-09-23 — см. doc-комментарий removeRoute()');
    }

    /** @return array{0:string,1:string} [network, dotted-decimal mask] */
    private function cidrToNetworkMask(string $cidr): array
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, '32');
        $mask = long2ip(-1 << (32 - (int) $prefix));
        return [$network, $mask];
    }

    private function networkMaskToCidr(string $network, string $mask): string
    {
        if ($network === '' || $mask === '') {
            return '';
        }
        $prefix = 32 - (int) log((ip2long($mask) ^ 0xFFFFFFFF) + 1, 2);
        return "$network/$prefix";
    }

    private function verify(RciClient $client, array $desired): bool
    {
        $current = $this->currentPanelRoutes($client);
        $currentValues = array_map(fn($r) => $r['value'] . '|' . $r['interface'], $current);
        foreach ($desired as $route) {
            if (!in_array($route['value'] . '|' . $route['interface'], $currentValues, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return array elements of $from whose (value, interface) pair is absent from $in */
    private function routesMissingFrom(array $from, array $in): array
    {
        $inKeys = array_map(fn($r) => $r['value'] . '|' . $r['interface'], $in);
        return array_values(array_filter($from, fn($r) => !in_array($r['value'] . '|' . $r['interface'], $inKeys, true)));
    }
}
