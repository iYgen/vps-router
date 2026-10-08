<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\AuditLog;
use App\View;

Auth::requireLogin();

$category = $_GET['category'] ?? null;
if (!in_array($category, AuditLog::CATEGORIES, true)) {
    $category = null;
}
$tabs = [
    null => t('audit.tab.all'),
    'action' => t('audit.tab.action'),
    'auth' => t('audit.tab.auth'),
    'block' => t('audit.tab.block'),
];

View::header(t('audit.title'));
?>

<div class="row" style="margin-bottom:14px">
  <?php foreach ($tabs as $value => $label): ?>
    <?php $active = $category === $value; ?>
    <a href="?<?= $value !== null ? 'category=' . urlencode($value) : '' ?>"
       class="<?= $active ? 'secondary' : '' ?>"
       style="padding:6px 14px;border-radius:8px;text-decoration:none;font-size:13px;<?= $active ? 'background:var(--accent);color:#fff' : 'background:var(--surface-elevated);color:var(--text-secondary);border:1px solid var(--border)' ?>">
      <?= htmlspecialchars($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <table>
    <tr><th><?= htmlspecialchars(t('audit.col.time')) ?></th><th><?= htmlspecialchars(t('audit.col.user')) ?></th><th><?= htmlspecialchars(t('audit.col.action')) ?></th><th><?= htmlspecialchars(t('audit.col.details')) ?></th><th><?= htmlspecialchars(t('audit.col.ip')) ?></th></tr>
    <?php foreach (AuditLog::recent(200, $category) as $row): ?>
      <tr>
        <td class="muted"><?= htmlspecialchars($row['ts']) ?></td>
        <td><?= htmlspecialchars($row['username']) ?></td>
        <td><?= htmlspecialchars($row['action']) ?></td>
        <td class="muted"><?= htmlspecialchars($row['details']) ?></td>
        <td class="muted"><?= htmlspecialchars($row['ip'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php View::footer(); ?>
