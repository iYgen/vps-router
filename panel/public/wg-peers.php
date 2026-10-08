<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\AuditLog;
use App\Models\ExitServer;
use App\Models\ExitServerPeer;
use App\Provisioner;
use App\View;

Auth::requireLogin();

$exitServerId = (int) ($_GET['exit_server_id'] ?? 0);
$es = $exitServerId ? ExitServer::find($exitServerId) : null;
if (!$es) {
    http_response_code(404);
    die('Exit-сервер не найден');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new \InvalidArgumentException(t('devices.err.name_empty'));
            }
            Provisioner::addWireguardPeer($exitServerId, $name);
            AuditLog::record('wg_peer.add', "exit_server=$exitServerId name=$name");
            View::flash('success', t('wgp.added', $name));
        } elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            Provisioner::removeWireguardPeer($id);
            AuditLog::record('wg_peer.remove', "id=$id");
            View::flash('success', t('devices.deleted'));
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }

    header('Location: /wg-peers.php?exit_server_id=' . $exitServerId);
    exit;
}

$peers = ExitServerPeer::forExitServer($exitServerId);
$ready = ($es['protocol'] ?? '') === 'wireguard' && ($es['provision_status'] ?? '') === 'provisioned';

View::header(t('wgp.title', $es['name']), t('wgp.subtitle'));
?>

<?php if (!$ready): ?>
<div class="card">
  <p>
    <?php if (($es['protocol'] ?? '') !== 'wireguard'): ?>
      <?= htmlspecialchars(t('wgp.not_wg', $es['protocol'])) ?>
    <?php else: ?>
      <?= htmlspecialchars(t('wgp.not_ready')) ?>
    <?php endif; ?>
  </p>
</div>
<?php else: ?>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('wgp.new')) ?></h2>
  <p class="muted">
    <?= htmlspecialchars(t('wgp.new_desc')) ?>
  </p>
  <form method="post" class="row">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input name="name" required placeholder="<?= htmlspecialchars(t('wgp.new_ph')) ?>">
    <button type="submit"><?= htmlspecialchars(t('wgp.add_apply')) ?></button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('wgp.list', count($peers))) ?></h2>
  <?php if (empty($peers)): ?>
    <p class="muted"><?= htmlspecialchars(t('wgp.empty')) ?></p>
  <?php endif; ?>
  <?php foreach ($peers as $p): ?>
    <div class="card" style="margin-bottom:8px">
      <div class="row" style="justify-content:space-between">
        <div>
          <strong><?= htmlspecialchars($p['name']) ?></strong>
          <span class="muted"><?= htmlspecialchars($p['tunnel_address']) ?></span>
          <?= $p['applied_at'] ? '<span class="badge ok">'.htmlspecialchars(t('wgp.applied')).'</span>' : '<span class="badge warn">'.htmlspecialchars(t('wgp.not_applied')).'</span>' ?>
        </div>
        <div class="row">
          <a class="btn secondary" href="/wg-peer-config.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars(t('wgp.download')) ?></a>
          <button type="button" class="secondary" data-show-qr="<?= (int)$p['id'] ?>"><?= htmlspecialchars(t('wgp.show_qr')) ?></button>
          <form method="post" class="inline" onsubmit="return confirm('<?= htmlspecialchars(t('wgp.del_confirm', str_replace("'", '', $p['name'])), ENT_QUOTES) ?>');">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="danger" type="submit"><?= htmlspecialchars(t('wgp.del')) ?></button>
          </form>
        </div>
      </div>
      <div id="qr-wrap-<?= (int)$p['id'] ?>" class="hidden" style="margin-top:12px">
        <div id="qr-<?= (int)$p['id'] ?>" style="background:#fff;display:inline-block;padding:12px;border-radius:8px"></div>
        <p class="muted"><?= htmlspecialchars(t('wgp.qr_hint')) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script src="/assets/vendor/qrcode.min.js"></script>
<script>
(function () {
  var cache = {};
  document.querySelectorAll('[data-show-qr]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-show-qr');
      var wrap = document.getElementById('qr-wrap-' + id);
      var target = document.getElementById('qr-' + id);
      if (!wrap.classList.contains('hidden')) {
        wrap.classList.add('hidden');
        return;
      }
      wrap.classList.remove('hidden');
      if (cache[id]) return;
      cache[id] = true;
      fetch('/wg-peer-config.php?id=' + id)
        .then(function (r) { return r.text(); })
        .then(function (text) {
          var qr = qrcode(0, 'M');
          qr.addData(text);
          qr.make();
          target.innerHTML = qr.createSvgTag(4);
        })
        .catch(function () {
          target.textContent = <?= json_encode(t('wgp.qr_err'), JSON_UNESCAPED_UNICODE) ?>;
        });
    });
  });
})();
</script>

<?php endif; ?>

<?php View::footer(); ?>
