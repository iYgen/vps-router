<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\Client;
use App\Models\ExitServer;
use App\TrafficCollector;
use App\View;

Auth::requireLogin();

// Вкладка «Трафик» — модуль feature-traffic; при выключении страница закрыта.
if (!\App\Modules\ModuleManager::featureActive('traffic-stats')) {
    header('Location: /dashboard.php');
    exit;
}

function trFmt(int $n): string
{
    if ($n < 1024) {
        return $n . ' Б';
    }
    $units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    $i = -1;
    $v = (float) $n;
    do {
        $v /= 1024;
        $i++;
    } while ($v >= 1024 && $i < count($units) - 1);
    return ($v >= 100 ? number_format($v, 0, ',', ' ') : number_format($v, 1, ',', ' ')) . ' ' . $units[$i];
}

$totals = TrafficCollector::deviceTotals();

// Имена/типы устройств: client_key 'c<id>' -> Client.
$names = [];
$types = [];
foreach (Client::all() as $c) {
    $names['c' . $c['id']] = $c['name'];
    $types['c' . $c['id']] = $c['device_type'] ?? null;
}

// Показываем только известные устройства (c<id>); ip:* (несопоставленные) не шумим.
$rows = [];
$sumToday = ['up' => 0, 'down' => 0];
$sum30 = ['up' => 0, 'down' => 0];
$sumTotal = ['up' => 0, 'down' => 0];
foreach ($totals as $key => $t) {
    if (!isset($names[$key])) {
        continue;
    }
    $rowTotal = ($t['total_up'] ?? 0) + ($t['total_down'] ?? 0);
    $rows[] = [
        'name' => $names[$key],
        'type' => $types[$key] ?? null,
        'online' => !empty($t['online']),
        'today_up' => $t['today_up'] ?? 0, 'today_down' => $t['today_down'] ?? 0,
        'd30_up' => $t['d30_up'] ?? 0, 'd30_down' => $t['d30_down'] ?? 0,
        'total_up' => $t['total_up'] ?? 0, 'total_down' => $t['total_down'] ?? 0,
        'total' => $rowTotal,
    ];
    $sumToday['up'] += $t['today_up'] ?? 0;
    $sumToday['down'] += $t['today_down'] ?? 0;
    $sum30['up'] += $t['d30_up'] ?? 0;
    $sum30['down'] += $t['d30_down'] ?? 0;
    $sumTotal['up'] += $t['total_up'] ?? 0;
    $sumTotal['down'] += $t['total_down'] ?? 0;
}
usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
$maxTotal = $rows ? max(array_column($rows, 'total')) : 0;

// По выходным серверам за 24ч (summary window) — tag -> имя exit.
$exitNames = ['direct-rf' => t('traffic.direct')];
foreach (ExitServer::all() as $es) {
    $exitNames['exit-' . $es['id']] = $es['name'];
}
$byExit = [];
try {
    $summary = TrafficCollector::summary(1440);
    $byExit = $summary['by_outbound'] ?? [];
} catch (\Throwable $e) {
    $byExit = [];
}
uasort($byExit, fn($a, $b) => (($b['up'] ?? 0) + ($b['down'] ?? 0)) <=> (($a['up'] ?? 0) + ($a['down'] ?? 0)));
$byExitMax = 0;
foreach ($byExit as $v) {
    $byExitMax = max($byExitMax, ($v['up'] ?? 0) + ($v['down'] ?? 0));
}

$typeIcons = [
    'router' => 'M3 13h18v7H3zM7 17h.01M11 17h2M12 8a7 7 0 0 1 7-7',
    'phone' => 'M8 3h8v18H8zM11 18h2',
    'tablet' => 'M6 3h12v18H6zM10 18h4',
    'computer' => 'M3 5h18v11H3zM2 20h20',
];

View::header(t('traffic.title'), t('traffic.subtitle'));
?>
<style>
.tr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;padding:16px 20px 0}
.tr-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 16px}
.tr-card .lbl{color:var(--text-muted);font-size:12px;margin-bottom:6px}
.tr-card .val{font-size:15px;font-weight:600}
.tr-card .val .u{color:var(--text-muted);font-weight:400;font-size:13px;margin-left:2px}
.tr-sec{padding:18px 20px}
.tr-sec h2{font-size:15px;margin:0 0 10px}
.tr-bar{height:8px;border-radius:4px;background:var(--surface2);overflow:hidden;min-width:60px}
.tr-bar>i{display:block;height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-purple));border-radius:4px}
.tr-tbl{width:100%;border-collapse:collapse}
.tr-tbl th{text-align:left;color:var(--text-muted);font-weight:500;font-size:12px;padding:6px 10px;border-bottom:1px solid var(--border)}
.tr-tbl td{padding:8px 10px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
.tr-dev{display:flex;align-items:center;gap:9px}
.tr-ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.05);border:1px solid var(--border);flex-shrink:0}
.tr-dot{width:7px;height:7px;border-radius:50%;display:inline-block;margin-left:6px}
.tr-dot.on{background:var(--success)}.tr-dot.off{background:var(--text-disabled)}
.tr-dl{color:var(--text)}.tr-ul{color:var(--text-muted)}
.tr-exit{display:flex;align-items:center;gap:10px;padding:6px 0}
.tr-exit .nm{min-width:150px;font-size:13px}
.tr-exit .tr-bar{flex:1}
.tr-ranges{display:flex;gap:6px;margin-left:auto}
.tr-ranges button{padding:5px 12px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text-secondary);font:inherit;font-size:12.5px;cursor:pointer}
.tr-ranges button.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.tr-chart{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:14px}
.tr-chart h3{font-size:14px;margin:0 0 10px}
@media(max-width:640px){.tr-hide{display:none}}
</style>

<div class="tr-cards">
  <div class="tr-card"><div class="lbl"><?= htmlspecialchars(t('traffic.total')) ?></div>
    <div class="val">↓<?= trFmt($sumTotal['down']) ?> <span class="u">↑<?= trFmt($sumTotal['up']) ?></span></div></div>
  <div class="tr-card"><div class="lbl"><?= htmlspecialchars(t('traffic.today')) ?></div>
    <div class="val">↓<?= trFmt($sumToday['down']) ?> <span class="u">↑<?= trFmt($sumToday['up']) ?></span></div></div>
  <div class="tr-card"><div class="lbl"><?= htmlspecialchars(t('traffic.d30')) ?></div>
    <div class="val">↓<?= trFmt($sum30['down']) ?> <span class="u">↑<?= trFmt($sum30['up']) ?></span></div></div>
</div>

<div class="tr-sec" id="tr-charts">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
    <div class="tr-ranges" style="margin-left:0">
      <button data-range="7">7<?= htmlspecialchars(t('traffic.d_short')) ?></button>
      <button data-range="30" class="active">30<?= htmlspecialchars(t('traffic.d_short')) ?></button>
      <button data-range="90">90<?= htmlspecialchars(t('traffic.d_short')) ?></button>
      <button data-range="365">1<?= htmlspecialchars(t('traffic.y_short')) ?></button>
      <button data-range="730">2<?= htmlspecialchars(t('traffic.y_short')) ?></button>
      <button data-range="all"><?= htmlspecialchars(t('traffic.all')) ?></button>
    </div>
    <div style="display:flex;align-items:center;gap:6px;margin-left:auto;flex-wrap:wrap">
      <input type="date" id="tr-from" style="padding:5px 8px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text);font:inherit;font-size:12.5px">
      <span class="muted">—</span>
      <input type="date" id="tr-to" style="padding:5px 8px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text);font:inherit;font-size:12.5px">
      <select id="tr-gran" style="padding:5px 8px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text);font:inherit;font-size:12.5px">
        <option value=""><?= htmlspecialchars(t('traffic.gran_auto')) ?></option>
        <option value="day"><?= htmlspecialchars(t('traffic.gran_day')) ?></option>
        <option value="week"><?= htmlspecialchars(t('traffic.gran_week')) ?></option>
        <option value="month"><?= htmlspecialchars(t('traffic.gran_month')) ?></option>
        <option value="year"><?= htmlspecialchars(t('traffic.gran_year')) ?></option>
      </select>
    </div>
  </div>
  <div class="tr-chart"><h3 id="tr-totals-title"><?= htmlspecialchars(sprintf(t('traffic.chart_totals'), t('traffic.per_day'))) ?></h3><div id="tr-chart-totals"></div></div>
  <div class="tr-chart"><h3><?= htmlspecialchars(t('traffic.chart_servers')) ?></h3><div id="tr-chart-servers"></div></div>
  <div class="tr-chart"><h3><?= htmlspecialchars(t('traffic.chart_hosts')) ?></h3><div id="tr-hosts"></div></div>
</div>

<?php if ($byExit): ?>
<div class="tr-sec">
  <h2><?= htmlspecialchars(t('traffic.by_exit')) ?></h2>
  <?php foreach ($byExit as $tag => $v): $val = ($v['up'] ?? 0) + ($v['down'] ?? 0); ?>
    <div class="tr-exit">
      <span class="nm"><?= htmlspecialchars($exitNames[$tag] ?? $tag) ?></span>
      <span class="tr-bar"><i style="width:<?= $byExitMax ? round($val / $byExitMax * 100) : 0 ?>%"></i></span>
      <span style="min-width:150px;text-align:right;color:var(--text-muted);font-size:12px">↓<?= trFmt($v['down'] ?? 0) ?> ↑<?= trFmt($v['up'] ?? 0) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tr-sec">
  <h2><?= htmlspecialchars(t('traffic.devices')) ?></h2>
  <?php if (!$rows): ?>
    <p class="muted"><?= htmlspecialchars(t('traffic.empty')) ?></p>
  <?php else: ?>
  <table class="tr-tbl">
    <thead><tr>
      <th><?= htmlspecialchars(t('traffic.col_device')) ?></th>
      <th class="tr-hide"><?= htmlspecialchars(t('traffic.col_today')) ?></th>
      <th class="tr-hide"><?= htmlspecialchars(t('traffic.col_d30')) ?></th>
      <th><?= htmlspecialchars(t('traffic.col_total')) ?></th>
      <th style="width:22%"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $p = $r['type'] && isset($typeIcons[$r['type']]) ? $typeIcons[$r['type']] : $typeIcons['phone']; ?>
      <tr>
        <td>
          <span class="tr-dev">
            <span class="tr-ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $p ?>"/></svg></span>
            <span><?= htmlspecialchars($r['name']) ?><span class="tr-dot <?= $r['online'] ? 'on' : 'off' ?>" title="<?= $r['online'] ? 'online' : 'offline' ?>"></span></span>
          </span>
        </td>
        <td class="tr-hide"><span class="tr-dl">↓<?= trFmt($r['today_down']) ?></span> <span class="tr-ul">↑<?= trFmt($r['today_up']) ?></span></td>
        <td class="tr-hide"><span class="tr-dl">↓<?= trFmt($r['d30_down']) ?></span> <span class="tr-ul">↑<?= trFmt($r['d30_up']) ?></span></td>
        <td><span class="tr-dl">↓<?= trFmt($r['total_down']) ?></span> <span class="tr-ul">↑<?= trFmt($r['total_up']) ?></span></td>
        <td><span class="tr-bar"><i style="width:<?= $maxTotal ? round($r['total'] / $maxTotal * 100) : 0 ?>%"></i></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script src="/assets/js/traffic-charts.js?v=<?= filemtime(__DIR__ . '/assets/js/traffic-charts.js') ?>"></script>
<?php View::footer(); ?>
