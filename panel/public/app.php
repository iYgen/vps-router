<?php

// Страница «Приложение»: скачать Android-клиент VPS Router и подключить его к этой панели.

require __DIR__ . '/../src/bootstrap.php';

use App\AppRelease;
use App\Auth;
use App\View;

Auth::requireLogin();

$info = AppRelease::info();
$downloadQr = $info ? AppRelease::baseUrl() . AppRelease::signedPath() : null;
$panelUrl = AppRelease::baseUrl();
$hostOnly = trim(preg_replace('/:\d+$/', '', parse_url($panelUrl, PHP_URL_HOST) ?? ''), '[]');
$insecure = str_starts_with($panelUrl, 'https://') && filter_var($hostOnly, FILTER_VALIDATE_IP) !== false;
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

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
  <p class="muted"><?= htmlspecialchars(t('settings.app.desc')) ?></p>
  <label class="muted" for="app-alt" style="display:block;margin-bottom:4px"><?= htmlspecialchars(t('settings.app.alt')) ?></label>
  <textarea id="app-alt" rows="2" style="width:100%;max-width:520px" placeholder="http://203.0.113.5:8443"></textarea>
  <p class="muted" style="margin-top:4px"><?= htmlspecialchars(t('settings.app.alt_hint')) ?></p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">
    <button type="button" id="app-qr-show"><?= htmlspecialchars(t('settings.app.show')) ?></button>
    <button type="button" class="secondary" id="app-qr-copy"><?= htmlspecialchars(t('settings.app.copy')) ?></button>
  </div>
  <div id="app-qr-wrap" class="hidden">
    <div id="app-qr" style="background:#fff;display:inline-block;padding:10px;border-radius:8px"></div>
    <div class="muted" id="app-qr-link" style="word-break:break-all;font-size:12px;margin-top:6px"></div>
  </div>
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

    var base = <?= json_encode($panelUrl, $jsonFlags) ?>;
    var user = <?= json_encode(Auth::username() ?? 'admin', $jsonFlags) ?>;
    var insecure = <?= $insecure ? 'true' : 'false' ?>;
    function link() {
      var s = 'vpsrouter://panel?url=' + encodeURIComponent(base) + '&user=' + encodeURIComponent(user);
      document.getElementById('app-alt').value.split(/\r?\n/).map(function (l) { return l.trim(); })
        .filter(function (l) { return /^https?:\/\/\S+$/i.test(l); })
        .forEach(function (l) { s += '&alt=' + encodeURIComponent(l); });
      if (insecure) s += '&insecure=1';
      return s;
    }
    function render() {
      qrInto(document.getElementById('app-qr'), link());
      document.getElementById('app-qr-link').textContent = link();
    }
    document.getElementById('app-qr-show').addEventListener('click', function () {
      document.getElementById('app-qr-wrap').classList.remove('hidden');
      render();
    });
    document.getElementById('app-alt').addEventListener('input', function () {
      if (!document.getElementById('app-qr-wrap').classList.contains('hidden')) render();
    });
    document.getElementById('app-qr-copy').addEventListener('click', function () {
      var t = link();
      if (navigator.clipboard) navigator.clipboard.writeText(t).catch(function () { window.prompt('', t); });
      else window.prompt('', t);
    });
  })();
</script>
<?php View::footer(); ?>
