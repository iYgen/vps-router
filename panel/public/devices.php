<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Applier;
use App\Auth;
use App\DeviceInbounds;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExitServer;
use App\Models\ExitServerPeer;
use App\Models\NodeSetting;
use App\Models\Setting;
use App\Provisioner;
use App\RouterContext;
use App\View;

Auth::requireLogin();

// Мультироутерность: всё на этой странице — в контексте текущего роутера.
$routerId = RouterContext::currentId();
$isSelfRouter = RouterContext::isSelf($routerId);
$includeNull = RouterContext::includeNull($routerId);
DeviceInbounds::useRouter($isSelfRouter ? null : $routerId);
// Настройки Reality: self — из глобального store (как раньше), иначе — node_settings.
$rget = fn(string $key, $default = null) => $isSelfRouter ? (Setting::get($key) ?? $default) : NodeSetting::get($routerId, $key, $default);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $kind = $_POST['kind'] ?? 'vless';
            if ($name === '') {
                throw new \InvalidArgumentException(t('devices.err.name_empty'));
            }
            if ($kind === 'wireguard') {
                $exitServerId = (int) ($_POST['exit_server_id'] ?? 0);
                if (!$exitServerId) {
                    throw new \InvalidArgumentException(t('devices.err.pick_exit'));
                }
                Provisioner::addWireguardPeer($exitServerId, $name);
                AuditLog::record('wg_peer.add', "exit_server=$exitServerId name=$name");
                View::flash('success', t('devices.created_vless', $name));
            } else {
                Client::create($name, $routerId);
                AuditLog::record('client.create', "$name (router=$routerId)");
                $result = (new Applier())->apply(Auth::username() ?? 'system', $action, $routerId);
                View::applyResultFlash($result);
            }
        } elseif ($action === 'create_device') {
            // Мастер добавления устройства: имя + выбранный протокол; при необходимости
            // сам включает нужный протокол входа, затем создаёт устройство и применяет.
            $name = trim($_POST['name'] ?? '');
            $protocol = $_POST['protocol'] ?? 'vless';
            $deviceType = $_POST['device_type'] ?? '';
            if ($name === '') {
                throw new \InvalidArgumentException(t('devices.err.name_empty'));
            }
            if (!isset(DeviceInbounds::PROTOCOLS[$protocol])) {
                throw new \InvalidArgumentException('Неизвестный тип соединения');
            }
            if (!DeviceInbounds::isEnabled($protocol)) {
                DeviceInbounds::enableProtocol($protocol);
                AuditLog::record('settings.enable_protocol', "$protocol (router=$routerId, via device wizard)");
            }
            Client::create($name, $routerId, $deviceType);
            AuditLog::record('client.create', "$name [$deviceType/$protocol] (router=$routerId)");
            $result = (new Applier())->apply(Auth::username() ?? 'system', 'add device: ' . $name, $routerId);
            View::applyResultFlash($result);
        } elseif ($action === 'revoke') {
            $id = (int) $_POST['id'];
            Client::setRevoked($id, (bool) $_POST['revoked']);
            AuditLog::record('client.revoke', "id=$id revoked=" . $_POST['revoked']);
            $result = (new Applier())->apply(Auth::username() ?? 'system', $action, $routerId);
            View::applyResultFlash($result);
        } elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            Client::delete($id);
            AuditLog::record('client.delete', "id=$id");
            $result = (new Applier())->apply(Auth::username() ?? 'system', $action, $routerId);
            View::applyResultFlash($result);
        } elseif ($action === 'delete_wg') {
            $id = (int) $_POST['id'];
            Provisioner::removeWireguardPeer($id);
            AuditLog::record('wg_peer.remove', "id=$id");
            View::flash('success', t('devices.deleted'));
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }

    header('Location: /devices.php');
    exit;
}

$wgPeers = ExitServerPeer::allWithExitServer();
$wgExitServers = array_values(array_filter(
    ExitServer::all(),
    fn($es) => ($es['protocol'] ?? '') === 'wireguard' && ($es['provision_status'] ?? '') === 'provisioned'
));
$settings = Setting::all();
$config = \App\App::config();

$connectHost = $rget('reality_public_host', $config['reality_listen_ip'] ?? null);
$port = $rget('reality_listen_port', (string) ($config['reality_listen_port'] ?? 443));
$pbk = $rget('reality_public_key');
$sid = $rget('reality_short_id');
$sni = $rget('reality_server_name');

$vlessReady = $connectHost && $pbk && $sid && $sni && DeviceInbounds::isEnabled('vless');
Client::backfillCredentials();
$clients = Client::all($routerId, $includeNull);
$enabledProtocols = DeviceInbounds::enabled();
$totals = \App\TrafficCollector::deviceTotals();

function fmtBytes(int $n): string
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

/** Компактные inline-иконки для строк устройств (без текста). */
function di(string $n, int $s = 16): string
{
    $p = [
        'phone' => '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M11 18h2"/>',
        'tablet' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M10 18h4"/>',
        'computer' => '<rect x="3" y="5" width="18" height="11" rx="2"/><path d="M2 20h20"/>',
        'router' => '<rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 17h.01M11 17h2"/><path d="M12 8v-.5a4 4 0 0 1 4-4M12 8a7 7 0 0 1 7-7"/>',
        'chevron' => '<path d="M9 6l6 6-6 6"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/>',
        'json' => '<path d="M8 3H7a2 2 0 0 0-2 2v4l-1 3 1 3v4a2 2 0 0 0 2 2h1"/><path d="M16 3h1a2 2 0 0 1 2 2v4l1 3-1 3v4a2 2 0 0 1-2 2h-1"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M14 21h3M21 18v3"/>',
        'pause' => '<rect x="7" y="5" width="3.5" height="14" rx="1"/><rect x="13.5" y="5" width="3.5" height="14" rx="1"/>',
        'play' => '<path d="M7 5l12 7-12 7z"/>',
        'trash' => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
    ];
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($p[$n] ?? '') . '</svg>';
}
?>
<style>
.dev-list{display:flex;flex-direction:column;gap:4px}
.dev-row{display:flex;align-items:center;gap:10px;padding:7px 10px;border:1px solid var(--border);border-radius:9px;background:var(--surface)}
.dev-row.revoked{opacity:.55}
.dev-toggle{background:none;border:none;color:var(--text-muted);cursor:pointer;padding:2px;display:flex;transition:transform .15s}
.dev-toggle.open{transform:rotate(90deg)}
.dev-ic{color:var(--text);display:flex;flex-shrink:0;width:34px;height:34px;border-radius:9px;
  align-items:center;justify-content:center;background:rgba(255,255,255,.05);border:1px solid var(--border)}
.dev-name{font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:60px}
.dev-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;padding:2px 8px;border-radius:999px;white-space:nowrap}
.dev-pill .d{width:7px;height:7px;border-radius:50%}
.dev-pill.on{background:rgba(53,208,127,.14);color:var(--success)}.dev-pill.on .d{background:var(--success)}
.dev-pill.off{background:rgba(255,255,255,.06);color:var(--text-muted)}.dev-pill.off .d{background:var(--text-disabled)}
.dev-tr{color:var(--text-muted);font-size:12px;white-space:nowrap;margin-left:auto;display:flex;gap:10px;align-items:center}
.dev-tr b{color:var(--text);font-weight:600}
.dev-act{display:flex;gap:2px;flex-shrink:0}
.iconbtn{background:none;border:none;color:var(--text-muted);cursor:pointer;padding:6px;border-radius:7px;display:flex;text-decoration:none}
.iconbtn:hover{background:rgba(255,255,255,.07);color:var(--text)}
.iconbtn.danger:hover{background:rgba(255,92,92,.14);color:var(--danger)}
.iconbtn.ok{color:var(--success)}
.dev-exp{padding:6px 10px 12px 40px;border:1px solid var(--border);border-top:none;border-radius:0 0 9px 9px;margin-top:-5px;background:var(--surface)}
.proto-line{display:flex;align-items:center;gap:8px;padding:5px 0;flex-wrap:wrap}
.proto-name{font-size:13px;min-width:130px}
.copied{color:var(--success)!important}
@media(max-width:560px){.dev-tr{display:none}.proto-name{min-width:90px}}
</style>
<?php
View::header(t('devices.title'), t('devices.subtitle'));
View::routerScopeBar();
?>

<div class="card">
  <div class="row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0"><?= htmlspecialchars(t('devices.new.title')) ?></h2>
    <button type="button" id="dw-open"><?= htmlspecialchars(t('dw.open')) ?></button>
  </div>
  <p class="muted">
    <b>Устройство этого сервера</b> (телефон, ПК, роутер) сразу получает доступ по всем включённым
    протоколам: <?= $enabledProtocols ? htmlspecialchars(implode(', ', array_map(fn($p) => DeviceInbounds::PROTOCOLS[$p]['label'], $enabledProtocols))) : '<b>ни один не включён</b>' ?>.
    Если один протокол не подключается — возьмите ссылку/файл другого. Набор протоколов меняется в
    <a href="/settings.php">Настройках</a>. Keenetic/MikroTik могут подключаться сюда по WireGuard
    (включите его в Настройках).
    <br><b>Напрямую к exit-серверу (WireGuard)</b> — отдельный пир на самом exit-сервере в обход этого сервера.
  </p>
  <form method="post" class="row" id="new-device-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="create">
    <select name="kind" id="device-kind" onchange="document.getElementById('wg-target-row').classList.toggle('hidden', this.value !== 'wireguard')">
      <option value="vless"><?= htmlspecialchars(t('devices.new.this_server')) ?></option>
      <option value="wireguard"><?= htmlspecialchars(t('devices.new.direct_router')) ?></option>
    </select>
    <input name="name" required placeholder="<?= htmlspecialchars(t('devices.new.name_ph')) ?>">
    <span id="wg-target-row" class="hidden row" style="gap:10px">
      <select name="exit_server_id">
        <?php if (empty($wgExitServers)): ?>
          <option value="">— нет готового WireGuard exit-сервера —</option>
        <?php else: ?>
          <?php foreach ($wgExitServers as $es): ?>
            <option value="<?= (int)$es['id'] ?>"><?= htmlspecialchars($es['name']) ?></option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </span>
    <button type="submit"><?= htmlspecialchars(t('devices.new.create')) ?></button>
  </form>
  <?php if (empty($wgExitServers)): ?>
    <p class="muted" style="margin-top:10px">
      Для варианта «Роутер (WireGuard)» нужен хотя бы один exit-сервер с протоколом <b>wireguard</b>,
      установленный и настроенный (не amneziawg — встроенный клиент Keenetic не умеет его обфускацию).
      Создайте связь с типом wireguard на странице «Инфраструктура» и нажмите «Установить и настроить».
    </p>
  <?php endif; ?>
  <?php if (DeviceInbounds::isEnabled('vless') && !$vlessReady): ?>
    <p class="muted" style="margin-top:10px">
      Для VLESS сначала заполните Reality-настройки на странице
      <a href="/settings.php">Настройки</a> — без них VLESS-ссылку сгенерировать нельзя
      (остальные протоколы от этого не зависят).
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('devices.this_server', count($clients))) ?></h2>
  <?php if (empty($clients)): ?>
    <p class="muted"><?= htmlspecialchars(t('devices.empty')) ?></p>
  <?php endif; ?>
  <div class="dev-list">
  <?php foreach ($clients as $c): ?>
    <?php
      $configs = $c['revoked'] ? [] : DeviceInbounds::clientConfigs($c);
      $t = $totals['c' . $c['id']] ?? null;
      $hosts = $t ? \App\TrafficCollector::deviceHosts('c' . $c['id'], 30, 30) : [];
      $total = $t ? fmtBytes(($t['total_down'] ?? 0) + ($t['total_up'] ?? 0)) : '—';
      $typeIcon = ['router' => 'router', 'phone' => 'phone', 'tablet' => 'tablet', 'computer' => 'computer'][$c['device_type'] ?? ''] ?? 'phone';
    ?>
    <div class="dev-item">
      <div class="dev-row <?= $c['revoked'] ? 'revoked' : '' ?>">
        <button class="dev-toggle" type="button" title="<?= htmlspecialchars(t('devices.details')) ?>"><?= di('chevron') ?></button>
        <span class="dev-ic"><?= di($typeIcon, 18) ?></span>
        <span class="dev-name" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></span>
        <?php if (!$c['revoked']): ?>
          <span class="dev-pill <?= $t && $t['online'] ? 'on' : 'off' ?>"><span class="d"></span><?= $t && $t['online'] ? htmlspecialchars(t('devices.online')) : htmlspecialchars(t('devices.offline')) ?></span>
        <?php else: ?>
          <span class="badge down" style="padding:0 6px"><?= htmlspecialchars(t('devices.revoked')) ?></span>
        <?php endif; ?>
        <span class="dev-tr">
          <?php if ($t): ?><span title="<?= htmlspecialchars(t('devices.total')) ?>">↓<?= fmtBytes($t['total_down'] ?? 0) ?> ↑<?= fmtBytes($t['total_up'] ?? 0) ?></span><?php endif; ?>
          <span title="<?= htmlspecialchars(t('devices.total')) ?>">Σ <b><?= $total ?></b></span>
        </span>
        <span class="dev-act">
          <form method="post" class="inline">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="revoked" value="<?= $c['revoked'] ? '0' : '1' ?>">
            <button class="iconbtn <?= $c['revoked'] ? 'ok' : '' ?>" type="submit" title="<?= $c['revoked'] ? htmlspecialchars(t('devices.act.allow')) : htmlspecialchars(t('devices.act.revoke')) ?>"><?= di($c['revoked'] ? 'play' : 'pause') ?></button>
          </form>
          <form method="post" class="inline js-del" data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="iconbtn danger" type="submit" title="<?= htmlspecialchars(t('devices.act.delete')) ?>"><?= di('trash') ?></button>
          </form>
        </span>
      </div>
      <div class="dev-exp hidden">
        <div class="muted" style="font-size:12px;margin-bottom:8px">
          <?php if ($t && $t['online']): ?>🟢 в сети · <?= htmlspecialchars((string) $t['source_ip']) ?>
          <?php elseif ($t && $t['last_seen']): ?><?= htmlspecialchars(t('devices.was_online', date('d.m H:i', $t['last_seen']))) ?>
          <?php else: ?><?= htmlspecialchars(t('devices.never')) ?><?php endif; ?>
          · сегодня ↓<?= fmtBytes($t['today_down'] ?? 0) ?> ↑<?= fmtBytes($t['today_up'] ?? 0) ?>
          · 30дн ↓<?= fmtBytes($t['d30_down'] ?? 0) ?> ↑<?= fmtBytes($t['d30_up'] ?? 0) ?>
          · всего ↓<?= fmtBytes($t['total_down'] ?? 0) ?> ↑<?= fmtBytes($t['total_up'] ?? 0) ?>
        </div>
        <?php if (!$c['revoked'] && !$configs): ?>
          <p class="muted"><?= htmlspecialchars(t('devices.no_proto')) ?></p>
        <?php endif; ?>
        <?php foreach ($configs as $proto => $cfg): ?>
          <?php $key = (int) $c['id'] . '-' . $proto; ?>
          <div class="proto-line">
            <span class="proto-name"><b><?= htmlspecialchars($cfg['label']) ?></b></span>
            <?php if ($cfg['uri']): ?>
              <button class="iconbtn" type="button" data-copy="cfg-<?= $key ?>" title="<?= htmlspecialchars(t('devices.act.copy')) ?>"><?= di('copy') ?></button>
              <code id="cfg-<?= $key ?>" class="hidden"><?= htmlspecialchars($cfg['uri']) ?></code>
            <?php endif; ?>
            <a class="iconbtn" href="/device-config.php?id=<?= (int)$c['id'] ?>&format=<?= $proto ?>" title="Скачать <?= $cfg['file'] ? '.conf' : '.txt' ?>"><?= di('download') ?></a>
            <?php if ($proto === 'vless'): ?>
              <a class="iconbtn" href="/device-config.php?id=<?= (int)$c['id'] ?>&format=singbox" title="<?= htmlspecialchars(t('devices.act.json')) ?>"><?= di('json') ?></a>
            <?php endif; ?>
            <button class="iconbtn" type="button" data-show-qr-cfg="<?= $key ?>" data-url="/device-config.php?id=<?= (int)$c['id'] ?>&format=<?= $proto ?>&inline=1" title="<?= htmlspecialchars(t('devices.act.qr')) ?>"><?= di('qr') ?></button>
          </div>
          <div id="qr-cfg-wrap-<?= $key ?>" class="hidden" style="margin:4px 0 8px">
            <div id="qr-cfg-<?= $key ?>" style="background:#fff;display:inline-block;padding:10px;border-radius:8px"></div>
            <?php if ($cfg['file']): ?><div class="muted" style="font-size:11.5px">Содержит приватный ключ — не показывайте посторонним.</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($hosts): ?>
          <details style="margin-top:6px">
            <summary class="muted" style="cursor:pointer;font-size:12px">Сайты за 30 дней (топ <?= count($hosts) ?>)</summary>
            <table style="width:100%;font-size:12px;margin-top:6px">
              <?php foreach ($hosts as $h): ?>
                <tr><td style="word-break:break-all"><?= htmlspecialchars($h['host']) ?></td><td class="muted"><?= htmlspecialchars($h['outbound'] === 'direct-rf' ? 'напрямую' : $h['outbound']) ?></td><td style="text-align:right;white-space:nowrap">↓<?= fmtBytes($h['down']) ?> ↑<?= fmtBytes($h['up']) ?></td></tr>
              <?php endforeach; ?>
            </table>
          </details>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('devices.routers', count($wgPeers))) ?></h2>
  <?php if (empty($wgPeers)): ?>
    <p class="muted">Пока нет. Роутер Keenetic добавляется на странице <a href="/dashboard.php">Инфраструктура</a> (связь с exit-сервером типа WireGuard → «Устройства»).</p>
  <?php endif; ?>
  <div class="dev-list">
  <?php foreach ($wgPeers as $p): ?>
    <div class="dev-item">
      <div class="dev-row">
        <button class="dev-toggle" type="button" title="<?= htmlspecialchars(t('devices.details')) ?>"><?= di('chevron') ?></button>
        <span class="dev-ic"><?= di('router', 18) ?></span>
        <span class="dev-name" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></span>
        <span class="dev-dot <?= $p['applied_at'] ? 'on' : 'off' ?>" title="<?= $p['applied_at'] ? 'применено' : 'не применено' ?>"></span>
        <span class="dev-tr">через «<?= htmlspecialchars($p['exit_server_name']) ?>»</span>
        <span class="dev-act">
          <a class="iconbtn" href="/wg-peer-config.php?id=<?= (int)$p['id'] ?>" title="<?= htmlspecialchars(t('devices.act.download_conf')) ?>"><?= di('download') ?></a>
          <button class="iconbtn" type="button" data-show-qr-wg="<?= (int)$p['id'] ?>" title="<?= htmlspecialchars(t('devices.act.qr')) ?>"><?= di('qr') ?></button>
          <form method="post" class="inline js-del" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete_wg">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="iconbtn danger" type="submit" title="<?= htmlspecialchars(t('devices.act.delete')) ?>"><?= di('trash') ?></button>
          </form>
        </span>
      </div>
      <div class="dev-exp hidden">
        <div class="muted" style="font-size:12px"><?= htmlspecialchars($p['tunnel_address']) ?> · через «<?= htmlspecialchars($p['exit_server_name']) ?>»</div>
        <div id="qr-wg-wrap-<?= (int)$p['id'] ?>" class="hidden" style="margin-top:8px">
          <div id="qr-wg-<?= (int)$p['id'] ?>" style="background:#fff;display:inline-block;padding:10px;border-radius:8px"></div>
          <div class="muted" style="font-size:11.5px">Отсканируйте приложением WireGuard. Содержит приватный ключ — не показывайте посторонним.</div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<script src="/assets/vendor/qrcode.min.js"></script>
<script>
(function () {
  function wireQrButtons(selector, datasetKey, urlPrefix, wrapPrefix, qrPrefix) {
    var cache = {};
    document.querySelectorAll(selector).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.dataset[datasetKey];
        var wrap = document.getElementById(wrapPrefix + id);
        var target = document.getElementById(qrPrefix + id);
        if (!wrap.classList.contains('hidden')) { wrap.classList.add('hidden'); return; }
        wrap.classList.remove('hidden');
        if (cache[id]) return;
        cache[id] = true;
        fetch(urlPrefix + id + (urlPrefix.indexOf('device-config') !== -1 ? '&format=uri' : ''))
          .then(function (r) { return r.text(); })
          .then(function (text) {
            var qr = qrcode(0, 'M');
            qr.addData(text);
            qr.make();
            target.innerHTML = qr.createSvgTag(4);
          })
          .catch(function () { target.textContent = 'Не удалось загрузить данные для QR'; });
      });
    });
  }
  wireQrButtons('[data-show-qr-wg]', 'showQrWg', '/wg-peer-config.php?id=', 'qr-wg-wrap-', 'qr-wg-');

  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      navigator.clipboard.writeText(document.getElementById(btn.dataset.copy).textContent).then(function () {
        btn.classList.add('copied');
        btn.title = 'Скопировано ✓';
        setTimeout(function () { btn.classList.remove('copied'); btn.title = 'Скопировать ссылку'; }, 1500);
      });
    });
  });

  // Разворачивание строки устройства.
  document.querySelectorAll('.dev-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = btn.closest('.dev-item');
      var exp = item.querySelector('.dev-exp');
      var open = exp.classList.toggle('hidden');
      btn.classList.toggle('open', !open);
    });
  });

  // Подтверждение удаления (иконка-корзина).
  document.querySelectorAll('form.js-del').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm('Удалить «' + f.dataset.name + '»? Устройство перестанет подключаться. Действие необратимо.')) {
        e.preventDefault();
      }
    });
  });

  var cfgCache = {};
  document.querySelectorAll('[data-show-qr-cfg]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.dataset.showQrCfg;
      var wrap = document.getElementById('qr-cfg-wrap-' + key);
      var target = document.getElementById('qr-cfg-' + key);
      if (!wrap.classList.contains('hidden')) { wrap.classList.add('hidden'); return; }
      wrap.classList.remove('hidden');
      if (cfgCache[key]) return;
      cfgCache[key] = true;
      fetch(btn.dataset.url)
        .then(function (r) { return r.text(); })
        .then(function (text) {
          var qr = qrcode(0, 'M');
          qr.addData(text);
          qr.make();
          target.innerHTML = qr.createSvgTag(4);
        })
        .catch(function () { target.textContent = 'Не удалось загрузить данные для QR'; });
    });
  });
})();
</script>

<div id="dw-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px">
  <div class="card" style="max-width:560px;width:100%;margin:0">
    <div class="row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0"><?= htmlspecialchars(t('dw.title')) ?></h2>
      <button type="button" class="secondary" id="dw-close">✕</button>
    </div>
    <div id="dw-body" style="margin-top:14px"></div>
  </div>
</div>
<form method="post" id="dw-form" style="display:none">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="action" value="create_device">
  <input type="hidden" name="device_type" id="dw-f-type">
  <input type="hidden" name="protocol" id="dw-f-proto">
  <input type="hidden" name="name" id="dw-f-name">
</form>
<script>
(function () {
  var T = window.T || function (k) { return k; };
  var PROTOS = <?= json_encode(array_map(fn($m) => $m['label'], \App\DeviceInbounds::PROTOCOLS), JSON_UNESCAPED_UNICODE) ?>;
  var ENABLED = <?= json_encode(array_values($enabledProtocols)) ?>;
  var L = <?= json_encode([
    'step_type' => t('dw.step_type'), 'step_conn' => t('dw.step_conn'), 'step_name' => t('dw.step_name'),
    'router' => t('dw.router'), 'phone' => t('dw.phone'), 'tablet' => t('dw.tablet'), 'computer' => t('dw.computer'),
    'router_d' => t('dw.router_d'), 'phone_d' => t('dw.phone_d'), 'tablet_d' => t('dw.tablet_d'), 'computer_d' => t('dw.computer_d'),
    'recommended' => t('dw.recommended'), 'will_enable' => t('dw.will_enable'), 'enabled' => t('dw.enabled'),
    'name_ph' => t('dw.name_ph'), 'back' => t('dw.back'), 'next' => t('dw.next'), 'create' => t('dw.create'),
    'conn_hint' => t('dw.conn_hint'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var esc = function (s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; };

  // Рекомендованный протокол под тип устройства.
  var TYPES = {
    router:   { label: L.router,   desc: L.router_d,   icon:'🌐', proto:'wireguard', name:'Роутер' },
    phone:    { label: L.phone,    desc: L.phone_d,    icon:'📱', proto:'vless',     name:'Телефон' },
    tablet:   { label: L.tablet,   desc: L.tablet_d,   icon:'📲', proto:'vless',     name:'Планшет' },
    computer: { label: L.computer, desc: L.computer_d, icon:'💻', proto:'vless',     name:'Компьютер' },
  };
  var state = { step:0, type:'phone', proto:'vless', name:'' };
  var modal = document.getElementById('dw-modal'), body = document.getElementById('dw-body');
  function open(){ state={step:0,type:'phone',proto:'vless',name:''}; modal.style.display='flex'; render(); }
  function close(){ modal.style.display='none'; }
  document.getElementById('dw-open').addEventListener('click', open);
  document.getElementById('dw-close').addEventListener('click', close);
  modal.addEventListener('click', function(e){ if(e.target===modal) close(); });

  function isEnabled(p){ return ENABLED.indexOf(p) !== -1; }
  function nav(back, nextLabel, onNext){
    var h='<div class="row" style="justify-content:space-between;margin-top:18px"><div>'+(back?'<button type="button" class="secondary" id="dw-back">'+esc(L.back)+'</button>':'')+'</div><button type="button" id="dw-next">'+esc(nextLabel)+'</button></div>';
    setTimeout(function(){ var b=document.getElementById('dw-back'); if(b)b.onclick=function(){state.step--;render();}; document.getElementById('dw-next').onclick=onNext; },0);
    return h;
  }
  function render(){
    if(state.step===0){
      body.innerHTML='<p class="muted">'+esc(L.step_type)+'</p>'+Object.keys(TYPES).map(function(k){var t=TYPES[k];
        return '<div class="dw-opt" data-type="'+k+'" style="border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:8px;cursor:pointer;'+(state.type===k?'border-color:var(--accent);background:rgba(255,79,135,.06)':'')+'"><b>'+t.icon+' '+esc(t.label)+'</b><div class="muted" style="font-size:12.5px">'+esc(t.desc)+'</div></div>';
      }).join('')+nav(false,L.next,function(){ state.proto=TYPES[state.type].proto; state.step=1; render(); });
      body.querySelectorAll('[data-type]').forEach(function(el){el.onclick=function(){state.type=el.dataset.type; state.proto=TYPES[el.dataset.type].proto; render();};});
    } else if(state.step===1){
      body.innerHTML='<p class="muted">'+esc(L.step_conn)+'</p><p class="muted" style="font-size:12.5px">'+esc(L.conn_hint)+'</p>'+
        Object.keys(PROTOS).map(function(p){var rec=(p===TYPES[state.type].proto); var en=isEnabled(p);
          return '<label class="dw-proto" style="display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:9px;padding:10px 12px;margin-bottom:6px;cursor:pointer;'+(state.proto===p?'border-color:var(--accent)':'')+'"><input type="radio" name="dwp" value="'+p+'" '+(state.proto===p?'checked':'')+'><span style="flex:1">'+esc(PROTOS[p])+(rec?' <span class="badge ok">'+esc(L.recommended)+'</span>':'')+'</span>'+(en?'<span class="muted" style="font-size:12px">'+esc(L.enabled)+'</span>':'<span class="badge warn" style="font-size:11px">'+esc(L.will_enable)+'</span>')+'</label>';
        }).join('')+nav(true,L.next,function(){ var r=body.querySelector('input[name=dwp]:checked'); state.proto=r?r.value:state.proto; state.step=2; render(); });
      body.querySelectorAll('input[name=dwp]').forEach(function(r){r.onchange=function(){state.proto=r.value;};});
    } else {
      body.innerHTML='<p class="muted">'+esc(L.step_name)+'</p><input id="dw-name" placeholder="'+esc(L.name_ph)+'" value="'+esc(state.name||TYPES[state.type].name)+'" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:#18181c;color:var(--text)">'+
        '<div class="muted" style="font-size:12.5px;margin-top:8px">'+esc(TYPES[state.type].label)+' · '+esc(PROTOS[state.proto]||state.proto)+(isEnabled(state.proto)?'':' — '+esc(L.will_enable))+'</div>'+
        nav(true,L.create,function(){
          var nm=document.getElementById('dw-name').value.trim(); if(!nm){document.getElementById('dw-name').focus();return;}
          document.getElementById('dw-f-type').value=state.type;
          document.getElementById('dw-f-proto').value=state.proto;
          document.getElementById('dw-f-name').value=nm;
          document.getElementById('dw-form').submit();
        });
    }
  }
})();
</script>

<?php View::footer(); ?>
