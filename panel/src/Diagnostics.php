<?php

namespace App;

/**
 * "Diagnostics / Evidence" — документирует НАБЛЮДАЕМОЕ сетевое поведение по
 * конкретному хосту/домену (DNS, TCP-доступность локально и извне, TLS),
 * чтобы было что предметно приложить к обращению в поддержку хостера/
 * провайдера. run() выводов не делает; analyzeSshFailure() даёт оценку
 * «похоже на блокировку Active Blocking System» по косвенным признакам (локально закрыто всё,
 * извне открыто) — это подсказка пользователю, а не доказательство.
 *
 * "Локально" = с этого сервера (обычно входной VPS, где крутится панель).
 * "Извне" = через check-host.net — независимый сторонний сервис с узлами
 * по всему миру (тот же метод, которым в этой сессии вручную диагностировали
 * блокировку DE-сервера). Оба замера — просто данные на момент запроса,
 * а не гарантия чего-либо в будущем.
 */
class Diagnostics
{
    private const CONNECT_TIMEOUT = 3;
    private const CHECKHOST_POLL_DELAY = 5;

    /**
     * Публичная обёртка над checkExternal() — используется там, где нужна
     * только внешняя TCP-достижимость порта (например App\Provisioner/
     * settings.php после смены Reality listen_ip/port), без DNS/TLS-проверок
     * и без host-параметра из пользовательского ввода на весь run().
     *
     * @return array{available:bool,nodes:array<int,array{node:string,country:string,ok:bool,note:string}>,error:?string}
     */
    public static function checkPortReachability(string $host, int $port): array
    {
        return self::checkExternal($host, $port);
    }

    /**
     * @return array{host:string,port:int,checked_at:string,dns:array,local:array,tls:?array,external:array}
     */
    public static function run(string $host, int $port = 443): array
    {
        return [
            'host' => $host,
            'port' => $port,
            'checked_at' => date('c'),
            'dns' => self::checkDns($host),
            'local' => self::checkLocalReachability($host, $port),
            'tls' => $port === 443 || $port === 8443 ? self::checkTls($host, $port) : null,
            'external' => self::checkExternal($host, $port),
        ];
    }

    /** Порты, которые пробуем при недоступном SSH: где обычно бывает SSH + типовые открытые у VPS. */
    public const SSH_PROBE_PORTS = [22, 2222, 22222, 2022, 222, 10022, 443, 80, 8443, 8080, 3389];

    /**
     * Быстрая проверка перед SSH-логином: TCP-соединение + SSH-баннер.
     * TCP может открыться, но баннер так и не прийти — типичная картина,
     * когда трафик до сервера режется по пути (DPI).
     *
     * @return array{tcp:bool,banner:?string,error:?string}
     */
    public static function probeSsh(string $host, int $port, int $timeout = 4): array
    {
        $conn = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
        if (!$conn) {
            return ['tcp' => false, 'banner' => null, 'error' => $errstr ?: 'timeout'];
        }
        stream_set_timeout($conn, $timeout);
        $line = @fgets($conn, 256);
        fclose($conn);
        $line = $line === false ? '' : trim($line);
        return ['tcp' => true, 'banner' => str_starts_with($line, 'SSH-') ? $line : null, 'error' => $line === '' ? I18n::t('diag.probe_no_banner') : null];
    }

    /**
     * Обмен ключами SSH (без авторизации). Active Blocking System часто пропускает TCP и даже
     * приветствие сервера, а обрывает именно этот шаг — проверено вживую:
     * заблокированный сервер отдаёт баннер, но KEX висит до таймаута.
     *
     * @return array{ok:bool,error:?string,seconds:float}
     */
    public static function probeSshKex(string $host, int $port, int $timeout = 6): array
    {
        $start = microtime(true);
        try {
            $ssh = new \phpseclib3\Net\SSH2($host, $port, $timeout);
            $ssh->setTimeout($timeout);
            $ok = (bool) $ssh->getServerPublicHostKey();
            $error = $ok ? null : I18n::t('diag.kex_incomplete');
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }
        return ['ok' => $ok, 'error' => $error, 'seconds' => round(microtime(true) - $start, 1)];
    }

    /**
     * Разбор "SSH не отвечает": параллельно стучимся на типовые порты с ЭТОГО
     * сервера (он локально — ровно тот путь, которым панель ходит на сервер) и
     * проверяем SSH-порт извне (check-host.net, узлы по всему миру). По
     * сочетанию результатов — человекочитаемый вывод. Вывод «похоже на
     * блокировку Active Blocking System» — оценка по косвенным признакам (локально не открывается
     * ничего, извне порт открыт), а не доказательство.
     *
     * @return array{verdict:string,message:string,advice:string[],ssh_port:int,local:array,ssh_found_on:?int,external:array}
     */
    public static function analyzeSshFailure(string $host, int $sshPort): array
    {
        $ports = array_values(array_unique(array_merge([$sshPort], self::SSH_PROBE_PORTS)));
        $local = self::probePortsParallel($host, $ports, 4);

        $sshFoundOn = null;      // порт, где SSH реально работает (баннер + обмен ключами)
        $bannerPorts = [];       // порты, где пришло приветствие SSH
        foreach ($local as $port => $r) {
            if ($r['open']) {
                $probe = self::probeSsh($host, $port, 3);
                $local[$port]['banner'] = $probe['banner'];
                if ($probe['banner']) {
                    $bannerPorts[] = $port;
                    $kex = self::probeSshKex($host, $port);
                    $local[$port]['kex'] = $kex['ok'];
                    $local[$port]['kex_error'] = $kex['error'];
                    if ($kex['ok'] && $sshFoundOn === null) {
                        $sshFoundOn = $port;
                    }
                }
            }
        }
        $openLocal = array_keys(array_filter($local, fn($r) => $r['open']));

        $external = self::checkExternal($host, $sshPort);
        $foreignOk = 0; $foreignTotal = 0; $ruOk = 0; $ruTotal = 0;
        foreach ($external['nodes'] as $n) {
            $isRu = stripos($n['country'], 'russia') !== false || str_starts_with($n['node'], 'ru');
            if ($isRu) { $ruTotal++; $ruOk += $n['ok'] ? 1 : 0; } else { $foreignTotal++; $foreignOk += $n['ok'] ? 1 : 0; }
        }

        // Единичные «открыто» из сотни узлов — шум (локальные сети узлов, anycast);
        // «доступен извне» — только если открыто заметной доле узлов.
        $foreignReachable = $foreignOk >= max(2, (int) ceil($foreignTotal * 0.3));

        $advice = [];
        $openList = implode(', ', $openLocal);
        if ($sshFoundOn !== null && $sshFoundOn === $sshPort) {
            $verdict = 'ssh_ok';
            $message = I18n::t('diag.ssh_ok', $sshPort);
        } elseif ($sshFoundOn !== null) {
            $verdict = 'ssh_other_port';
            $message = I18n::t('diag.ssh_other_port', $sshPort, $sshFoundOn);
            $advice[] = I18n::t('diag.ssh_other_port_adv', $sshFoundOn);
        } elseif ($bannerPorts) {
            $verdict = 'tspu_ssh_dpi';
            $message = I18n::t('diag.tspu_ssh_dpi', implode(', ', $bannerPorts))
                . ($foreignReachable ? ' ' . I18n::t('diag.foreign_open', $sshPort, $foreignOk, $foreignTotal) : '');
            $advice[] = I18n::t('diag.adv_change_ip');
            $advice[] = I18n::t('diag.adv_relay_dpi');
        } elseif (!$openLocal && $foreignReachable) {
            $verdict = 'tspu_block';
            $message = I18n::t('diag.tspu_block', $sshPort, $foreignOk, $foreignTotal)
                . ($ruTotal ? ' ' . I18n::t('diag.tspu_block_ru', $ruOk, $ruTotal) : '');
            $advice[] = I18n::t('diag.adv_change_ip');
            $advice[] = I18n::t('diag.adv_relay_block');
        } elseif (!$openLocal && $external['available']) {
            $verdict = 'down';
            $message = I18n::t('diag.down');
            $advice[] = I18n::t('diag.adv_down');
        } elseif (!$openLocal) {
            $verdict = 'unreachable';
            $message = I18n::t('diag.unreachable');
            $advice[] = I18n::t('diag.adv_unreachable');
        } elseif (!empty($local[$sshPort]['open'])) {
            $verdict = 'ssh_no_banner';
            $message = I18n::t('diag.ssh_no_banner', $sshPort);
            $advice[] = I18n::t('diag.adv_move_port');
        } elseif ($foreignReachable) {
            $verdict = 'tspu_port';
            $message = I18n::t('diag.tspu_port', $openList, $sshPort);
            $advice[] = I18n::t('diag.adv_move_to_open', $openLocal[0]);
        } else {
            $verdict = 'ssh_closed';
            $message = I18n::t('diag.ssh_closed', $openList, $sshPort);
            $advice[] = I18n::t('diag.adv_check_sshd');
        }

        return [
            'verdict' => $verdict,
            'message' => $message,
            'advice' => $advice,
            'ssh_port' => $sshPort,
            'local' => $local,
            'ssh_found_on' => $sshFoundOn,
            'external' => $external,
        ];
    }

    /**
     * Параллельный TCP-connect на список портов (неблокирующие сокеты +
     * stream_select), чтобы 10 портов проверялись за один таймаут, а не за 10.
     *
     * @return array<int,array{open:bool,error:?string}>
     */
    public static function probePortsParallel(string $host, array $ports, int $timeout = 4): array
    {
        $pending = [];
        $result = [];
        foreach ($ports as $port) {
            $port = (int) $port;
            $s = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($s) {
                $pending[$port] = $s;
            } else {
                $result[$port] = ['open' => false, 'error' => $errstr ?: I18n::t('diag.conn_error')];
            }
        }
        $deadline = microtime(true) + $timeout;
        while ($pending && microtime(true) < $deadline) {
            $write = array_values($pending);
            $read = $except = [];
            $left = max(0, $deadline - microtime(true));
            if (@stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1e6)) === false) {
                break;
            }
            foreach ($write as $s) {
                $port = array_search($s, $pending, true);
                // Сокет стал writable и при ошибке — соединение подтверждает только наличие peer-адреса.
                $result[$port] = stream_socket_get_name($s, true) !== false
                    ? ['open' => true, 'error' => null]
                    : ['open' => false, 'error' => I18n::t('diag.conn_refused')];
                fclose($s);
                unset($pending[$port]);
            }
        }
        foreach ($pending as $port => $s) {
            fclose($s);
            $result[$port] = ['open' => false, 'error' => I18n::t('diag.conn_timeout')];
        }
        $ordered = [];
        foreach ($ports as $port) {
            $ordered[(int) $port] = $result[(int) $port];
        }
        return $ordered;
    }

    private static function checkDns(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['resolved' => true, 'note' => I18n::t('diag.dns_ip')];
        }
        $ip = gethostbyname($host);
        $resolved = $ip !== $host;
        return ['resolved' => $resolved, 'address' => $resolved ? $ip : null];
    }

    private static function checkLocalReachability(string $host, int $port): array
    {
        $start = microtime(true);
        $conn = @fsockopen($host, $port, $errno, $errstr, self::CONNECT_TIMEOUT);
        $elapsedMs = round((microtime(true) - $start) * 1000);
        if ($conn) {
            fclose($conn);
            return ['reachable' => true, 'latency_ms' => $elapsedMs];
        }
        return ['reachable' => false, 'error' => $errstr ?: 'timeout', 'latency_ms' => $elapsedMs];
    }

    private static function checkTls(string $host, int $port): array
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
        $client = @stream_socket_client("ssl://$host:$port", $errno, $errstr, self::CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if (!$client) {
            return ['available' => false, 'error' => $errstr ?: I18n::t('diag.tls_none')];
        }
        $params = stream_context_get_params($client);
        fclose($client);
        if (empty($params['options']['ssl']['peer_certificate'])) {
            return ['available' => false, 'error' => I18n::t('diag.tls_nocert')];
        }
        $parsed = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        return [
            'available' => true,
            'subject_cn' => $parsed['subject']['CN'] ?? null,
            'valid_from' => isset($parsed['validFrom_time_t']) ? date('c', $parsed['validFrom_time_t']) : null,
            'valid_to' => isset($parsed['validTo_time_t']) ? date('c', $parsed['validTo_time_t']) : null,
            'expired' => isset($parsed['validTo_time_t']) && $parsed['validTo_time_t'] < time(),
        ];
    }

    /** @return array{available:bool,nodes:array<int,array{node:string,country:string,ok:bool,note:string}>,error:?string} */
    /** Настройки → «Приватность»: можно запретить панели обращаться к check-host.net. */
    public static function externalChecksEnabled(): bool
    {
        return Models\Setting::get('external_checks_enabled', '1') === '1';
    }

    private static function checkExternal(string $host, int $port): array
    {
        if (!self::externalChecksEnabled()) {
            return ['available' => false, 'nodes' => [], 'error' => I18n::t('diag.ext_disabled')];
        }
        $url = 'https://check-host.net/check-tcp?host=' . rawurlencode("$host:$port");
        $initial = self::httpGetJson($url);
        if (!$initial || empty($initial['ok']) || empty($initial['request_id'])) {
            return ['available' => false, 'nodes' => [], 'error' => I18n::t('diag.ext_unavailable')];
        }

        sleep(self::CHECKHOST_POLL_DELAY);

        $result = self::httpGetJson('https://check-host.net/check-result/' . $initial['request_id']);
        if (!$result) {
            return ['available' => false, 'nodes' => [], 'error' => I18n::t('diag.ext_noresult')];
        }

        $nodeMeta = $initial['nodes'] ?? [];
        $nodes = [];
        foreach ($result as $nodeName => $entries) {
            $entry = $entries[0] ?? null;
            $country = $nodeMeta[$nodeName][1] ?? '';
            if ($entry && empty($entry['error'])) {
                $nodes[] = ['node' => $nodeName, 'country' => $country, 'ok' => true, 'note' => I18n::t('diag.node_ms', round(($entry['time'] ?? 0) * 1000))];
            } else {
                $nodes[] = ['node' => $nodeName, 'country' => $country, 'ok' => false, 'note' => $entry['error'] ?? I18n::t('diag.node_noreply')];
            }
        }

        return ['available' => true, 'nodes' => $nodes, 'error' => null];
    }

    private static function httpGetJson(string $url): ?array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "Accept: application/json\r\n", 'timeout' => 10]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
