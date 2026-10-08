<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\Models\Setting;
use App\Modules\ModuleManager;

// Диагностика домена сайта-визитки: куда указывает A-запись и обслуживает ли
// этот домен именно наша панель (ищем маркер <meta name="x-vpsrouter-site">).
Http::guard(['GET']);

if (!ModuleManager::featureActive('site-builder')) {
    Http::error('module disabled', 404);
}

$host = strtolower(trim((string) Setting::get('site_public_host', '')));
$host = preg_replace('/^https?:\/\//', '', (string) $host);
$host = preg_replace('/\/.*$/', '', (string) $host);

if ($host === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
    Http::json(['status' => 'unset', 'host' => $host]);
}

// IP(ы) панели: публичный хост reality (обычно это и есть IP сервера) либо резолв своего имени.
$panelHostName = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
$panelIps = [];
$ph = (string) Setting::get('reality_public_host', '');
if ($ph !== '' && filter_var($ph, FILTER_VALIDATE_IP)) {
    $panelIps[] = $ph;
}
foreach ([$panelHostName, gethostname()] as $n) {
    if ($n) {
        $ip = @gethostbyname($n);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $panelIps[] = $ip;
        }
    }
}
$panelIps = array_values(array_unique(array_filter($panelIps)));

// A-записи домена.
$domainIps = [];
$a = @dns_get_record($host, DNS_A);
if (is_array($a)) {
    foreach ($a as $r) {
        if (!empty($r['ip'])) {
            $domainIps[] = $r['ip'];
        }
    }
}
if (!$domainIps) {
    $ip = @gethostbyname($host);
    if ($ip && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        $domainIps[] = $ip;
    }
}
$domainIps = array_values(array_unique($domainIps));

// HTTP-проба: тянем корень домена и ищем наш маркер.
$marker = false;
$reachable = false;
$fetchErr = '';
foreach (['https', 'http'] as $scheme) {
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 1, 'max_redirects' => 3,
            'header' => "User-Agent: vpsrouter-domain-check\r\nAccept: text/html\r\n", 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($scheme . '://' . $host . '/', false, $ctx, 0, 65536);
    if ($body !== false) {
        $reachable = true;
        if (strpos($body, 'x-vpsrouter-site') !== false) {
            $marker = true;
            break;
        }
    } else {
        $err = error_get_last();
        $fetchErr = $err['message'] ?? '';
    }
}

$dnsMatches = $domainIps && $panelIps && array_intersect($domainIps, $panelIps);

if ($marker) {
    $status = 'ok';
} elseif (!$domainIps) {
    $status = 'no_dns';          // нет A-записи вовсе
} elseif (!$dnsMatches) {
    $status = 'wrong_ip';        // A-запись указывает на чужой IP
} elseif ($reachable) {
    $status = 'other_site';      // IP наш, но отдаётся не наш сайт (чужой vhost / не настроен server_name)
} else {
    $status = 'unreachable';     // не достучались
}

Http::json([
    'status' => $status,
    'host' => $host,
    'panel_ips' => $panelIps,
    'domain_ips' => $domainIps,
    'reachable' => $reachable,
    'marker' => $marker,
    'error' => $fetchErr,
]);
