<?php
// Периодический замер CPU/RAM/трафика exit-серверов, состоящих в пуле
// балансировки (exit_servers.pool_label задан) — без этого App\ExitServerBalancer
// не может выбирать наименее загруженный сервер при добавлении нового
// устройства. Серверы вне пула не трогаем — незачем лишний раз ходить по SSH.
//
// Запуск по cron раз в 5 минут:
//   */5 * * * * php /var/www/panel/bin/collect_exit_load.php >/dev/null 2>&1

require __DIR__ . '/../src/bootstrap.php';

use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\ExitServerLoad;
use App\Models\Server;
use App\Ssh;

$pooled = array_filter(ExitServer::all(), static fn (array $es) => !empty($es['pool_label']) && $es['status'] === 'active');

foreach ($pooled as $es) {
    $exitServerId = (int) $es['id'];

    $conn = Connection::forExitServer($exitServerId);
    if (!$conn) {
        ExitServerLoad::upsert($exitServerId, ['raw_error' => 'Не найдена связь, ссылающаяся на этот exit-сервер'] + array_fill_keys(['cpu_load_1min', 'mem_used_percent', 'net_bytes_total', 'net_bytes_per_sec'], null));
        continue;
    }
    $target = Server::find((int) $conn['target_server_id']);
    $privateKey = $target ? Server::sshPrivateKey((int) $target['id']) : null;
    if (!$target || !$privateKey) {
        ExitServerLoad::upsert($exitServerId, ['raw_error' => 'Нет сохранённого SSH-ключа для узла этого exit-сервера'] + array_fill_keys(['cpu_load_1min', 'mem_used_percent', 'net_bytes_total', 'net_bytes_per_sec'], null));
        continue;
    }

    $metrics = Ssh::fetchMetrics($target['host'], (int) $target['ssh_port'], $target['ssh_user'], $privateKey);
    $network = Ssh::fetchNetworkTotals($target['host'], (int) $target['ssh_port'], $target['ssh_user'], $privateKey);

    if (!$metrics['ok'] && !$network['ok']) {
        ExitServerLoad::upsert($exitServerId, [
            'cpu_load_1min' => null,
            'mem_used_percent' => null,
            'net_bytes_total' => null,
            'net_bytes_per_sec' => null,
            'raw_error' => $metrics['raw_error'] ?? $network['raw_error'] ?? 'SSH-опрос не удался',
        ]);
        echo "{$es['name']}: ошибка — " . ($metrics['raw_error'] ?? $network['raw_error']) . "\n";
        continue;
    }

    $previous = ExitServerLoad::latestFor($exitServerId);
    $bytesPerSec = null;
    if ($network['ok'] && $previous && $previous['net_bytes_total'] !== null && $previous['sample_at']) {
        $prevTs = strtotime($previous['sample_at'] . ' UTC');
        $elapsed = $prevTs !== false ? time() - $prevTs : null;
        // Не старше 30 минут — иначе дельта бессмысленна (сервер мог быть
        // перезагружен, счётчики интерфейсов сброшены).
        if ($elapsed !== null && $elapsed > 0 && $elapsed <= 1800 && $network['bytes_total'] >= $previous['net_bytes_total']) {
            $bytesPerSec = ($network['bytes_total'] - $previous['net_bytes_total']) / $elapsed;
        }
    }

    $cpuLoad = $metrics['load']['1min'] ?? null;
    $memPercent = $metrics['mem']['used_percent'] ?? null;

    ExitServerLoad::upsert($exitServerId, [
        'cpu_load_1min' => $cpuLoad,
        'mem_used_percent' => $memPercent,
        'net_bytes_total' => $network['bytes_total'] ?? null,
        'net_bytes_per_sec' => $bytesPerSec,
        'raw_error' => null,
    ]);

    echo "{$es['name']}: load=" . ($cpuLoad ?? 'n/a') . ' mem=' . ($memPercent ?? 'n/a') . '% net=' . ($bytesPerSec !== null ? round($bytesPerSec / 1024, 1) . 'KB/s' : 'n/a') . "\n";
}
