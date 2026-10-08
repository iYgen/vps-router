<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Models\Setting;
use App\Modules\ModuleManager;
use App\PortalAuth;
use App\PortalView;

if (!ModuleManager::featureActive('billing') || Setting::get('billing_portal_enabled', '0') !== '1') {
    http_response_code(404);
    die('Not found');
}

$ok = PortalAuth::verifyToken((string) ($_GET['token'] ?? ''));

PortalView::header(t('portal.verify_title'));
?>
<div class="card">
  <h2 style="margin-top:0"><?= htmlspecialchars(t('portal.verify_title')) ?></h2>
  <?php if ($ok): ?>
    <p class="flash success" style="margin:0 0 14px"><?= htmlspecialchars(t('portal.verify_ok')) ?></p>
  <?php else: ?>
    <p class="flash error" style="margin:0 0 14px"><?= htmlspecialchars(t('portal.verify_fail')) ?></p>
  <?php endif; ?>
  <a class="btn" href="/portal.php"><?= htmlspecialchars(t('portal.to_account')) ?></a>
</div>
<?php PortalView::footer(); ?>
