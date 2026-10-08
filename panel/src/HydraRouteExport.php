<?php

namespace App;

use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\ServerSet;

/**
 * Экспорт маршрутов в формат HydraRoute Neo (демон на самом Keenetic): файлы
 * `domain.conf` (строки `<domain>/<Policy>` и `geosite:<tag>/<Policy>`) и `ip.list`
 * (секции `/<Policy>` + CIDR). Пользователь HydraRoute импортирует наши наборы и
 * получает устойчивую по-доменную маршрутизацию на роутере (ipset решает проблему
 * меняющихся CDN-IP — то, что статический `.bat` не умеет). Мы только генерируем
 * текст, ничего не применяем.
 *
 * Политика = выходной сервер (exit): его имя приводится к безопасному виду
 * (латиница/цифры/._-), т.к. HydraRoute использует имя политики как идентификатор.
 */
class HydraRouteExport
{
    /**
     * Собирает назначения по политикам (exit).
     * @return array<string,array{domains:string[],geosite:string[],ips:string[]}>
     */
    public static function byPolicy(?int $routerId, bool $includeNull): array
    {
        $policies = [];
        foreach (RuleGroup::all($routerId, $includeNull) as $g) {
            if (!$g['enabled']) {
                continue;
            }
            $exitId = null;
            if (!empty($g['server_set_id'])) {
                $exitId = ServerSet::resolveExitServerId((int) $g['server_set_id']);
            } elseif (!empty($g['exit_server_id'])) {
                $exitId = (int) $g['exit_server_id'];
            }
            if (!$exitId) {
                continue; // направляется напрямую — HydraRoute-политика не нужна
            }
            $es = ExitServer::find($exitId);
            if (!$es) {
                continue;
            }
            $policy = self::policyName($es['name'], $exitId);
            $policies[$policy] ??= ['domains' => [], 'geosite' => [], 'ips' => []];

            foreach (Rule::forGroup((int) $g['id']) as $r) {
                $v = trim((string) $r['value']);
                if ($v === '') {
                    continue;
                }
                switch ($r['type']) {
                    case 'domain_full':
                    case 'domain_suffix':
                        $policies[$policy]['domains'][$v] = true;
                        break;
                    case 'domain_keyword':
                        // HydraRoute не знает keyword — пропускаем (нет эквивалента).
                        break;
                    case 'ip_cidr':
                        $policies[$policy]['ips'][$v] = true;
                        break;
                    case 'geosite':
                        $policies[$policy]['geosite'][$v] = true;
                        break;
                }
            }
        }
        // Массивы ключей + сортировка.
        foreach ($policies as $p => &$sets) {
            foreach (['domains', 'geosite', 'ips'] as $k) {
                $sets[$k] = array_keys($sets[$k]);
                sort($sets[$k]);
            }
        }
        return $policies;
    }

    /** Содержимое `domain.conf`. */
    public static function domainConf(?int $routerId, bool $includeNull): string
    {
        $lines = ['# domain.conf для HydraRoute Neo — сгенерировано vps_router'];
        foreach (self::byPolicy($routerId, $includeNull) as $policy => $sets) {
            foreach ($sets['geosite'] as $g) {
                $lines[] = "geosite:$g/$policy";
            }
            foreach ($sets['domains'] as $d) {
                $lines[] = "$d/$policy";
            }
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    /** Содержимое `ip.list`. */
    public static function ipList(?int $routerId, bool $includeNull): string
    {
        $lines = ['# ip.list для HydraRoute Neo — сгенерировано vps_router'];
        foreach (self::byPolicy($routerId, $includeNull) as $policy => $sets) {
            if (!$sets['ips']) {
                continue;
            }
            $lines[] = "/$policy";
            foreach ($sets['ips'] as $ip) {
                $lines[] = $ip;
            }
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    /** Имя политики HydraRoute из имени exit: безопасные символы, непусто. */
    public static function policyName(string $exitName, int $exitId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($exitName));
        $safe = trim((string) $safe, '_');
        return $safe !== '' ? $safe : ('exit' . $exitId);
    }
}
