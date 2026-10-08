<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Applier;
use App\Auth;
use App\Models\AuditLog;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\View;

Auth::requireLogin();

$groupId = (int) ($_GET['id'] ?? 0);
$group = RuleGroup::find($groupId);
if (!$group) {
    http_response_code(404);
    die('Пул не найден');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_rule') {
            $type = $_POST['type'];
            $value = trim($_POST['value']);
            if ($value === '') {
                throw new \InvalidArgumentException('Значение не может быть пустым');
            }
            Rule::create($groupId, $type, $value);
            AuditLog::record('rule.add', "group=$groupId $type=$value");
            $result = (new Applier())->apply(Auth::username() ?? 'system', $action);
            View::applyResultFlash($result);
        } elseif ($action === 'delete_rule') {
            $ruleId = (int) $_POST['rule_id'];
            Rule::delete($ruleId);
            AuditLog::record('rule.delete', "group=$groupId rule=$ruleId");
            $result = (new Applier())->apply(Auth::username() ?? 'system', $action);
            View::applyResultFlash($result);
        } elseif ($action === 'rename') {
            $name = trim($_POST['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('Название не может быть пустым');
            }
            RuleGroup::rename($groupId, $name);
            AuditLog::record('group.rename', "group=$groupId -> $name");
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }

    header('Location: /group.php?id=' . $groupId);
    exit;
}

$rules = Rule::forGroup($groupId);

$typeLabels = [
    'domain_suffix' => 'Домен и поддомены (suffix)',
    'domain_full' => 'Точный домен',
    'domain_keyword' => 'Ключевое слово в домене',
    'ip_cidr' => 'IP / CIDR',
    'geosite' => 'Geosite-категория (например youtube, google)',
];

View::header('Пул: ' . $group['name']);
?>

<p><a href="/groups.php">&larr; все пулы</a></p>

<div class="card">
  <form method="post" class="row">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="rename">
    <input name="name" value="<?= htmlspecialchars($group['name']) ?>">
    <button type="submit" class="secondary">Переименовать</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Добавить правило</h2>
  <form method="post" class="row">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="add_rule">
    <select name="type">
      <?php foreach ($typeLabels as $val => $label): ?>
        <option value="<?= $val ?>"><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="value" required placeholder="youtube.com / 172.217.0.0/16 / youtube" style="min-width:260px">
    <button type="submit">Добавить</button>
  </form>
  <p class="muted">
    Suffix — совпадает домен и все поддомены (youtube.com → www.youtube.com тоже).
    Geosite — использует готовый публичный список доменов категории (скачивается sing-box'ом с GitHub при применении).
  </p>
</div>

<div class="card">
  <h2 style="margin-top:0">Правила (<?= count($rules) ?>)</h2>
  <?php if (empty($rules)): ?>
    <p class="muted">Правил пока нет — пул ничего не маршрутизирует.</p>
  <?php else: ?>
  <table>
    <tr><th>Тип</th><th>Значение</th><th></th></tr>
    <?php foreach ($rules as $r): ?>
      <tr>
        <td class="muted"><?= htmlspecialchars($typeLabels[$r['type']] ?? $r['type']) ?></td>
        <td><?= htmlspecialchars($r['value']) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Удалить правило?');">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete_rule">
            <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
            <button class="danger" type="submit">удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<?php View::footer(); ?>
