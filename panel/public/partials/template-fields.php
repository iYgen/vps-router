<?php
/**
 * Поля формы шаблона оформления портала. Ожидает в области видимости $tpl
 * (строка portal_templates или дефолты для нового). Используется billing.php.
 */
use App\Models\PortalTemplate;

/** @var array $tpl */
$colorFields = [
    'accent'   => t('billing.design.c_accent'),
    'bg'       => t('billing.design.c_bg'),
    'surface'  => t('billing.design.c_surface'),
    'surface2' => t('billing.design.c_surface2'),
    'text'     => t('billing.design.c_text'),
    'muted'    => t('billing.design.c_muted'),
];
/** Для <input type=color> нужен #hex; если значение rgba/имя — оставим текстовым. */
$isHex = fn($v) => (bool) preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v);
?>
<label><?= htmlspecialchars(t('billing.design.t_name')) ?></label>
<input name="name" value="<?= htmlspecialchars((string) ($tpl['name'] ?? '')) ?>" required style="max-width:280px">

<label style="display:flex;align-items:center;gap:8px;margin-top:10px;cursor:pointer">
  <input type="checkbox" name="dark" value="1" <?= !empty($tpl['dark']) ? 'checked' : '' ?> style="width:auto"> <?= htmlspecialchars(t('billing.design.dark')) ?>
</label>

<div class="row" style="gap:14px;margin-top:10px">
  <?php foreach ($colorFields as $f => $lbl): $val = (string) ($tpl[$f] ?? ''); ?>
    <label style="font-size:12px">
      <div class="muted"><?= htmlspecialchars($lbl) ?></div>
      <?php if ($isHex($val) || $val === ''): ?>
        <input type="color" name="<?= $f ?>" value="<?= htmlspecialchars($val ?: '#000000') ?>" style="width:52px;height:34px;padding:2px">
      <?php else: ?>
        <input type="text" name="<?= $f ?>" value="<?= htmlspecialchars($val) ?>" style="width:120px">
      <?php endif; ?>
    </label>
  <?php endforeach; ?>
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.design.radius')) ?></div><input type="text" name="radius" value="<?= htmlspecialchars((string) ($tpl['radius'] ?? '14px')) ?>" style="width:80px"></label>
</div>

<div class="row" style="gap:14px;margin-top:10px">
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.design.plan_style')) ?></div>
    <select name="plan_style">
      <?php foreach (['cards' => t('billing.design.style_cards'), 'table' => t('billing.design.style_table'), 'rows' => t('billing.design.style_rows')] as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= ($tpl['plan_style'] ?? 'cards') === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label style="font-size:12px"><div class="muted"><?= htmlspecialchars(t('billing.design.width')) ?></div>
    <select name="layout_width">
      <?php foreach (['contained' => t('billing.design.width_contained'), 'full' => t('billing.design.width_full')] as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= ($tpl['layout_width'] ?? 'contained') === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</div>

<label style="margin-top:10px"><?= htmlspecialchars(t('billing.design.intro_html')) ?></label>
<textarea name="intro_html" rows="2" style="width:100%;font-family:ui-monospace,monospace;font-size:12.5px" placeholder="&lt;h1&gt;…&lt;/h1&gt;"><?= htmlspecialchars((string) ($tpl['intro_html'] ?? '')) ?></textarea>

<label style="margin-top:10px"><?= htmlspecialchars(t('billing.design.header_html')) ?></label>
<textarea name="header_html" rows="2" style="width:100%;font-family:ui-monospace,monospace;font-size:12.5px"><?= htmlspecialchars((string) ($tpl['header_html'] ?? '')) ?></textarea>

<label><?= htmlspecialchars(t('billing.design.footer_html')) ?></label>
<textarea name="footer_html" rows="2" style="width:100%;font-family:ui-monospace,monospace;font-size:12.5px"><?= htmlspecialchars((string) ($tpl['footer_html'] ?? '')) ?></textarea>

<label><?= htmlspecialchars(t('billing.design.custom_css')) ?></label>
<textarea name="custom_css" rows="4" style="width:100%;font-family:ui-monospace,monospace;font-size:12.5px" placeholder=".card{box-shadow:…}"><?= htmlspecialchars((string) ($tpl['custom_css'] ?? '')) ?></textarea>
