<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Modules\ModuleManager;
use App\PortalAuth;
use App\PortalView;
use App\View;

if (!ModuleManager::featureActive('billing') || Setting::get('billing_portal_enabled', '0') !== '1') {
    http_response_code(404);
    die('Not found');
}

$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
$valid = Subscriber::findByResetToken($token) !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    try {
        if (PortalAuth::resetWithToken($token, (string) ($_POST['new_password'] ?? ''))) {
            View::flash('success', t('portal.reset_done'));
            header('Location: /portal.php');
            exit;
        }
        View::flash('error', t('portal.reset_invalid'));
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }
    header('Location: /portal-reset.php?token=' . urlencode($token));
    exit;
}

PortalView::header(t('portal.reset_title'));
?>
<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('portal.reset_title')) ?></h2>
  <?php if (!$valid): ?>
    <p class="flash error"><?= htmlspecialchars(t('portal.reset_invalid')) ?></p>
    <a class="btn" href="/portal-login.php"><?= htmlspecialchars(t('portal.login_btn')) ?></a>
  <?php else: ?>
    <form method="post">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <label><?= htmlspecialchars(t('portal.new_password')) ?> <span class="muted">(<?= htmlspecialchars(t('portal.pw_hint')) ?>)</span></label>
      <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
      <div style="margin-top:14px"><button type="submit"><?= htmlspecialchars(t('portal.reset_btn')) ?></button></div>
    </form>
  <?php endif; ?>
</div>
<?php PortalView::footer(); ?>
