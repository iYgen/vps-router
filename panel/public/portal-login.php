<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\Setting;
use App\Modules\ModuleManager;
use App\PortalAuth;
use App\PortalView;
use App\View;

// Портал доступен, только если включён модуль биллинга И админ включил портал.
if (!ModuleManager::featureActive('billing') || Setting::get('billing_portal_enabled', '0') !== '1') {
    http_response_code(404);
    die('Not found');
}
$registerOpen = Setting::get('billing_portal_register', '1') === '1';

if (PortalAuth::check() && ($_SERVER['REQUEST_METHOD'] !== 'POST')) {
    header('Location: /portal.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'logout') {
            PortalAuth::logout();
            header('Location: /portal-login.php');
            exit;
        }
        if ($action === 'resend') {
            $id = PortalAuth::currentId();
            if ($id) {
                PortalAuth::sendVerification($id);
                View::flash('success', t('portal.verify_sent'));
            }
            header('Location: /portal.php');
            exit;
        }
        if ($action === 'login') {
            if (!PortalAuth::login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                throw new \RuntimeException(t('portal.err.login'));
            }
            header('Location: /portal.php');
            exit;
        }
        if ($action === 'forgot') {
            PortalAuth::requestReset((string) ($_POST['email'] ?? ''));
            View::flash('success', t('portal.reset_sent')); // не раскрываем, есть ли такой email
            header('Location: /portal-login.php');
            exit;
        }
        if ($action === 'register') {
            if (!$registerOpen) {
                throw new \RuntimeException(t('portal.err.register_closed'));
            }
            PortalAuth::register((string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            header('Location: /portal.php');
            exit;
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
        header('Location: /portal-login.php');
        exit;
    }
}

PortalView::header(t('portal.login_title'));
?>
<div class="card">
  <h2><?= htmlspecialchars(t('portal.login_title')) ?></h2>
  <form method="post">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="login">
    <label>Email</label>
    <input type="email" name="email" required autocomplete="email">
    <label><?= htmlspecialchars(t('portal.password')) ?></label>
    <input type="password" name="password" required autocomplete="current-password">
    <div style="margin-top:14px"><button type="submit"><?= htmlspecialchars(t('portal.login_btn')) ?></button></div>
  </form>
  <details style="margin-top:12px"><summary class="muted" style="cursor:pointer;font-size:13px"><?= htmlspecialchars(t('portal.forgot')) ?></summary>
    <form method="post" style="margin-top:10px">
      <?= Auth::csrfField() ?><input type="hidden" name="action" value="forgot">
      <label>Email</label><input type="email" name="email" required autocomplete="email">
      <div style="margin-top:10px"><button type="submit" class="secondary"><?= htmlspecialchars(t('portal.reset_btn')) ?></button></div>
    </form>
  </details>
</div>

<?php if ($registerOpen): ?>
<div class="card">
  <h3><?= htmlspecialchars(t('portal.register_title')) ?></h3>
  <form method="post">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="register">
    <label><?= htmlspecialchars(t('portal.name')) ?></label>
    <input type="text" name="name" required>
    <label>Email</label>
    <input type="email" name="email" required autocomplete="email">
    <label><?= htmlspecialchars(t('portal.password')) ?> <span class="muted">(<?= htmlspecialchars(t('portal.pw_hint')) ?>)</span></label>
    <input type="password" name="password" required autocomplete="new-password" minlength="8">
    <div style="margin-top:14px"><button type="submit" class="secondary"><?= htmlspecialchars(t('portal.register_btn')) ?></button></div>
  </form>
</div>
<?php endif; ?>
<?php PortalView::footer(); ?>
