<?php

// Health-проба бесплатных exit'ов (source=free). У них нет SSH, поэтому обычный
// health_check_servers.php их не покрывает, а failover-набор «Free pool» должен
// уметь пропускать мёртвые ноды. Здесь — лёгкая проверка живости: TCP-connect
// к server:port для TCP-протоколов; UDP-протоколы (hysteria2/tuic) проверить
// так нельзя — доверяем ежечасному тестированию в самой подписке и держим их
// «здоровыми». Запускать по cron, например раз в 2-3 минуты:
//   */3 * * * *  panel  php82 /var/www/panel/bin/free_pool_health.php

require __DIR__ . '/../src/bootstrap.php';

use App\Models\ExitServer;

$tcpProtocols = ['vless', 'vmess', 'trojan', 'shadowsocks'];
$checked = 0;
$up = 0;

foreach (ExitServer::all() as $es) {
    if (($es['source'] ?? 'own') !== 'free') {
        continue;
    }
    $checked++;
    $protocol = $es['protocol'] ?? '';
    $ok = true;
    if (in_array($protocol, $tcpProtocols, true)) {
        $host = $es['endpoint_host'];
        $port = (int) $es['endpoint_port'];
        $conn = @fsockopen($host, $port, $errno, $errstr, 4.0);
        $ok = $conn !== false;
        if ($conn) {
            fclose($conn);
        }
    }
    // UDP (hysteria2/tuic): $ok остаётся true — доверяем свежести подписки.
    ExitServer::setHealth((int) $es['id'], $ok);
    if ($ok) {
        $up++;
    }
}

fwrite(STDOUT, "[free_pool_health] checked=$checked up=$up\n");
