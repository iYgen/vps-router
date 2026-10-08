<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Applier;
use App\Auth;
use App\Models\AuditLog;
use App\Models\ExitServer;
use App\Models\Server;
use App\View;

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create' || $action === 'update') {
            $data = [
                'name' => trim($_POST['name']),
                'endpoint_host' => trim($_POST['endpoint_host']),
                'endpoint_port' => (int) $_POST['endpoint_port'],
                'wg_peer_pubkey' => trim($_POST['wg_peer_pubkey']),
                'wg_peer_psk' => trim($_POST['wg_peer_psk']) ?: null,
                'wg_local_privkey' => trim($_POST['wg_local_privkey']),
                'wg_local_address' => trim($_POST['wg_local_address']),
                'interface_name' => trim($_POST['interface_name']),
                'amnezia_params' => trim($_POST['amnezia_params']) ?: null,
                'status' => $_POST['status'],
                'pool_label' => trim($_POST['pool_label'] ?? '') ?: null,
            ];

            if ($data['amnezia_params']) {
                json_decode($data['amnezia_params'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException(t('exit.err.json'));
                }
            }

            if ($action === 'create') {
                if ($data['wg_local_privkey'] === '') {
                    throw new \InvalidArgumentException(t('exit.err.privkey'));
                }
                ExitServer::create($data);
                AuditLog::record('exit_server.create', $data['name']);
            } else {
                if ($data['wg_local_privkey'] === '') {
                    // Поле оставили пустым намеренно — не перезаписываем существующий ключ.
                    $existing = ExitServer::find((int) $_POST['id']);
                    $data['wg_local_privkey'] = $existing['wg_local_privkey'] ?? '';
                }
                ExitServer::update((int) $_POST['id'], $data);
                AuditLog::record('exit_server.update', $data['name']);
            }

            $result = (new Applier())->apply(Auth::username() ?? 'system', $action);
            View::applyResultFlash($result);
        } elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            ExitServer::delete($id);
            AuditLog::record('exit_server.delete', "id=$id");
            $result = (new Applier())->apply(Auth::username() ?? 'system', $action);
            View::applyResultFlash($result);
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }

    header('Location: /servers.php');
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = $editId ? ExitServer::find($editId) : null;

$generatedKey = null;
if (isset($_GET['generate']) && function_exists('shell_exec')) {
    $priv = trim((string) shell_exec('wg genkey 2>/dev/null'));
    if ($priv !== '') {
        $generatedKey = $priv;
    }
}

View::header(t('exit.page_title'), t('exit.page_subtitle'));

/** Небольшая карточка-кнопка: статус → класс бейджа. */
$statusBadge = static function (string $st): string {
    $map = ['active' => 'ok', 'online' => 'ok', 'standby' => 'warn', 'disabled' => 'unknown', 'offline' => 'down', 'unknown' => 'unknown'];
    return $map[$st] ?? 'unknown';
};
?>

<style>
  .srv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
  .srv-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 15px; display: flex; flex-direction: column; gap: 8px; }
  .srv-card .srv-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
  .srv-card .srv-name { font-weight: 650; word-break: break-word; }
  .srv-card .srv-meta { font-size: 12.5px; color: var(--text-muted); line-height: 1.5; }
  .srv-card .srv-meta code { font-family: ui-monospace, monospace; }
  .srv-card .srv-actions { display: flex; gap: 6px; margin-top: 2px; align-items: center; }
  .srv-card .srv-actions form { margin: 0; }
  .srv-card .srv-actions .icon-btn { padding: 7px; line-height: 0; display: inline-flex; }
  .srv-sec-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 4px 0 2px; }
  .srv-sec-head h2 { margin: 0; }
  .srv-sec-note { font-size: 12.5px; color: var(--text-muted); margin: 0 0 12px; }
</style>

<?php if (\App\Modules\ModuleManager::featureActive('free-exits')): ?>
<div class="card">
  <div class="row" style="justify-content:space-between;align-items:center">
    <div>
      <h2 style="margin:0"><?= htmlspecialchars(t('fx.card_title')) ?></h2>
      <p class="muted" style="margin:4px 0 0"><?= htmlspecialchars(t('fx.card_desc')) ?></p>
    </div>
    <button type="button" onclick="openFreeExitModal()"><?= htmlspecialchars(t('fx.add_btn')) ?></button>
  </div>
</div>
<?php endif; ?>

<?php
// --- Входные узлы (инфраструктура): карточки-ссылки в Инфраструктуру ---
$entryServers = Server::all();
?>
<div class="card">
  <div class="srv-sec-head"><h2><?= htmlspecialchars(t('exit.sec.entry')) ?></h2></div>
  <p class="srv-sec-note"><?= htmlspecialchars(t('exit.sec.entry_note')) ?></p>
  <?php if ($entryServers): ?>
  <div class="srv-grid">
    <?php foreach ($entryServers as $srv): ?>
      <div class="srv-card">
        <div class="srv-top">
          <span class="srv-name"><?= htmlspecialchars($srv['name']) ?><?php if ((int)($srv['is_self'] ?? 0) === 1): ?> <span class="muted" style="font-weight:400">(<?= htmlspecialchars(t('exit.card.this')) ?>)</span><?php endif; ?></span>
          <span class="badge <?= $statusBadge((string)($srv['status'] ?? 'unknown')) ?>"><?= htmlspecialchars((string)($srv['status'] ?? '—')) ?></span>
        </div>
        <div class="srv-meta">
          <?= htmlspecialchars((string)($srv['role'] ?? '')) ?><?php if (!empty($srv['host'])): ?> · <code><?= htmlspecialchars($srv['host']) ?>:<?= (int)($srv['ssh_port'] ?? 22) ?></code><?php endif; ?>
          <?php if (!empty($srv['country'])): ?><br><?= htmlspecialchars($srv['country']) ?><?php endif; ?>
        </div>
        <div class="srv-actions">
          <a class="btn secondary" href="/dashboard.php#server-<?= (int)$srv['id'] ?>"><?= htmlspecialchars(t('exit.card.open_infra')) ?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?><p class="muted"><?= htmlspecialchars(t('exit.empty_entry')) ?></p><?php endif; ?>
</div>

<?php
// --- Выходные серверы: карточки + «+ Добавить сервер» / форма ---
$exitServers = ExitServer::all();
?>
<div class="card">
  <div class="srv-sec-head">
    <h2><?= htmlspecialchars(t('exit.sec.exit')) ?></h2>
    <button type="button" id="btn-add-server"><?= htmlspecialchars(t('exit.add_server')) ?></button>
  </div>
  <?php if ($exitServers): ?>
  <div class="srv-grid">
    <?php foreach ($exitServers as $s): $isFree = (($s['source'] ?? 'own') === 'free'); ?>
      <div class="srv-card">
        <div class="srv-top">
          <span class="srv-name"><?= htmlspecialchars($s['name']) ?><?php if ($isFree): ?> <span class="badge unknown" title="free-vpn-subscriptions"><?= htmlspecialchars(t('fx.badge_free')) ?><?= $s['country'] ? ' ' . htmlspecialchars($s['country']) : '' ?></span><?php endif; ?></span>
          <span class="badge <?= $statusBadge((string)$s['status']) ?>"><?= htmlspecialchars((string)$s['status']) ?></span>
        </div>
        <div class="srv-meta">
          <code><?= htmlspecialchars($s['endpoint_host']) ?>:<?= (int)$s['endpoint_port'] ?></code><br>
          <?= htmlspecialchars(t('exit.col.iface')) ?>: <code><?= htmlspecialchars($s['interface_name']) ?></code>
          <?php if (!empty($s['pool_label'])): ?><br><?= htmlspecialchars(t('exit.col.pool')) ?>: <?= htmlspecialchars($s['pool_label']) ?><?php endif; ?>
        </div>
        <?php if (!$isFree): ?>
        <div class="srv-actions">
          <a class="btn secondary icon-btn" title="<?= htmlspecialchars(t('exit.card.settings')) ?>" href="/servers.php?edit=<?= (int)$s['id'] ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
          </a>
          <form class="inline" method="post" onsubmit="return confirm('<?= htmlspecialchars(t('exit.del_confirm', str_replace("'", '', $s['name'])), ENT_QUOTES) ?>');">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="danger icon-btn" type="submit" title="<?= htmlspecialchars(t('exit.act.delete')) ?>">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6"/></svg>
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?><p class="muted" id="exit-empty"><?= htmlspecialchars(t('exit.empty_exits')) ?></p><?php endif; ?>
</div>

<div class="card" id="server-form-card"<?= $editing ? '' : ' hidden' ?>>
  <h2 style="margin-top:0"><?= $editing ? htmlspecialchars(t('exit.edit')) : htmlspecialchars(t('exit.add')) ?></h2>
  <form method="post">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

    <table>
      <tr><td><?= htmlspecialchars(t('exit.f.name')) ?></td><td><input name="name" required value="<?= htmlspecialchars($editing['name'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('exit.f.name_ph')) ?>"></td></tr>
      <tr><td><?= htmlspecialchars(t('exit.f.endpoint_host')) ?></td><td><input name="endpoint_host" required value="<?= htmlspecialchars($editing['endpoint_host'] ?? '') ?>" placeholder="exit1.example.com"></td></tr>
      <tr><td><?= htmlspecialchars(t('exit.f.endpoint_port')) ?></td><td><input name="endpoint_port" type="number" required value="<?= htmlspecialchars($editing['endpoint_port'] ?? '51820') ?>"></td></tr>
      <tr><td><?= htmlspecialchars(t('exit.f.peer_pubkey')) ?></td><td><input name="wg_peer_pubkey" required value="<?= htmlspecialchars($editing['wg_peer_pubkey'] ?? '') ?>"></td></tr>
      <tr><td><?= htmlspecialchars(t('exit.f.psk')) ?></td><td><input name="wg_peer_psk" value="<?= htmlspecialchars($editing['wg_peer_psk'] ?? '') ?>"></td></tr>
      <tr>
        <td><?= htmlspecialchars(t('exit.f.local_privkey')) ?></td>
        <td>
          <?php if ($editing && !$generatedKey): ?>
            <input name="wg_local_privkey" placeholder="<?= htmlspecialchars(t('exit.f.privkey_keep')) ?>">
          <?php else: ?>
            <input name="wg_local_privkey" required value="<?= htmlspecialchars($generatedKey ?? '') ?>">
          <?php endif; ?>
          <a class="muted" href="?generate=1<?= $editId ? "&edit=$editId" : '' ?>"><?= htmlspecialchars(t('exit.f.gen_pair')) ?></a>
        </td>
      </tr>
      <tr><td><?= htmlspecialchars(t('exit.f.local_address')) ?></td><td><input name="wg_local_address" required value="<?= htmlspecialchars($editing['wg_local_address'] ?? '10.90.0.2/32') ?>"></td></tr>
      <tr><td><?= htmlspecialchars(t('exit.f.iface')) ?></td><td><input name="interface_name" required value="<?= htmlspecialchars($editing['interface_name'] ?? '') ?>" placeholder="awg-ex1"></td></tr>
      <tr><td><?= htmlspecialchars(t('exit.f.amnezia')) ?></td><td><input name="amnezia_params" value="<?= htmlspecialchars($editing['amnezia_params'] ?? '') ?>" placeholder='{"Jc":4,"Jmin":40,"Jmax":70}'></td></tr>
      <tr><td><?= htmlspecialchars(t('exit.f.status')) ?></td><td>
        <select name="status">
          <?php foreach (['active' => t('exit.st.active'), 'standby' => t('exit.st.standby'), 'disabled' => t('exit.st.disabled')] as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($editing['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </td></tr>
      <tr>
        <td><?= htmlspecialchars(t('exit.f.pool')) ?></td>
        <td>
          <input name="pool_label" value="<?= htmlspecialchars($editing['pool_label'] ?? '') ?>" placeholder="eu-1">
          <p class="muted" style="margin:4px 0 0"><?= htmlspecialchars(t('exit.f.pool_note')) ?></p>
        </td>
      </tr>
    </table>
    <div class="row" style="margin-top:12px">
      <button type="submit"><?= $editing ? htmlspecialchars(t('common.save')) : htmlspecialchars(t('exit.add')) ?></button>
      <?php if ($editing): ?><a class="btn secondary" href="/servers.php"><?= htmlspecialchars(t('common.cancel')) ?></a><?php endif; ?>
    </div>
  </form>
</div>

<script>
  // «+ Добавить сервер» раскрывает форму (по умолчанию скрыта). В режиме
  // редактирования (?edit=) форма уже открыта сервером.
  (function () {
    var btn = document.getElementById('btn-add-server');
    var form = document.getElementById('server-form-card');
    if (btn && form) {
      btn.addEventListener('click', function () {
        form.hidden = false;
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var first = form.querySelector('input[name="name"]');
        if (first) first.focus();
      });
    }
  })();
</script>

<?php if (\App\Modules\ModuleManager::featureActive('free-exits')) { include __DIR__ . '/partials/free-exit-modal.php'; } ?>

<?php View::footer(); ?>
