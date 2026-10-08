<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

Auth::requireLogin();

// Вкладка Keenetic — часть модуля router-keenetic; при его выключении страница закрыта.
if (!\App\Modules\ModuleManager::featureActive('keenetic-page')) {
    header('Location: /dashboard.php');
    exit;
}

View::header(t('nav.keenetic'), t('cp.subtitle'));
?>

<div class="cp-tabs">
  <button class="cp-tab active" data-tab="profiles"><?= htmlspecialchars(t('cp.tab.profiles')) ?></button>
  <button class="cp-tab" data-tab="devices"><?= htmlspecialchars(t('cp.tab.devices')) ?></button>
</div>

<div id="cp-profiles" class="cp-panel"></div>
<div id="cp-devices" class="cp-panel hidden"></div>

<div class="modal-overlay hidden" id="modal-overlay"></div>
<div class="toast-stack" id="toast-stack"></div>

<script src="/assets/js/client-policy.js?v=<?= filemtime(__DIR__ . '/assets/js/client-policy.js') ?>"></script>

<?php View::footer(); ?>
