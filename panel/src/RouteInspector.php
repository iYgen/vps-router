<?php

namespace App;

use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\ServerSet;

/**
 * «Инспектор маршрута»: по домену или IP показывает, под какое правило и через
 * какой выход пойдёт трафик. Повторяет порядок и семантику правил (первое
 * совпадение выигрывает), кроме geosite — их принадлежность нельзя проверить
 * офлайн (список категории у sing-box, не у нас), поэтому geosite-правила
 * выводятся отдельно как «возможные».
 */
class RouteInspector
{
    /**
     * @return array{
     *   query:string, is_ip:bool, match:string, group:?string, exit:?string,
     *   via:?string, geosite_candidates:array<int,array{group:string,exit:string,geosite:string}>
     * }
     */
    public static function inspect(string $query, ?int $routerId, bool $includeNull): array
    {
        $q = strtolower(trim($query));
        $isIp = $q !== '' && filter_var($q, FILTER_VALIDATE_IP) !== false;
        $geositeCandidates = [];

        foreach (RuleGroup::all($routerId, $includeNull) as $g) {
            if (!$g['enabled']) {
                continue;
            }
            $exit = self::exitLabel($g);
            foreach (Rule::forGroup((int) $g['id']) as $r) {
                $type = $r['type'];
                $val = strtolower(trim((string) $r['value']));
                if ($val === '') {
                    continue;
                }

                if ($type === 'geosite') {
                    $geositeCandidates[] = ['group' => $g['name'], 'exit' => $exit, 'geosite' => $val];
                    continue;
                }

                $hit = false;
                if (!$isIp) {
                    if ($type === 'domain_full') {
                        $hit = $q === $val;
                    } elseif ($type === 'domain_suffix') {
                        $hit = $q === $val || str_ends_with($q, '.' . $val);
                    } elseif ($type === 'domain_keyword') {
                        $hit = str_contains($q, $val);
                    }
                } elseif ($type === 'ip_cidr') {
                    $hit = self::ipInCidr($q, (string) $r['value']);
                }

                if ($hit) {
                    return [
                        'query' => $query, 'is_ip' => $isIp, 'match' => 'rule',
                        'group' => $g['name'], 'exit' => $exit,
                        'via' => $type . ': ' . $r['value'],
                        'geosite_candidates' => $geositeCandidates,
                    ];
                }
            }
        }

        return [
            'query' => $query, 'is_ip' => $isIp, 'match' => 'direct',
            'group' => null, 'exit' => null, 'via' => null,
            'geosite_candidates' => $geositeCandidates,
        ];
    }

    /** Имя выхода группы: exit-сервер, набор или «напрямую». */
    private static function exitLabel(array $g): string
    {
        $exitId = null;
        if (!empty($g['server_set_id'])) {
            $exitId = ServerSet::resolveExitServerId((int) $g['server_set_id']);
        } elseif (!empty($g['exit_server_id'])) {
            $exitId = (int) $g['exit_server_id'];
        }
        if ($exitId) {
            $es = ExitServer::find($exitId);
            if ($es) {
                return $es['name'];
            }
        }
        return t('traffic.direct');
    }

    /** IPv4/IPv6-принадлежность адреса CIDR-подсети (или точное совпадение). */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false; // разные семейства (v4 vs v6) или мусор
        }
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes > 0 && strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }
}
