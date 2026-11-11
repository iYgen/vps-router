<?php

// Страница «Приложение»: скачать Android-клиент VPS Router и привязать его к этой панели по QR.

require __DIR__ . '/../src/bootstrap.php';

use App\AppPairing;
use App\AppRelease;
use App\Auth;
use App\Models\AuditLog;
use App\Models\Client;
use App\View;

Auth::requireLogin();
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    if (($_POST['action'] ?? '') === 'revoke') {
        AppPairing::revoke($userId, (int) ($_POST['id'] ?? 0));
        AuditLog::record('app.revoked', 'Отозван доступ приложения #' . (int) ($_POST['id'] ?? 0));
        View::flash('success', t('app.pair.revoked'));
    }
    header('Location: /app.php#connect');
    exit;
}

$info = AppRelease::info();
$downloadQr = $info ? AppRelease::baseUrl() . AppRelease::signedPath() : null;
$panelUrl = AppRelease::baseUrl();
$hostOnly = trim(preg_replace('/:\d+$/', '', parse_url($panelUrl, PHP_URL_HOST) ?? ''), '[]');
$insecure = str_starts_with($panelUrl, 'https://') && filter_var($hostOnly, FILTER_VALIDATE_IP) !== false;
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
$clients = Client::all();
$linked = AppPairing::tokens($userId);

View::header(t('app.title'), t('app.subtitle'));
?>
<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('app.install.title')) ?></h2>
  <?php if ($info === null): ?>
    <p class="muted"><?= htmlspecialchars(t('app.not_published')) ?></p>
  <?php else: ?>
    <p><?= htmlspecialchars(sprintf(t('app.version'), $info['versionName'], $info['versionCode'], round($info['size'] / 1048576, 1))) ?></p>
    <p class="muted"><?= htmlspecialchars(t('app.install.desc')) ?></p>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
      <div>
        <div id="apk-qr" style="background:#fff;display:inline-block;padding:10px;border-radius:8px"></div>
        <p class="muted" style="max-width:260px;font-size:12px"><?= htmlspecialchars(sprintf(t('app.qr_hint'), (int) (AppRelease::LINK_TTL / 60))) ?></p>
      </div>
      <div>
        <a class="btn" href="/api/app.php?action=download"><?= htmlspecialchars(t('app.download')) ?></a>
        <p class="muted" style="font-size:12px;margin-top:8px">SHA-256: <code style="word-break:break-all"><?= htmlspecialchars($info['sha256']) ?></code></p>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card" id="connect">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('settings.app.title')) ?></h2>
  <p class="muted"><?= htmlspecialchars(t('app.pair.desc')) ?></p>
  <label class="muted" for="app-device" style="display:block;margin-bottom:4px"><?= htmlspecialchars(t('app.pair.device')) ?></label>
  <select id="app-device" style="min-width:260px">
    <option value=""><?= htmlspecialchars(t('app.pair.device_choose')) ?></option>
    <?php foreach ($clients as $c): ?>
      <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="muted" for="app-alt" style="display:block;margin:10px 0 4px"><?= htmlspecialchars(t('settings.app.alt')) ?></label>
  <textarea id="app-alt" rows="2" style="width:100%;max-width:520px" placeholder="http://203.0.113.5:8443"></textarea>
  <p class="muted" style="margin-top:4px"><?= htmlspecialchars(t('settings.app.alt_hint')) ?></p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">
    <button type="button" id="app-qr-show"><?= htmlspecialchars(t('settings.app.show')) ?></button>
  </div>
  <div id="app-qr-wrap" class="hidden">
    <div id="app-qr" style="background:#fff;display:inline-block;padding:10px;border-radius:8px"></div>
    <p class="muted" id="app-qr-note" style="font-size:12px;max-width:420px"></p>
  </div>

  <?php if ($linked): ?>
    <h3 style="margin-bottom:6px"><?= htmlspecialchars(t('app.pair.linked')) ?></h3>
    <table>
      <?php foreach ($linked as $l): ?>
        <tr>
          <td><?= htmlspecialchars((string) $l['name']) ?><?= $l['client_name'] ? ' <span class="muted">· ' . htmlspecialchars((string) $l['client_name']) . '</span>' : '' ?></td>
          <td class="muted"><?= htmlspecialchars(sprintf(t('app.pair.last_used'), $l['last_used_at'] ?? '—', $l['last_ip'] ?? '—')) ?></td>
          <td>
            <form method="post" style="margin:0" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('app.pair.revoke_confirm'), $jsonFlags)) ?>)">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
              <button type="submit" class="secondary"><?= htmlspecialchars(t('app.pair.revoke')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('app.updates.title')) ?></h2>
  <p class="muted"><?= htmlspecialchars(t('app.updates.desc')) ?></p>
</div>

<script src="/assets/vendor/qrcode.min.js"></script>
<script>
  (function () {
    function qrInto(el, text) {
      try {
        var qr = qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        el.innerHTML = qr.createSvgTag(4);
      } catch (e) { el.textContent = 'QR error'; }
    }
    var apk = <?= json_encode($downloadQr, $jsonFlags) ?>;
    if (apk) qrInto(document.getElementById('apk-qr'), apk);

    var csrf = <?= json_encode(Auth::csrfToken(), $jsonFlags) ?>;
    var insecure = <?= $insecure ? 'true' : 'false' ?>;
    var noteTpl = <?= json_encode(t('app.pair.qr_note'), $jsonFlags) ?>;
    var btn = document.getElementById('app-qr-show');
    btn.addEventListener('click', function () {
      var alts = document.getElementById('app-alt').value.split(/\r?\n/).map(function (l) { return l.trim(); })
        .filter(function (l) { return /^https?:\/\/\S+$/i.test(l); });
      var dev = document.getElementById('app-device').value;
      btn.disabled = true;
      fetch('/api/app.php?action=pair', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ alt: alts, insecure: insecure, client_id: dev === '' ? null : parseInt(dev, 10) })
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.link) throw new Error(j.error || 'error');
        document.getElementById('app-qr-wrap').classList.remove('hidden');
        qrInto(document.getElementById('app-qr'), j.link);
        document.getElementById('app-qr-note').textContent = noteTpl.replace('%d', Math.round(j.ttl / 60));
      }).catch(function (e) {
        document.getElementById('app-qr-wrap').classList.remove('hidden');
        document.getElementById('app-qr').textContent = 'Ошибка: ' + e.message;
      }).finally(function () { btn.disabled = false; });
    });
  })();
</script>
<?php View::footer(); ?>
