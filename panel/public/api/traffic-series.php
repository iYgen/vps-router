<?php

// Временные ряды трафика для графиков на странице «Трафик» (модуль feature-traffic).
// Период задаётся from/to (календарь) или days; гранулярность — gran (day/week/month/year)
// либо авто по длине периода.

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Http;
use App\Modules\ModuleManager;
use App\TrafficSeries;

Http::guard(['GET']);
Auth::requireLogin();

if (!ModuleManager::featureActive('traffic-stats')) {
    Http::error('Модуль «Дополнительная статистика» не активен', 403);
}

function tsValidDate(?string $d): ?string
{
    $d = trim((string) $d);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
}

try {
    $from = tsValidDate($_GET['from'] ?? null);
    $to = tsValidDate($_GET['to'] ?? null);

    if ($from === null || $to === null) {
        // Режим «Всё» или по числу дней.
        if (($_GET['days'] ?? '') === 'all') {
            $from = TrafficSeries::earliestDay();
            $to = gmdate('Y-m-d');
        } else {
            [$from, $to] = TrafficSeries::rangeForDays((int) ($_GET['days'] ?? 30));
        }
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    // Ограничиваем длину периода.
    $span = (int) floor((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400) + 1;
    if ($span > TrafficSeries::MAX_DAYS) {
        $from = gmdate('Y-m-d', strtotime($to . ' UTC') - (TrafficSeries::MAX_DAYS - 1) * 86400);
        $span = TrafficSeries::MAX_DAYS;
    }

    $gran = (string) ($_GET['gran'] ?? '');
    if (!in_array($gran, TrafficSeries::GRANULARITIES, true)) {
        $gran = TrafficSeries::granularityFor($span);
    }

    $series = TrafficSeries::serverSeries($from, $to, $gran);
    Http::json([
        'from' => $from,
        'to' => $to,
        'granularity' => $gran,
        'labels' => $series['labels'],
        'servers' => $series['servers'],
        'totals' => $series['totals'],
        'hosts' => TrafficSeries::topHosts($from, $to, (int) ($_GET['top'] ?? 15)),
    ]);
} catch (\Throwable $e) {
    Http::error('Внутренняя ошибка: ' . $e->getMessage(), 500);
}
