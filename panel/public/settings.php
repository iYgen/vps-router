<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Applier;
use App\Auth;
use App\DeviceInbounds;
use App\Diagnostics;
use App\Models\AuditLog;
use App\Models\NodeSetting;
use App\Models\Setting;
use App\NetworkInfo;
use App\RealityKeys;
use App\RiskScanner;
use App\RouterContext;
use App\View;

Auth::requireLogin();

// Мультироутерность: настройки Reality/протоколов/блокировок — per-router.
// self-роутер использует глобальный store (как раньше), остальные — node_settings.
// Системные/аккаунт/приватность — глобальные (не зависят от выбранного роутера).
$routerId = RouterContext::currentId();
$isSelfRouter = RouterContext::isSelf($routerId);
$nodeCtx = $isSelfRouter ? null : $routerId;
DeviceInbounds::useRouter($nodeCtx);
$sget = fn(string $key, $default = null) => $isSelfRouter ? Setting::get($key, $default) : NodeSetting::get($routerId, $key, $default);
$sset = function (string $key, string $value) use ($isSelfRouter, $routerId): void {
    $isSelfRouter ? Setting::set($key, $value) : NodeSetting::set($routerId, $key, $value);
};

// Embed-режим: страница настроек встроена в инспектор конкретного сервера
// (Инфраструктура → узел → «Конфигурация»). Тогда показываем ТОЛЬКО per-router
// настройки (Reality/протоколы/блокировки) без общего каркаса панели.
$embed = isset($_GET['embed']);
// В embed-режиме после сохранения добавляем saved=1 — по нему iframe уведомит
// родительскую страницу (граф Инфраструктуры), чтобы та сразу перечитала конфиг.
$selfRedirect = '/settings.php' . ($embed ? '?embed=1&router=' . $routerId . '&saved=1' : '');

$fields = [
    'reality_server_name' => [t('field.reality_server_name'), 'www.example-popular-site.com'],
    'reality_listen_ip' => [t('field.reality_listen_ip'), '203.0.113.10'],
    'reality_public_host' => [t('field.reality_public_host'), '203.0.113.10'],
    'reality_listen_port' => [t('field.reality_listen_port'), '443'],
    'reality_private_key' => [t('field.reality_private_key'), ''],
    'reality_public_key' => [t('field.reality_public_key'), ''],
    'reality_short_id' => [t('field.reality_short_id'), 'a1b2c3d4'],
    'reality_handshake' => [t('field.reality_handshake'), '127.0.0.1:8443'],
];
$secretFields = ['reality_private_key'];
$popularSniDomains = ['www.microsoft.com', 'www.apple.com', 'addons.mozilla.org', 'www.amazon.com', 'www.samsung.com', 'gateway.icloud.com', 'www.swift.org'];

/**
 * Панель ставится на разные серверы с разной сетевой топологией — поэтому
 * IP/порт не зашиваем как константу, а определяем на месте (App\NetworkInfo)
 * и правим только то, чего не хватает или что реально занято. Уже заданные
 * пользователем значения не трогаем, кроме случая конфликта порта.
 * @return string[] человекочитаемые пояснения, что было подставлено/исправлено
 */
function autoFillNetwork(): array
{
    $notes = [];
    $ip = Setting::get('reality_listen_ip');
    if (!$ip) {
        $ip = NetworkInfo::detectPublicIp();
        if ($ip) {
            Setting::set('reality_listen_ip', $ip);
            if (!Setting::get('reality_public_host')) {
                Setting::set('reality_public_host', $ip);
            }
            $notes[] = "IP определён автоматически: $ip";
        } else {
            $notes[] = 'Не удалось автоматически определить публичный IP сервера — впишите его вручную.';
        }
    } elseif (!Setting::get('reality_public_host')) {
        Setting::set('reality_public_host', $ip);
    }

    if ($ip) {
        $port = (int) Setting::get('reality_listen_port', '0');
        if (!$port || !NetworkInfo::isPortFree($ip, $port)) {
            $free = NetworkInfo::findFreePort($ip, $port ? array_values(array_diff(NetworkInfo::CANDIDATE_PORTS, [$port])) : null);
            if ($free) {
                Setting::set('reality_listen_port', (string) $free);
                $notes[] = $port
                    ? "Порт $port на этом сервере уже занят — выбран свободный порт $free."
                    : "Порт не был указан — выбран свободный порт $free.";
            } else {
                $notes[] = 'Все порты-кандидаты (' . implode(', ', NetworkInfo::CANDIDATE_PORTS) . ') заняты — укажите порт вручную.';
            }
        }
    }

    return $notes;
}

/** После Apply — реально ли порт достижим снаружи (check-host.net), а не только поднят локально. */
function checkExternalReachability(): void
{
    $ip = Setting::get('reality_listen_ip');
    $port = (int) Setting::get('reality_listen_port', '0');
    if (!$ip || !$port) {
        return;
    }
    $result = Diagnostics::checkPortReachability($ip, $port);
    if (!$result['available'] || empty($result['nodes'])) {
        return; // check-host.net сам недоступен — не мешаем основному flash об Apply
    }
    $ok = count(array_filter($result['nodes'], fn($n) => $n['ok']));
    $total = count($result['nodes']);
    if ($ok === 0) {
        View::flash('error', "Порт $port на $ip поднят локально, но недоступен снаружи ни с одного из $total проверенных узлов — скорее всего закрыт в firewall сервера или у хостинг-провайдера. Откройте порт $port/tcp для входящих подключений.");
    } else {
        View::flash('success', "Порт $port доступен снаружи ($ok из $total проверенных узлов).");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            foreach (array_keys($fields) as $key) {
                $value = trim($_POST[$key] ?? '');
                // Секретное поле, оставленное пустым, не затирает уже сохранённое значение.
                if ($value === '' && in_array($key, $secretFields, true)) {
                    continue;
                }
                $sset($key, $value);
            }
            AuditLog::record('settings.save', "reality settings updated (router=$routerId)");
            $result = (new Applier())->apply(Auth::username() ?? 'system', $action, $routerId);
            View::applyResultFlash($result);
            if (($result['exit_code'] ?? 1) === 0 && $isSelfRouter) {
                checkExternalReachability();
            }
        } elseif ($action === 'generate_reality') {
            $alreadyConfigured = !empty($sget('reality_private_key'));

            // Та же X25519-пара, что использует Provisioner для WG/AmneziaWG/
            // VLESS-Reality — sodium_crypto_box_keypair (Curve25519), закодированная
            // как base64 URL-safe без padding — именно этот формат (а не обычный
            // base64_encode) даёт `sing-box generate reality-keypair` и ожидают
            // строгие Reality-клиенты (см. App\RealityKeys). short_id — как
            // `sing-box generate rand 8 --hex`.
            $kp = RealityKeys::generateKeypair();
            $sset('reality_private_key', $kp['private']);
            $sset('reality_public_key', $kp['public']);
            $sset('reality_short_id', RealityKeys::shortId());
            if (!$sget('reality_server_name')) {
                $sset('reality_server_name', $popularSniDomains[array_rand($popularSniDomains)]);
            }
            AuditLog::record('settings.generate_reality', "Reality keypair перегенерирован (router=$routerId)");

            // Флашим ДО Applier::apply() — она может бросить исключение
            // (например если после автоподстановки IP/порта всё ещё не хватает
            // SNI-домена), и тогда до кода ниже управление не дойдёт вообще:
            // пользователь должен в любом случае увидеть, что уже подставилось.
            // Автоопределение IP/порта работает только для self-роутера (панель
            // видит свою сеть локально); для удалённого роутера IP задаётся руками.
            $notes = $isSelfRouter ? autoFillNetwork() : [];
            $msg = $alreadyConfigured
                ? 'Новая пара ключей сгенерирована. Все ранее выданные ссылки/QR устройств перестанут подключаться — их нужно переоткрыть/переслать заново на странице «Устройства».'
                : 'Ключи сгенерированы.';
            View::flash('success', $msg . ($notes ? ' ' . implode(' ', $notes) : ''));

            $result = (new Applier())->apply(Auth::username() ?? 'system', $action, $routerId);
            View::applyResultFlash($result);
            if (($result['exit_code'] ?? 1) === 0 && $isSelfRouter) {
                checkExternalReachability();
            }
        } elseif ($action === 'refresh_network') {
            // Ключи НЕ трогаем вообще — только IP/порт. Для удалённого роутера
            // автоопределение недоступно — IP/порт задаются вручную в полях выше.
            if (!$isSelfRouter) {
                throw new \RuntimeException('Автоопределение IP/порта доступно только для сервера панели (self). Для удалённого роутера укажите IP и порт вручную и сохраните.');
            }
            $notes = autoFillNetwork();
            AuditLog::record('settings.refresh_network', implode('; ', $notes) ?: 'без изменений');
            View::flash('success', $notes ? implode(' ', $notes) : 'IP и порт уже в порядке, ничего не изменилось.');

            $result = (new Applier())->apply(Auth::username() ?? 'system', $action, $routerId);
            View::applyResultFlash($result);
            if (($result['exit_code'] ?? 1) === 0) {
                checkExternalReachability();
            }
        } elseif ($action === 'save_inbounds') {
            $values = [];
            foreach (array_keys(DeviceInbounds::PROTOCOLS) as $protocol) {
                $values[$protocol] = [
                    'enabled' => !empty($_POST['inbound'][$protocol]['enabled']),
                    'port' => (int) ($_POST['inbound'][$protocol]['port'] ?? 0),
                ];
            }
            if (!array_filter($values, fn($v) => $v['enabled'])) {
                throw new \InvalidArgumentException(t('settings.err.no_proto'));
            }
            DeviceInbounds::saveSettings($values);
            AuditLog::record('settings.save_inbounds', implode(',', DeviceInbounds::enabled()) . " (router=$routerId)");
            $result = (new Applier())->apply(Auth::username() ?? 'system', 'протоколы устройств: ' . implode(', ', DeviceInbounds::enabled()), $routerId);
            View::applyResultFlash($result);
            if (($result['exit_code'] ?? 1) === 0) {
                View::flash('success', 'Протоколы применены. Ссылки/файлы для каждого протокола — на странице «Устройства». Если у хостера есть свой firewall в панели управления — откройте там порты: ' . implode(', ', DeviceInbounds::openPorts()) . '.');
            }
        } elseif ($action === 'save_adblock') {
            $sset('adblock_enabled', !empty($_POST['adblock_enabled']) ? '1' : '0');
            $sset('block_quic', !empty($_POST['block_quic']) ? '1' : '0');
            $sset('smart_dns', !empty($_POST['smart_dns']) ? '1' : '0');
            $sset('list_fetch_via_exit', !empty($_POST['list_fetch_via_exit']) ? '1' : '0');
            AuditLog::record('settings.adblock', 'adblock=' . (!empty($_POST['adblock_enabled']) ? '1' : '0') . ' quic=' . (!empty($_POST['block_quic']) ? '1' : '0') . ' dns=' . (!empty($_POST['smart_dns']) ? '1' : '0') . ' listfetch=' . (!empty($_POST['list_fetch_via_exit']) ? '1' : '0') . " (router=$routerId)");
            $result = (new Applier())->apply(Auth::username() ?? 'system', 'блокировка рекламы/QUIC/умный DNS', $routerId);
            View::applyResultFlash($result);
        } elseif ($action === 'save_privacy') {
            $on = !empty($_POST['external_checks_enabled']);
            Setting::set('external_checks_enabled', $on ? '1' : '0');
            AuditLog::record('settings.privacy', 'check-host.net ' . ($on ? 'on' : 'off'));
            View::flash('success', $on ? t('settings.flash.privacy_on') : t('settings.flash.privacy_off'));
        } elseif ($action === 'change_username') {
            Auth::changeUsername($_POST['new_username'] ?? '', $_POST['current_password'] ?? '');
            View::flash('success', t('settings.flash.username_changed'));
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $stmt = \App\Database::get()->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($current, $user['password_hash'])) {
                throw new \RuntimeException(t('settings.err.pw_current'));
            }
            if (strlen($new) < 10) {
                throw new \RuntimeException(t('settings.err.pw_short'));
            }
            $upd = \App\Database::get()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            AuditLog::record('user.change_password', '');
            View::flash('success', t('settings.flash.pw_changed'));
        } elseif ($action === 'set_email') {
            $email = trim($_POST['email'] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException(t('settings.err.email'));
            }
            $upd = \App\Database::get()->prepare('UPDATE users SET email = ? WHERE id = ?');
            $upd->execute([$email !== '' ? $email : null, $_SESSION['user_id']]);
            AuditLog::record('user.set_email', $email !== '' ? $email : '(очищен)');
            View::flash('success', $email !== '' ? t('settings.flash.email_saved') : t('settings.flash.email_cleared'));
        } elseif ($action === 'import_settings') {
            if (empty($_FILES['backup']['tmp_name']) || !is_uploaded_file($_FILES['backup']['tmp_name'])) {
                throw new \InvalidArgumentException(t('settings.backup.err_file'));
            }
            $raw = file_get_contents($_FILES['backup']['tmp_name']);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                throw new \InvalidArgumentException(t('settings.backup.err_parse'));
            }
            $res = \App\PanelBackup::import($data);
            AuditLog::record('settings.import', 'restored ' . array_sum($res['restored']) . ' rows');
            View::flash('success', t('settings.backup.imported', array_sum($res['restored'])));
        } elseif ($action === 'totp_begin') {
            Auth::beginTotpEnrollment(Auth::username() ?? 'admin');
            // секрет в сессии; QR покажется на GET ниже
        } elseif ($action === 'totp_cancel') {
            unset($_SESSION['totp_setup_secret']);
        } elseif ($action === 'totp_enable') {
            $codes = Auth::confirmTotpEnrollment((int) $_SESSION['user_id'], $_POST['code'] ?? '');
            $_SESSION['totp_recovery_once'] = $codes;
            View::flash('success', t('settings.2fa.flash_enabled'));
        } elseif ($action === 'totp_disable') {
            Auth::disableTotp((int) $_SESSION['user_id'], $_POST['current_password'] ?? '', $_POST['code'] ?? '');
            View::flash('success', t('settings.2fa.flash_disabled'));
        } elseif ($action === 'save_graph_settings') {
            $interval = (int) ($_POST['graph_refresh_interval'] ?? 30);
            if ($interval < 10 || $interval > 3600) {
                throw new \InvalidArgumentException('Интервал должен быть от 10 до 3600 секунд');
            }
            Setting::set('graph_refresh_interval', (string) $interval);
            AuditLog::record('settings.save_graph', "interval={$interval}s");
            View::flash('success', t('settings.flash.graph_saved'));
        } elseif ($action === 'save_updates') {
            // Обновления — глобальная настройка панели (не per-router).
            $checkUrl = trim($_POST['update_check_url'] ?? '');
            $repoUrl = trim($_POST['update_repo_url'] ?? '');
            foreach ([$checkUrl, $repoUrl] as $u) {
                if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                    throw new \InvalidArgumentException('Адрес должен начинаться с http:// или https://');
                }
            }
            Setting::set('update_check_url', $checkUrl);
            Setting::set('update_repo_url', $repoUrl);
            AuditLog::record('settings.save_updates', $checkUrl !== '' ? 'configured' : 'cleared');
            View::flash('success', t('settings.flash.updates_saved'));
        } elseif ($action === 'save_alerts') {
            $alertEmail = trim($_POST['alert_email'] ?? '');
            if ($alertEmail !== '' && !filter_var($alertEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Некорректный e-mail для оповещений');
            }
            Setting::set('alert_email', $alertEmail);
            Setting::set('alerts_offline', !empty($_POST['alerts_offline']) ? '1' : '0');
            // Активная защита (модуль probe-intel): сбор зондирований и авто-ротация.
            if (\App\Modules\ModuleManager::featureActive('probe-intel')) {
                Setting::set('probe_logging_allowed', !empty($_POST['probe_logging_allowed']) ? '1' : '0');
                Setting::set('probe_auto_rotate', !empty($_POST['probe_auto_rotate']) ? '1' : '0');
            }
            AuditLog::record('settings.save_alerts', 'offline=' . (!empty($_POST['alerts_offline']) ? '1' : '0'));
            View::flash('success', t('settings.flash.alerts_saved'));
        } elseif ($action === 'save_antidpi') {
            $allowedFp = ['chrome', 'firefox', 'edge', 'safari', 'ios', 'android', 'random', 'randomized'];
            $fp = (string) ($_POST['antidpi_utls_fingerprint'] ?? 'chrome');
            if (!in_array($fp, $allowedFp, true)) {
                $fp = 'chrome';
            }
            $delay = trim((string) ($_POST['antidpi_fragment_delay'] ?? ''));
            if ($delay !== '' && !preg_match('/^[0-9]{1,6}(ms|s)$/', $delay)) {
                throw new \InvalidArgumentException('Задержка фрагментации: формат «500ms» или «1s»');
            }
            Setting::set('antidpi_tls_fragment', !empty($_POST['antidpi_tls_fragment']) ? '1' : '0');
            Setting::set('antidpi_record_fragment', !empty($_POST['antidpi_record_fragment']) ? '1' : '0');
            Setting::set('antidpi_fragment_delay', $delay);
            Setting::set('antidpi_utls_fingerprint', $fp);
            AuditLog::record('settings.save_antidpi', 'fragment=' . (!empty($_POST['antidpi_tls_fragment']) ? '1' : '0') . ' record=' . (!empty($_POST['antidpi_record_fragment']) ? '1' : '0') . " fp=$fp");
            View::flash('success', t('settings.flash.antidpi_saved'));
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }

    header('Location: ' . $selfRedirect);
    exit;
}

// Отображаемые значения — для выбранного роутера: self → глобальные,
// иначе node_settings поверх глобальных дефолтов.
$settings = $isSelfRouter ? Setting::all() : (NodeSetting::allForServer($routerId) + Setting::all());
$hasKeys = !empty($settings['reality_private_key']);
$currentUserStmt = \App\Database::get()->prepare('SELECT email FROM users WHERE id = ?');
$currentUserStmt->execute([$_SESSION['user_id']]);
$currentEmail = $currentUserStmt->fetchColumn() ?: '';

// 2FA (TOTP) состояние для вкладки «Аккаунт».
$totpEnabled = Auth::totpEnabled((int) $_SESSION['user_id']);
$totpSetupSecret = $_SESSION['totp_setup_secret'] ?? null;
$totpUri = $totpSetupSecret ? \App\Totp::uri($totpSetupSecret, Auth::username() ?? 'admin', 'vps_router') : null;
$totpRecoveryOnce = $_SESSION['totp_recovery_once'] ?? null;
unset($_SESSION['totp_recovery_once']);

// ===========================================================================
//  ВЫВОД СТРАНИЦЫ
//  • embed-режим (?embed=1&router=<id>) — только per-router настройки
//    (Reality/протоколы/блокировки) для инспектора конкретного сервера;
//  • обычный режим — глобальные настройки с боковой навигацией по разделам.
// ===========================================================================

if ($embed) {
    View::header(t('settings.router_cfg_title'), '', true);
    include __DIR__ . '/partials/settings-router.php';
    // После сохранения (redirect с saved=1) сообщаем родителю, чтобы граф
    // Инфраструктуры сразу перечитал конфиг; затем чистим URL от saved.
    if (isset($_GET['saved'])) {
        echo '<script>try{if(window.parent&&window.parent!==window){window.parent.postMessage({type:"vr-settings-saved",router:' . (int) $routerId . '},location.origin);}history.replaceState(null,"",location.pathname+"?embed=1&router=' . (int) $routerId . '");}catch(e){}</script>';
    }
    View::footer(true);
    return;
}

$sec = \App\SecurityAudit::report();
$updFeature = \App\Modules\ModuleManager::featureActive('update-check');
$upd = $updFeature ? \App\UpdateChecker::status() : null;

View::header('Настройки');
?>

<div class="settings-shell">
  <nav class="settings-nav" id="settings-nav">
    <button type="button" class="settings-navitem active" data-section="general"><span class="si">⚙️</span><span class="sl"><?= htmlspecialchars(t('settings.sec.general')) ?></span></button>
    <button type="button" class="settings-navitem" data-section="dpi"><span class="si">🛡️</span><span class="sl"><?= htmlspecialchars(t('settings.sec.dpi')) ?></span></button>
    <button type="button" class="settings-navitem" data-section="alerts"><span class="si">🔔</span><span class="sl"><?= htmlspecialchars(t('settings.sec.alerts')) ?></span></button>
    <button type="button" class="settings-navitem" data-section="security"><span class="si">🔐</span><span class="sl"><?= htmlspecialchars(t('settings.sec.security')) ?></span></button>
    <button type="button" class="settings-navitem" data-section="backup"><span class="si">💾</span><span class="sl"><?= htmlspecialchars(t('settings.sec.backup')) ?></span></button>
  </nav>
  <div class="settings-content">

  <!-- ============================ ОБЩИЕ ============================ -->
  <section class="settings-section" data-section="general">

  <div class="flash" style="background:color-mix(in srgb, var(--info) 10%, transparent);border:1px solid color-mix(in srgb, var(--info) 30%, transparent)">
    <?= htmlspecialchars(t('settings.router_moved_note')) ?> <a href="/dashboard.php"><?= htmlspecialchars(t('settings.router_moved_link')) ?></a>
  </div>

  <div class="card">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.graph.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.graph.desc')) ?></p>
    <form method="post" class="row">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="save_graph_settings">
      <input type="number" name="graph_refresh_interval" min="10" max="3600" value="<?= (int) ($settings['graph_refresh_interval'] ?? 30) ?>" style="width:100px">
      <span class="muted"><?= htmlspecialchars(t('common.seconds')) ?></span>
      <button type="submit"><?= htmlspecialchars(t('common.save')) ?></button>
    </form>
  </div>

  <?php if ($updFeature): ?>
  <div class="card" id="updates">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.updates.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.updates.desc')) ?></p>
    <div class="row" style="gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
      <span><?= htmlspecialchars(t('settings.updates.current')) ?>: <strong><?= htmlspecialchars($upd['current']) ?></strong></span>
      <span id="upd-latest-wrap" <?= $upd['latest'] ? '' : 'hidden' ?>>
        <?= htmlspecialchars(t('settings.updates.latest')) ?>: <strong id="upd-latest"><?= htmlspecialchars((string) $upd['latest']) ?></strong>
      </span>
      <?php if ($upd['update_available']): ?>
        <span class="badge warn" id="upd-badge"><?= htmlspecialchars(t('settings.updates.available')) ?></span>
      <?php else: ?>
        <span class="badge ok" id="upd-badge" <?= ($upd['latest'] && !$upd['error']) ? '' : 'hidden' ?>><?= htmlspecialchars(t('settings.updates.uptodate')) ?></span>
      <?php endif; ?>
    </div>
    <form method="post" style="display:flex;flex-direction:column;gap:8px;max-width:640px">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="save_updates">
      <label class="muted" style="font-size:13px"><?= htmlspecialchars(t('settings.updates.check_url')) ?></label>
      <input type="url" name="update_check_url" placeholder="https://raw.githubusercontent.com/&lt;owner&gt;/&lt;repo&gt;/main/panel/VERSION"
             value="<?= htmlspecialchars((string) ($settings['update_check_url'] ?? '')) ?>">
      <label class="muted" style="font-size:13px"><?= htmlspecialchars(t('settings.updates.repo_url')) ?></label>
      <input type="url" name="update_repo_url" placeholder="https://github.com/&lt;owner&gt;/&lt;repo&gt;"
             value="<?= htmlspecialchars((string) ($settings['update_repo_url'] ?? '')) ?>">
      <div class="row" style="gap:10px;align-items:center;margin-top:6px">
        <button type="submit"><?= htmlspecialchars(t('common.save')) ?></button>
        <button type="button" class="secondary" id="btn-check-updates"><?= htmlspecialchars(t('settings.updates.check_now')) ?></button>
        <span class="muted" id="upd-checked" style="font-size:12px"><?= $upd['checked_at'] ? htmlspecialchars(t('settings.updates.checked_at') . ': ' . $upd['checked_at']) : '' ?></span>
      </div>
      <div class="flash error" id="upd-error" <?= $upd['error'] ? '' : 'hidden' ?>><?= htmlspecialchars((string) $upd['error']) ?></div>
    </form>
    <p class="muted" style="margin:12px 0 0;font-size:12px"><?= htmlspecialchars(t('settings.updates.apply_note')) ?></p>
  </div>

  <script>
  (function () {
    var btn = document.getElementById('btn-check-updates');
    if (!btn) return;
    btn.addEventListener('click', async function () {
      btn.disabled = true;
      var orig = btn.textContent;
      btn.textContent = '…';
      try {
        var csrfEl = document.querySelector('#updates input[name=csrf_token]');
        var r = await fetch('/api/updates.php?action=check', {
          method: 'POST',
          headers: { 'X-CSRF-Token': (csrfEl ? csrfEl.value : '') }
        });
        var d = await r.json();
        var err = document.getElementById('upd-error');
        if (d.error) {
          err.textContent = d.error; err.hidden = false;
        } else {
          err.hidden = true;
          var lw = document.getElementById('upd-latest-wrap');
          document.getElementById('upd-latest').textContent = d.latest || '?';
          lw.hidden = !d.latest;
          var badge = document.getElementById('upd-badge');
          if (badge) {
            badge.hidden = false;
            badge.className = 'badge ' + (d.update_available ? 'warn' : 'ok');
            badge.textContent = d.update_available
              ? <?= json_encode(t('settings.updates.available'), JSON_UNESCAPED_UNICODE) ?>
              : <?= json_encode(t('settings.updates.uptodate'), JSON_UNESCAPED_UNICODE) ?>;
          }
        }
        if (d.checked_at) {
          document.getElementById('upd-checked').textContent =
            <?= json_encode(t('settings.updates.checked_at') . ': ', JSON_UNESCAPED_UNICODE) ?> + d.checked_at;
        }
      } catch (e) {
        var err2 = document.getElementById('upd-error'); err2.textContent = String(e); err2.hidden = false;
      } finally {
        btn.disabled = false; btn.textContent = orig;
      }
    });
  })();
  </script>
  <?php endif; // feature-update-check ?>

  <div class="card">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.privacy.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.privacy.desc')) ?></p>
    <form method="post" class="row">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="save_privacy">
      <label style="display:flex;align-items:center;gap:8px;font-weight:500">
        <input type="checkbox" name="external_checks_enabled" value="1" <?= ($settings['external_checks_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
        <?= htmlspecialchars(t('settings.privacy.toggle')) ?>
      </label>
      <button type="submit"><?= htmlspecialchars(t('common.save')) ?></button>
    </form>
  </div>

  </section>

  <!-- ========================= ЗАЩИТА ОТ DPI ========================= -->
  <section class="settings-section" data-section="dpi" hidden>

  <div class="card" id="antidpi">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.antidpi.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.antidpi.desc')) ?></p>
    <form method="post">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="save_antidpi">
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
        <input type="checkbox" name="antidpi_tls_fragment" value="1" <?= ($settings['antidpi_tls_fragment'] ?? '0') === '1' ? 'checked' : '' ?> style="width:auto">
        <?= htmlspecialchars(t('settings.antidpi.fragment')) ?>
      </label>
      <p class="muted" style="font-size:12px;margin:2px 0 10px 26px"><?= htmlspecialchars(t('settings.antidpi.fragment_hint')) ?></p>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
        <input type="checkbox" name="antidpi_record_fragment" value="1" <?= ($settings['antidpi_record_fragment'] ?? '0') === '1' ? 'checked' : '' ?> style="width:auto">
        <?= htmlspecialchars(t('settings.antidpi.record_fragment')) ?>
      </label>
      <p class="muted" style="font-size:12px;margin:2px 0 10px 26px"><?= htmlspecialchars(t('settings.antidpi.record_fragment_hint')) ?></p>
      <label class="muted" style="font-size:13px"><?= htmlspecialchars(t('settings.antidpi.fragment_delay')) ?></label><br>
      <input type="text" name="antidpi_fragment_delay" value="<?= htmlspecialchars((string) ($settings['antidpi_fragment_delay'] ?? '')) ?>" placeholder="500ms" style="width:auto;margin-top:4px">
      <p class="muted" style="font-size:12px;margin:6px 0 10px"><?= htmlspecialchars(t('settings.antidpi.fragment_delay_hint')) ?></p>
      <label class="muted" style="font-size:13px"><?= htmlspecialchars(t('settings.antidpi.fingerprint')) ?></label><br>
      <select name="antidpi_utls_fingerprint" style="width:auto;margin-top:4px">
        <?php foreach (['chrome', 'firefox', 'edge', 'safari', 'ios', 'android', 'random', 'randomized'] as $fp): ?>
          <option value="<?= $fp ?>" <?= ($settings['antidpi_utls_fingerprint'] ?? 'chrome') === $fp ? 'selected' : '' ?>><?= $fp ?></option>
        <?php endforeach; ?>
      </select>
      <p class="muted" style="font-size:12px;margin:6px 0 0"><?= htmlspecialchars(t('settings.antidpi.fingerprint_hint')) ?></p>
      <p class="muted" style="font-size:12px;margin:10px 0 0"><?= htmlspecialchars(t('settings.antidpi.cdn_note')) ?></p>
      <div style="margin-top:10px"><button type="submit"><?= htmlspecialchars(t('common.save')) ?></button></div>
    </form>

    <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars(t('settings.zapret.title')) ?></div>
    <p class="muted" style="font-size:12px;margin:2px 0 8px"><?= htmlspecialchars(t('settings.zapret.desc')) ?></p>
    <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
      <select id="zapret-strategy" style="width:auto">
        <?php foreach (['fakesplit', 'fake', 'disorder', 'split'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
      </select>
      <button type="button" class="secondary" id="zapret-enable"><?= htmlspecialchars(t('settings.zapret.enable')) ?></button>
      <button type="button" class="secondary" id="zapret-disable"><?= htmlspecialchars(t('settings.zapret.disable')) ?></button>
      <span id="zapret-status" class="muted" style="font-size:12px"></span>
    </div>
    <pre id="zapret-out" style="display:none;white-space:pre-wrap;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px;margin-top:8px;font-size:12px;max-height:200px;overflow:auto"></pre>
    <script>
    (function () {
      var CSRF = <?= json_encode(Auth::csrfToken()) ?>;
      var st = document.getElementById('zapret-status'), out = document.getElementById('zapret-out'), sel = document.getElementById('zapret-strategy');
      var L = { on: <?= json_encode(t('settings.zapret.on')) ?>, off: <?= json_encode(t('settings.zapret.off')) ?>, warn: <?= json_encode(t('settings.zapret.warn')) ?> };
      function j(url, opts) { return fetch(url, opts).then(function (r) { return r.json(); }); }
      function load() { j('/api/zapret.php').then(function (s) {
        if (s.strategy) sel.value = s.strategy;
        st.textContent = (s.enabled ? '● ' + L.on : '○ ' + L.off) + ' · targets: ' + (s.targets || 0);
        st.style.color = s.enabled ? 'var(--success)' : 'var(--text-muted)';
      }).catch(function () {}); }
      function run(action) {
        st.textContent = '…';
        j('/api/zapret.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ action: action, strategy: sel.value }) })
          .then(function (r) { out.style.display = 'block'; out.textContent = (r.output || r.error || JSON.stringify(r)); load(); })
          .catch(function (e) { out.style.display = 'block'; out.textContent = String(e); });
      }
      document.getElementById('zapret-enable').addEventListener('click', function () { if (confirm(L.warn)) run('enable'); });
      document.getElementById('zapret-disable').addEventListener('click', function () { run('disable'); });
      load();
    })();
    </script>
  </div>

  </section>

  <!-- ========================= ОПОВЕЩЕНИЯ ========================= -->
  <section class="settings-section" data-section="alerts" hidden>

  <div class="card" id="alerts">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.alerts.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.alerts.desc')) ?></p>
    <form method="post" style="display:flex;flex-direction:column;gap:10px;max-width:520px">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="save_alerts">
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
        <input type="checkbox" name="alerts_offline" value="1" <?= ($settings['alerts_offline'] ?? '1') === '1' ? 'checked' : '' ?> style="width:auto">
        <?= htmlspecialchars(t('settings.alerts.offline')) ?>
      </label>
      <label class="muted" style="font-size:13px"><?= htmlspecialchars(t('settings.alerts.email')) ?></label>
      <input type="email" name="alert_email" placeholder="admin@example.com" value="<?= htmlspecialchars((string) ($settings['alert_email'] ?? '')) ?>">
      <p class="muted" style="font-size:12px;margin:0"><?= htmlspecialchars(t('settings.alerts.email_hint')) ?></p>
      <?php if (\App\Modules\ModuleManager::featureActive('probe-intel')): ?>
        <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
        <div style="font-weight:600;font-size:13px"><?= htmlspecialchars(t('settings.probe.title')) ?></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;margin-top:6px">
          <input type="checkbox" name="probe_logging_allowed" value="1" <?= ($settings['probe_logging_allowed'] ?? '0') === '1' ? 'checked' : '' ?> style="width:auto">
          <?= htmlspecialchars(t('settings.probe.logging')) ?>
        </label>
        <p class="muted" style="font-size:12px;margin:0 0 0 26px"><?= htmlspecialchars(t('settings.probe.logging_hint')) ?></p>
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;margin-top:8px">
          <input type="checkbox" name="probe_auto_rotate" value="1" <?= ($settings['probe_auto_rotate'] ?? '0') === '1' ? 'checked' : '' ?> style="width:auto">
          <?= htmlspecialchars(t('settings.probe.auto_rotate')) ?>
        </label>
        <p class="muted" style="font-size:12px;margin:0 0 0 26px"><?= htmlspecialchars(t('settings.probe.auto_rotate_hint')) ?></p>
      <?php endif; ?>
      <div><button type="submit"><?= htmlspecialchars(t('common.save')) ?></button></div>
    </form>
  </div>

  </section>

  <!-- ========================= БЕЗОПАСНОСТЬ ========================= -->
  <section class="settings-section" data-section="security" hidden>

  <div class="card" id="security">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('sec.title')) ?></h2>
    <?php if (empty($sec['findings'])): ?>
      <p class="flash success" style="margin:0"><?= htmlspecialchars(t('sec.all_good')) ?></p>
    <?php else: ?>
      <?php foreach ($sec['findings'] as $f): ?>
        <p class="flash <?= $f['level'] === 'error' ? 'error' : '' ?>" style="margin:0 0 8px"><?= htmlspecialchars(t($f['key'], ...$f['args'])) ?></p>
      <?php endforeach; ?>
    <?php endif; ?>
    <table style="margin-top:12px">
      <tr>
        <td><?= htmlspecialchars(t('sec.os_label')) ?></td>
        <td><?= htmlspecialchars($sec['os']['pretty']) ?>
          <?php if ($sec['os']['eol']): ?><span class="badge down"><?= htmlspecialchars(t('sec.eol_badge')) ?><?= $sec['os']['eol_date'] ? ' · ' . htmlspecialchars(sprintf(t('sec.eol_since'), $sec['os']['eol_date'])) : '' ?></span><?php else: ?><span class="badge ok"><?= htmlspecialchars(t('sec.status_ok')) ?></span><?php endif; ?>
        </td>
      </tr>
      <tr>
        <td><?= htmlspecialchars(t('sec.php_label')) ?></td>
        <td><?= htmlspecialchars($sec['php']['version']) ?>
          <?php if ($sec['php']['eol']): ?><span class="badge down"><?= htmlspecialchars(t('sec.eol_badge')) ?><?= $sec['php']['eol_date'] ? ' · ' . htmlspecialchars(sprintf(t('sec.eol_since'), $sec['php']['eol_date'])) : '' ?></span><?php else: ?><span class="badge ok"><?= htmlspecialchars(t('sec.status_ok')) ?></span><?php endif; ?>
        </td>
      </tr>
      <tr>
        <td><?= htmlspecialchars(t('sec.updates_label')) ?></td>
        <td>
          <?php if ($sec['updates']['checked_at'] === null): ?>
            <span class="muted"><?= htmlspecialchars(t('sec.updates_unknown')) ?></span>
          <?php elseif (($sec['updates']['total'] ?? 0) > 0): ?>
            <?= htmlspecialchars(sprintf(t('sec.updates_count'), $sec['updates']['total'], $sec['updates']['security'])) ?>
            <span class="muted">· <?= htmlspecialchars(sprintf(t('sec.checked_at'), $sec['updates']['checked_at'])) ?></span>
          <?php else: ?>
            <span class="badge ok"><?= htmlspecialchars(t('sec.updates_none')) ?></span>
            <span class="muted">· <?= htmlspecialchars(sprintf(t('sec.checked_at'), $sec['updates']['checked_at'])) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    </table>
  </div>

  <div class="card">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.account.login.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.account.login.desc')) ?></p>
    <form method="post" class="row">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="change_username">
      <input name="new_username" value="<?= htmlspecialchars(Auth::username() ?? '') ?>" placeholder="<?= htmlspecialchars(t('settings.account.login.new')) ?>" required style="min-width:200px">
      <input type="password" name="current_password" placeholder="<?= htmlspecialchars(t('settings.account.login.current_pw')) ?>" required autocomplete="current-password">
      <button type="submit"><?= htmlspecialchars(t('settings.account.login.submit')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.account.email.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.account.email.desc')) ?></p>
    <form method="post" class="row">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="set_email">
      <input type="email" name="email" value="<?= htmlspecialchars($currentEmail) ?>" placeholder="you@example.com" style="min-width:260px">
      <button type="submit"><?= htmlspecialchars(t('common.save')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.account.pw.title')) ?></h2>
    <form method="post" class="row">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="change_password">
      <input type="password" name="current_password" placeholder="<?= htmlspecialchars(t('settings.account.login.current_pw')) ?>" required>
      <input type="password" name="new_password" placeholder="<?= htmlspecialchars(t('settings.account.pw.new')) ?>" required>
      <button type="submit"><?= htmlspecialchars(t('settings.account.pw.submit')) ?></button>
    </form>
  </div>

  <div class="card" id="twofa">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.2fa.title')) ?>
      <?php if ($totpEnabled): ?><span class="badge ok" style="vertical-align:middle"><?= htmlspecialchars(t('settings.2fa.on')) ?></span>
      <?php else: ?><span class="badge unknown" style="vertical-align:middle"><?= htmlspecialchars(t('settings.2fa.off')) ?></span><?php endif; ?>
    </h2>
    <p class="muted"><?= htmlspecialchars(t('settings.2fa.desc')) ?></p>

    <?php if ($totpRecoveryOnce): ?>
      <div class="flash success" style="margin-bottom:12px">
        <strong><?= htmlspecialchars(t('settings.2fa.recovery_title')) ?></strong><br>
        <span class="muted"><?= htmlspecialchars(t('settings.2fa.recovery_note')) ?></span>
        <div style="font-family:monospace;font-size:14px;margin-top:8px;line-height:1.8">
          <?php foreach ($totpRecoveryOnce as $rc): ?><div><?= htmlspecialchars($rc) ?></div><?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($totpEnabled): ?>
      <form method="post" class="row">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="totp_disable">
        <input type="password" name="current_password" placeholder="<?= htmlspecialchars(t('settings.2fa.current_pw')) ?>" required autocomplete="current-password">
        <input type="text" name="code" inputmode="numeric" placeholder="<?= htmlspecialchars(t('settings.2fa.disable_code')) ?>" required style="width:220px">
        <button type="submit" class="danger"><?= htmlspecialchars(t('settings.2fa.disable')) ?></button>
      </form>
    <?php elseif ($totpSetupSecret): ?>
      <p><strong><?= htmlspecialchars(t('settings.2fa.scan')) ?></strong></p>
      <div id="totp-qr" style="background:#fff;padding:10px;border-radius:8px;display:inline-block"></div>
      <p class="muted" style="margin:10px 0 4px"><?= htmlspecialchars(t('settings.2fa.manual')) ?></p>
      <code style="font-size:14px;letter-spacing:1px;word-break:break-all"><?= htmlspecialchars($totpSetupSecret) ?></code>
      <form method="post" class="row" style="margin-top:14px">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="totp_enable">
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="<?= htmlspecialchars(t('settings.2fa.confirm_code')) ?>" required autofocus style="width:200px">
        <button type="submit"><?= htmlspecialchars(t('settings.2fa.confirm_btn')) ?></button>
      </form>
      <form method="post" style="margin-top:8px">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="totp_cancel">
        <button type="submit" class="secondary"><?= htmlspecialchars(t('settings.2fa.cancel')) ?></button>
      </form>
      <script src="/assets/vendor/qrcode.min.js"></script>
      <script>
        (function () {
          try {
            var qr = qrcode(0, 'M');
            qr.addData(<?= json_encode($totpUri) ?>);
            qr.make();
            document.getElementById('totp-qr').innerHTML = qr.createImgTag(5, 8);
          } catch (e) { document.getElementById('totp-qr').textContent = 'QR error'; }
        })();
      </script>
    <?php else: ?>
      <form method="post">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="totp_begin">
        <button type="submit"><?= htmlspecialchars(t('settings.2fa.enable')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  </section>

  <!-- ========================= РЕЗЕРВНЫЕ КОПИИ ========================= -->
  <section class="settings-section" data-section="backup" hidden>

  <div class="card" id="backup">
    <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.backup.title')) ?></h2>
    <p class="muted"><?= htmlspecialchars(t('settings.backup.desc')) ?></p>
    <div class="row" style="gap:10px;align-items:center">
      <a class="btn" href="/api/settings-export.php"><?= htmlspecialchars(t('settings.backup.save')) ?></a>
    </div>
    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
    <form method="post" enctype="multipart/form-data" class="row" style="gap:10px;align-items:center"
          onsubmit="return confirm('<?= htmlspecialchars(t('settings.backup.confirm'), ENT_QUOTES) ?>');">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="import_settings">
      <input type="file" name="backup" accept=".json,application/json" required>
      <button type="submit" class="danger"><?= htmlspecialchars(t('settings.backup.load')) ?></button>
    </form>
    <p class="muted" style="margin:8px 0 0"><?= htmlspecialchars(t('settings.backup.warn')) ?></p>
  </div>

  </section>

  </div><!-- /.settings-content -->
</div><!-- /.settings-shell -->

<style>
.settings-shell{display:flex;gap:20px;align-items:flex-start}
.settings-nav{display:flex;flex-direction:column;gap:2px;flex:0 0 210px;position:sticky;top:16px}
.settings-navitem{display:flex;align-items:center;gap:10px;background:none;border:none;border-radius:8px;color:var(--text-secondary);padding:9px 12px;font-size:14px;font-weight:500;cursor:pointer;text-align:left;width:100%}
.settings-navitem .si{font-size:16px;line-height:1;width:20px;text-align:center}
.settings-navitem:hover,.settings-navitem:focus{background:var(--surface)!important;color:var(--text)!important}
.settings-navitem.active,.settings-navitem.active:hover{background:var(--surface-elevated)!important;color:var(--accent)!important}
.settings-content{flex:1;min-width:0}
@media (max-width:760px){
  .settings-shell{flex-direction:column}
  .settings-nav{flex-direction:row;flex-wrap:wrap;position:static;flex-basis:auto;width:100%;border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:4px}
  .settings-navitem{width:auto}
  .settings-navitem .sl{display:inline}
}
</style>
<script>
(function(){
  var nav=document.getElementById('settings-nav');
  if(!nav)return;
  var KEY='vr_settings_section';
  function show(sec){
    document.querySelectorAll('.settings-navitem').forEach(function(b){b.classList.toggle('active',b.dataset.section===sec);});
    document.querySelectorAll('.settings-section').forEach(function(p){p.hidden=(p.dataset.section!==sec);});
    try{localStorage.setItem(KEY,sec);}catch(e){}
  }
  nav.querySelectorAll('.settings-navitem').forEach(function(b){
    b.addEventListener('click',function(){show(b.dataset.section);location.hash=b.dataset.section;});
  });
  var start=(location.hash||'').replace('#','');
  if(!document.querySelector('.settings-section[data-section="'+start+'"]')){
    try{start=localStorage.getItem(KEY);}catch(e){}
  }
  if(document.querySelector('.settings-section[data-section="'+start+'"]'))show(start);
})();
</script>

<?php View::footer(); ?>
