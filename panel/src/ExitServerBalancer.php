<?php

namespace App;

use App\Models\ExitServer;
use App\Models\ExitServerLoad;
use App\Models\ExitServerPeer;

/**
 * Выбор наименее загруженного exit-сервера из пула взаимозаменяемых —
 * только для НОВЫХ устройств (см. panel plan "Балансировка нагрузки"), уже
 * подключённые пиры никогда не переезжают между серверами автоматически.
 */
class ExitServerBalancer
{
    /** Замер старше этого — считаем неактуальным и не участвующим в сравнении по метрикам. */
    private const FRESH_WINDOW_MINUTES = 30;

    public static function pickLeastLoaded(string $poolLabel, string $protocol = 'wireguard'): ?array
    {
        $candidates = ExitServer::activeInPool($poolLabel, $protocol);
        if (empty($candidates)) {
            return null;
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $fresh = [];
        $stale = [];
        foreach ($candidates as $server) {
            $load = ExitServerLoad::latestFor((int) $server['id']);
            if ($load !== null && self::isFresh($load['sample_at'])) {
                $fresh[] = ['server' => $server, 'load' => $load];
            } else {
                $stale[] = $server;
            }
        }

        usort($fresh, static function (array $a, array $b): int {
            return [$a['load']['cpu_load_1min'] ?? PHP_FLOAT_MAX, $a['load']['mem_used_percent'] ?? PHP_INT_MAX, $a['load']['net_bytes_per_sec'] ?? PHP_FLOAT_MAX]
                <=> [$b['load']['cpu_load_1min'] ?? PHP_FLOAT_MAX, $b['load']['mem_used_percent'] ?? PHP_INT_MAX, $b['load']['net_bytes_per_sec'] ?? PHP_FLOAT_MAX];
        });

        if (!empty($fresh)) {
            return $fresh[0]['server'];
        }

        // Ни у кого нет свежих метрик (например пул только что создан, cron
        // ещё не успел отработать) — bootstrap round-robin по числу уже
        // существующих пиров, чтобы новый сервер в пуле не простаивал вечно.
        usort($stale, static function (array $a, array $b): int {
            return count(ExitServerPeer::forExitServer((int) $a['id'])) <=> count(ExitServerPeer::forExitServer((int) $b['id']));
        });

        return $stale[0];
    }

    private static function isFresh(string $sampleAt): bool
    {
        $ts = strtotime($sampleAt . ' UTC');
        if ($ts === false) {
            return false;
        }
        return $ts >= time() - self::FRESH_WINDOW_MINUTES * 60;
    }
}
