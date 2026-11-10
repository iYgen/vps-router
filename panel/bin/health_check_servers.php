<?php
// Проверяет доступность каждого сервера графа инфраструктуры (servers) по
// SSH и пишет результат в БД тем же способом, что и кнопка «Проверить
// соединение» — дашборд показывает это как online/offline на графе.
// Отдельный скрипт от health_check.php: тот проверяет exit_servers (сами
// WG-туннели, через ping по интерфейсу), этот — узлы графа (полноценный
// SSH-логин, как ручная проверка).
//
// Запуск по cron раз в минуту:
//   * * * * * php /var/www/panel/bin/health_check_servers.php >/dev/null 2>&1
// Интервал автообновления графа в браузере настраивается отдельно в
// «Настройки» (graph_refresh_interval) — он не может быть точнее, чем
// частота запуска этого скрипта; если нужна частота обновления чаще 60с,
// замените cron на systemd-таймер с меньшим интервалом.

require __DIR__ . '/../src/bootstrap.php';

use App\LocalSystem;
use App\Models\Server;
use App\Provisioner;
use App\ServerAlerts;
use App\ServerIncidents;
use App\ServerTraffic;
use App\Ssh;

const HEALTH_SSH_ATTEMPTS = 3;    // всего попыток SSH до статуса offline
const HEALTH_SSH_RETRY_DELAY = 5; // сек между попытками (3×8с таймаут + 2×5с < минуты крона)

foreach (Server::all() as $server) {
    if (!$server['enabled']) {
        continue;
    }

    // is_self — сервер, на котором прямо сейчас выполняется этот же скрипт
    // (панель крутится на нём) — по определению online, SSH тут не нужен и
    // часто не настроен (незачем ходить по SSH самому к себе).
    if ($server['is_self']) {
        // hostname/OS/нагрузка читаются локально — на графе у текущего
        // сервера тоже видны CPU/RAM, как у остальных.
        $info = LocalSystem::info();
        // Трёхстатусность (по мотивам 2S-UI): узел жив, но движок sing-box упал →
        // не «online», а «warning» (core-stopped). Только для роутер-узла (на exit
        // sing-box может не быть).
        $status = 'online';
        if (($server['role'] ?? '') === 'router' && isset($info['singbox_active']) && !$info['singbox_active']) {
            $status = 'warning';
            $info['raw_error'] = 'Узел доступен, но служба sing-box не запущена (core-stopped).';
        }
        Server::setCheckResult((int) $server['id'], $status, $info);
        if (!empty($info['net'])) {
            ServerTraffic::record((int) $server['id'], $info['net']);
        }
        continue;
    }

    Provisioner::knockIfConfigured($server);

    if (!$server['has_ssh_key']) {
        // SSH-ключ не задан в карточке сервера — полноценно проверить не можем,
        // но не оставляем статус вечно "unknown": хотя бы TCP-достижимость
        // порта SSH, тем же способом, что и App\Diagnostics::checkLocalReachability.
        if (!$server['host']) {
            continue;
        }
        $conn = @fsockopen($server['host'], (int) $server['ssh_port'], $errno, $errstr, 3);
        if ($conn) {
            fclose($conn);
            $st = 'online';
            $res = ['ok' => true, 'raw_error' => 'Проверена только TCP-достижимость порта — SSH-ключ не задан в карточке сервера, полноценная проверка недоступна.'];
        } else {
            $st = 'offline';
            $res = ['ok' => false, 'raw_error' => $errstr ?: 'порт SSH недоступен'];
        }
        Server::setCheckResult((int) $server['id'], $st, $res);
        $old = (string) ($server['status'] ?? 'unknown');
        ServerAlerts::onStatusChange($server, $old, $st, (string) ($res['raw_error'] ?? ''));
        // Без SSH-ключа логи за простой снять нельзя — фиксируем только окно.
        ServerIncidents::handle($server, $old, $st, (string) ($res['raw_error'] ?? ''), null);
        continue;
    }

    $privateKey = Server::sshPrivateKey((int) $server['id']);
    if (!$privateKey) {
        continue;
    }
    // Повторы перед тем как объявить узел offline: одиночный SSH-отказ — обычно не
    // простой, а sshd под брутфорсом, отбросивший соединение по MaxStartups (или
    // разовая потеря пакета). Без повтора это давало пары писем «недоступен →
    // снова доступен» с интервалом в минуту.
    $result = Ssh::testConnection($server['host'], (int) $server['ssh_port'], $server['ssh_user'], $privateKey);
    for ($try = 1; !$result['ok'] && $try < HEALTH_SSH_ATTEMPTS; $try++) {
        sleep(HEALTH_SSH_RETRY_DELAY);
        $result = Ssh::testConnection($server['host'], (int) $server['ssh_port'], $server['ssh_user'], $privateKey);
    }
    $status = $result['ok'] ? 'online' : 'offline';
    // Трёхстатусность для УДАЛЁННЫХ router-узлов: доступен, но sing-box стоит -> warning.
    if ($result['ok'] && ($server['role'] ?? '') === 'router') {
        $core = Ssh::runCommand($server['host'], (int) $server['ssh_port'], $server['ssh_user'], $privateKey, 'systemctl is-active sing-box 2>/dev/null');
        if ($core['ok'] && $core['output'] !== 'active') {
            $status = 'warning';
            $result['singbox_active'] = false;
            $result['raw_error'] = 'Узел доступен, но служба sing-box не запущена (core-stopped).';
        }
    }
    Server::setCheckResult((int) $server['id'], $status, $result);
    $old = (string) ($server['status'] ?? 'unknown');
    ServerAlerts::onStatusChange($server, $old, $status, (string) ($result['raw_error'] ?? ''));
    // При восстановлении (down→online) снимаем логи за окно простоя по SSH.
    ServerIncidents::handle($server, $old, $status, (string) ($result['raw_error'] ?? ''), [
        'host' => $server['host'], 'port' => (int) $server['ssh_port'], 'user' => $server['ssh_user'], 'key' => $privateKey,
    ]);
    // Трафик сервера для контроля лимита тарифа — дельтами счётчиков интерфейса.
    if (!empty($result['net'])) {
        ServerTraffic::record((int) $server['id'], $result['net']);
    }
}
