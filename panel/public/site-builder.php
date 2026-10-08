<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\Setting;
use App\Models\SiteDesign;
use App\Modules\ModuleManager;
use App\Site\TemplateRegistry;
use App\View;

Auth::requireLogin();

if (!ModuleManager::featureActive('site-builder')) {
    View::header(t('nav.site'));
    echo '<div class="card"><p class="muted">' . htmlspecialchars(t('site.disabled')) . ' <a href="/modules.php">' . htmlspecialchars(t('billing.enable_link')) . '</a></p></div>';
    View::footer();
    exit;
}

$draft = SiteDesign::ensureDraft();
$draftId = (int) $draft['id'];
$cfg = SiteDesign::decode($draft);
$template = (string) $draft['template'];

/** Слить массив в ветку конфига и сохранить черновик. */
$saveCfg = function (array $cfg) use ($draftId, $template) {
    SiteDesign::update($draftId, $template, $cfg);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $a = $_POST['action'] ?? '';
    $hash = (string) ($_POST['hash'] ?? '');
    try {
        if ($a === 'pick_template') {
            $tk = (string) ($_POST['template'] ?? '');
            if (TemplateRegistry::get($tk)) {
                SiteDesign::update($draftId, $tk, $cfg);
            }
        } elseif ($a === 'save_brand') {
            $cfg['brand'] = array_merge($cfg['brand'] ?? [], ['name' => trim((string) ($_POST['brand_name'] ?? ''))]);
            $cfg['seo'] = array_merge($cfg['seo'] ?? [], ['title' => trim((string) ($_POST['seo_title'] ?? ''))]);
            Setting::set('site_public_host', strtolower(trim((string) ($_POST['site_host'] ?? ''))));
            if (!empty($_SERVER['HTTP_HOST'])) {
                Setting::set('panel_public_host', (string) $_SERVER['HTTP_HOST']);
            }
            // логотип (необязательно)
            if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
                $info = @getimagesize($_FILES['logo']['tmp_name']);
                $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif', IMAGETYPE_SVG => 'svg'][$info[2] ?? 0] ?? null;
                if (($_FILES['logo']['size'] ?? 0) <= 1048576 && $ext) {
                    $dir = __DIR__ . '/uploads/site';
                    @mkdir($dir, 0755, true);
                    $fn = 'logo-' . time() . '.' . $ext;
                    if (@move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $fn)) {
                        $cfg['brand']['logo'] = '/uploads/site/' . $fn;
                    }
                }
            }
            if (!empty($_POST['logo_clear'])) {
                $cfg['brand']['logo'] = '';
            }
            $saveCfg($cfg);
        } elseif ($a === 'save_palette') {
            $keys = ['primary', 'bg', 'surface', 'surface2', 'text', 'muted', 'border', 'price', 'popular'];
            $pal = $cfg['palette'] ?? [];
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $pal[$k] = trim((string) $_POST[$k]);
                }
            }
            $pal['dark'] = !empty($_POST['dark']) ? 1 : 0;
            $cfg['palette'] = $pal;
            $saveCfg($cfg);
        } elseif ($a === 'save_typography') {
            $cfg['typography'] = array_merge($cfg['typography'] ?? [], [
                'heading' => trim((string) ($_POST['heading'] ?? 'Inter')),
                'body'    => trim((string) ($_POST['body'] ?? 'Inter')),
                'scale'   => in_array($_POST['scale'] ?? '', ['normal', 'large', 'xl'], true) ? $_POST['scale'] : 'normal',
            ]);
            $cfg['layout'] = array_merge($cfg['layout'] ?? [], [
                'width'  => in_array($_POST['width'] ?? '', ['contained', 'full'], true) ? $_POST['width'] : 'contained',
                'radius' => preg_match('/^\d{1,3}px$/', (string) ($_POST['radius'] ?? '')) ? $_POST['radius'] : '14px',
            ]);
            $saveCfg($cfg);
        } elseif ($a === 'save_hero') {
            $cfg['hero'] = array_merge($cfg['hero'] ?? [], [
                'enabled'  => !empty($_POST['enabled']) ? 1 : 0,
                'layout'   => in_array($_POST['layout'] ?? '', ['centered', 'split', 'compact'], true) ? $_POST['layout'] : 'centered',
                'title'    => trim((string) ($_POST['title'] ?? '')),
                'subtitle' => trim((string) ($_POST['subtitle'] ?? '')),
                'cta'      => trim((string) ($_POST['cta'] ?? '')),
            ]);
            $saveCfg($cfg);
        } elseif ($a === 'save_header') {
            $allItems = ['pricing', 'features', 'how', 'faq', 'contacts'];
            $en = $_POST['nav'] ?? [];
            $cfg['header'] = [
                'items' => array_values(array_filter($allItems, fn($i) => !empty($en[$i]))),
                'login' => !empty($_POST['login']) ? 1 : 0,
            ];
            $saveCfg($cfg);
        } elseif ($a === 'save_pricing') {
            $cfg['pricing'] = array_merge($cfg['pricing'] ?? [], [
                'layout' => in_array($_POST['layout'] ?? '', ['2col', '3col', '4col', 'featured-center', 'horizontal', 'editorial'], true) ? $_POST['layout'] : '3col',
                'card'   => in_array($_POST['card'] ?? '', ['classic', 'premium', 'glass', 'editorial', 'horizontal', 'feature-first'], true) ? $_POST['card'] : 'classic',
            ]);
            $saveCfg($cfg);
        } elseif ($a === 'save_sections') {
            $order = array_filter(explode(',', (string) ($_POST['order'] ?? '')));
            $enabled = $_POST['enabled'] ?? [];
            $cfg['sections'] = array_values(array_filter($order, fn($s) => !empty($enabled[$s])));
            if (!$cfg['sections']) {
                $cfg['sections'] = ['hero', 'pricing', 'footer'];
            }
            $saveCfg($cfg);
        } elseif ($a === 'save_content') {
            $cfg['contacts'] = [
                'email' => trim((string) ($_POST['c_email'] ?? '')),
                'telegram' => trim((string) ($_POST['c_tg'] ?? '')),
                'vk' => trim((string) ($_POST['c_vk'] ?? '')),
                'support' => trim((string) ($_POST['c_support'] ?? '')),
            ];
            $cfg['footer'] = array_merge($cfg['footer'] ?? [], ['copyright' => trim((string) ($_POST['copyright'] ?? ''))]);
            $cfg['faq'] = ['items' => site_parse_pairs((string) ($_POST['faq'] ?? ''), 'q', 'a')];
            $cfg['features'] = ['title' => trim((string) ($_POST['feat_title'] ?? 'Почему выбирают нас')), 'items' => site_parse_pairs((string) ($_POST['features'] ?? ''), 't', 'd')];
            $saveCfg($cfg);
        } elseif ($a === 'save_cabinet') {
            $layout = in_array($_POST['cab_layout'] ?? '', ['sidebar', 'topnav', 'cards', 'premium', 'minimal'], true) ? $_POST['cab_layout'] : 'sidebar';
            $allB = ['subscription', 'usage', 'devices', 'plans', 'payments', 'profile'];
            $en = $_POST['cab_enabled'] ?? [];
            $blocks = array_values(array_filter($allB, fn($b) => !empty($en[$b])));
            if (!$blocks) {
                $blocks = $allB;
            }
            $cfg['cabinet'] = ['layout' => $layout, 'blocks' => $blocks];
            $saveCfg($cfg);
        } elseif ($a === 'publish') {
            SiteDesign::publish($draftId);
            View::flash('success', t('site.flash.published'));
            header('Location: /site-builder.php');
            exit;
        }
    } catch (\Throwable $e) {
        View::flash('error', $e->getMessage());
    }
    header('Location: /site-builder.php' . ($hash ? '#' . $hash : ''));
    exit;
}

/** "A | B" построчно → [[k1=>A,k2=>B],…]; для textarea парсинг faq/features. */
function site_parse_pairs(string $raw, string $k1, string $k2): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 2));
        $out[] = [$k1 => $parts[0], $k2 => $parts[1] ?? ''];
    }
    return $out;
}

/** Обратно: массив пар → текст для textarea. */
function site_pairs_text(array $items, string $k1, string $k2): string
{
    $lines = [];
    foreach ($items as $it) {
        $lines[] = trim(($it[$k1] ?? '') . ' | ' . ($it[$k2] ?? ''), ' |');
    }
    return implode("\n", $lines);
}

// значения для форм (с учётом дефолтов шаблона через DesignConfig)
$dc = new App\Site\DesignConfig($template, $cfg);
$pal = $dc->palette();
$typ = $dc->typography();
$lay = $dc->get('layout', []);
$hero = $dc->hero();
$pr = $dc->pricing();
$brand = $dc->brand();
$contacts = $dc->contacts();
$allSections = ['hero' => 'Hero', 'features' => 'Преимущества', 'pricing' => 'Тарифы', 'how' => 'Как это работает', 'benefits' => 'Выгоды', 'faq' => 'FAQ', 'cta' => 'Призыв (CTA)', 'contacts' => 'Контакты', 'footer' => 'Подвал'];
$curSections = $dc->sections();
$orderedSections = array_merge($curSections, array_values(array_diff(array_keys($allSections), $curSections)));
$siteHost = (string) Setting::get('site_public_host', '');
$panelHost = (string) ($_SERVER['HTTP_HOST'] ?? Setting::get('panel_public_host', ''));
$csrf = Auth::csrfField();

View::header(t('nav.site'), t('site.subtitle'));
?>
<style>
  .sb-wrap{display:flex;gap:18px;align-items:flex-start}
  .sb-panel{flex:0 0 420px;max-width:440px}
  .sb-preview{flex:1;position:sticky;top:16px}
  .sb-frame{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.25)}
  .sb-frame iframe{display:block;width:100%;height:72vh;border:0;background:#fff;transition:max-width .2s}
  .sb-dev{display:inline-flex;border:1px solid var(--border);border-radius:9px;overflow:hidden;margin-bottom:8px}
  .sb-dev button{background:none;border:0;color:var(--text-muted);padding:6px 12px;font-size:12.5px;cursor:pointer}
  .sb-dev button.active{background:var(--surface-elevated);color:var(--accent)}
  .sb-sec{border:1px solid var(--border);border-radius:10px;margin-bottom:10px;background:var(--surface)}
  .sb-sec>summary{cursor:pointer;padding:12px 14px;font-weight:600;list-style:none;display:flex;align-items:center;gap:8px}
  .sb-sec[open]>summary{border-bottom:1px solid var(--border)}
  .sb-sec .body{padding:12px 14px}
  .sb-sec label{display:block;font-size:12px;color:var(--text-muted);margin:8px 0 3px}
  .sb-sec input[type=text],.sb-sec input[type=email],.sb-sec input:not([type]),.sb-sec select,.sb-sec textarea{width:100%}
  .tpl-pick{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .tpl-pick form{margin:0;display:block}
  .tpl-pick button{text-align:left;border:1px solid var(--border);background:var(--surface-elevated);border-radius:9px;padding:10px;cursor:pointer;color:var(--text);width:100%}
  .tpl-pick button.active{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
  .tpl-pick button b{display:block;font-size:13px}.tpl-pick button span{font-size:11px;color:var(--text-muted)}
  .pal-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
  .pal-grid label{margin:0}.pal-grid input[type=color]{width:100%;height:30px;padding:2px}
  .card-pick,.lay-pick{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px}
  .card-pick label,.lay-pick label{display:flex;gap:6px;align-items:center;border:1px solid var(--border);border-radius:8px;padding:7px 8px;font-size:12.5px;color:var(--text);cursor:pointer;margin:0}
  .sb-publish{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px;margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .sec-row{display:flex;align-items:center;gap:8px;padding:7px 9px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;background:var(--surface-elevated)}
  .sec-row .dh{cursor:grab;color:var(--text-muted)}
  .dcheck{font-size:12px;line-height:1.5;border:1px solid var(--border);border-radius:9px;padding:10px 12px}
  .dcheck.ok{border-color:#1f9d55;background:rgba(31,157,85,.10)}
  .dcheck.warn{border-color:#d08700;background:rgba(208,135,0,.10)}
  .dcheck.err{border-color:#d64545;background:rgba(214,69,69,.10)}
  .dcheck b{color:var(--text)}
  @media(max-width:1000px){.sb-wrap{flex-direction:column}.sb-panel{flex-basis:auto;max-width:none;width:100%}.sb-preview{position:static;width:100%}}
</style>

<div class="sb-wrap">
  <!-- ЛЕВО: конструктор -->
  <div class="sb-panel">

    <details class="sb-sec" open id="template"><summary>🧩 Шаблон</summary><div class="body">
      <div class="tpl-pick">
        <?php foreach (TemplateRegistry::all() as $k => $m): ?>
          <form method="post"><?= $csrf ?><input type="hidden" name="action" value="pick_template"><input type="hidden" name="template" value="<?= $k ?>"><input type="hidden" name="hash" value="template">
            <button type="submit" class="<?= $k === $template ? 'active' : '' ?>"><b><?= htmlspecialchars($m['name']) ?></b><span><?= htmlspecialchars(mb_substr($m['description'], 0, 60)) ?></span></button>
          </form>
        <?php endforeach; ?>
      </div>
    </div></details>

    <details class="sb-sec" id="brand"><summary>🏷 Бренд и домены</summary><div class="body">
      <form method="post" enctype="multipart/form-data"><?= $csrf ?><input type="hidden" name="action" value="save_brand"><input type="hidden" name="hash" value="brand">
        <label>Название</label><input name="brand_name" value="<?= htmlspecialchars((string) ($brand['name'] ?? '')) ?>">
        <label>SEO title</label><input name="seo_title" value="<?= htmlspecialchars((string) $dc->get('seo.title', '')) ?>">
        <label>Логотип (PNG/SVG, ≤1МБ)</label>
        <?php if (!empty($brand['logo'])): ?><div style="margin:4px 0"><img src="<?= htmlspecialchars($brand['logo']) ?>" style="max-height:34px;background:#fff;padding:4px;border-radius:6px"> <label style="display:inline"><input type="checkbox" name="logo_clear" value="1"> убрать</label></div><?php endif; ?>
        <input type="file" name="logo" accept="image/*">
        <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
        <label>Домен сайта (где разместить сайт-визитку)</label>
        <input name="site_host" value="<?= htmlspecialchars($siteHost) ?>" placeholder="domain.ru">
        <p class="muted" style="font-size:11.5px;margin:6px 0 0">Панель — <code><?= htmlspecialchars($panelHost) ?></code>, сайт — <code><?= htmlspecialchars($siteHost ?: 'domain.ru') ?></code>. Нужно, чтобы этот домен в DNS (A-запись) и в nginx указывал на эту же панель (см. подсказку ниже).</p>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><button type="submit">Сохранить</button>
          <?php if ($siteHost): ?><button type="button" class="secondary" id="btn-check-domain">Проверить домен</button><?php endif; ?>
        </div>
      </form>
      <?php if ($siteHost): ?>
      <div id="domain-check" class="dcheck hidden" style="margin-top:10px"></div>
      <script>
      (function(){
        var btn=document.getElementById('btn-check-domain'),box=document.getElementById('domain-check');
        if(!btn||!box)return;
        var PANEL_HOST=<?= json_encode($panelHost) ?>;
        function show(cls,html){box.className='dcheck '+cls;box.innerHTML=html;box.classList.remove('hidden');}
        btn.addEventListener('click',async function(){
          btn.disabled=true;var old=btn.textContent;btn.textContent='Проверяю…';
          try{
            var r=await fetch('/api/site-domain-check.php',{headers:{Accept:'application/json'}});
            var d=await r.json();
            var dom=(d.domain_ips||[]).join(', ')||'—', pan=(d.panel_ips||[]).join(', ')||'—';
            if(d.status==='ok'){
              show('ok','✅ Домен <b>'+d.host+'</b> указывает на эту панель и отдаёт ваш сайт. Всё настроено верно.');
            }else if(d.status==='no_dns'){
              show('err','⛔ Для домена <b>'+d.host+'</b> нет A-записи. Добавьте A-запись у регистратора/DNS-провайдера на IP панели: <b>'+pan+'</b>, затем настройте nginx (подсказка ниже).');
            }else if(d.status==='wrong_ip'){
              show('err','⚠️ A-запись домена <b>'+d.host+'</b> указывает на <b>'+dom+'</b>, а панель — на <b>'+pan+'</b>. Это другой сервер. Измените A-запись на IP панели ('+pan+') или размещайте сайт там, куда указывает домен.');
            }else if(d.status==='other_site'){
              show('warn','⚠️ Домен <b>'+d.host+'</b> указывает на этот сервер ('+pan+'), но по нему открывается <b>другой сайт</b> — для него нет vhost, направляющего на эту панель. Добавьте в nginx server { server_name '+d.host+'; ... } на docroot панели (подсказка ниже) и перезагрузите nginx.');
            }else if(d.status==='unreachable'){
              show('err','⛔ Домен <b>'+d.host+'</b> не отвечает по HTTP/HTTPS. Проверьте, что A-запись указывает на IP панели ('+pan+'), сайт поднят и порт 80/443 открыт.');
            }else if(d.status==='unset'){
              show('warn','Сначала сохраните корректный домен.');
            }else{ show('err','Не удалось проверить домен.'); }
          }catch(e){ show('err','Ошибка проверки: '+e.message); }
          btn.disabled=false;btn.textContent=old;
        });
      })();
      </script>
      <?php endif; ?>
      <?php if ($siteHost): ?>
        <details style="margin-top:10px"><summary class="muted" style="cursor:pointer;font-size:12px">nginx-подсказка</summary>
        <pre style="white-space:pre-wrap;font-size:11px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px;margin-top:6px">server {
  server_name <?= htmlspecialchars($siteHost) ?>;
  root <?= htmlspecialchars(dirname(__DIR__)) ?>/public;
  index index.php;
  location / { try_files $uri /index.php$is_args$args; }
  # PHP-FPM — как у панели; затем certbot --nginx -d <?= htmlspecialchars($siteHost) ?>
}</pre></details>
      <?php endif; ?>
    </div></details>

    <details class="sb-sec" id="palette"><summary>🎨 Палитра</summary><div class="body">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save_palette"><input type="hidden" name="hash" value="palette">
        <div class="row" style="gap:8px;margin-bottom:8px">
          <select id="pal-preset" onchange="applyPreset(this.value)">
            <option value="">— пресет —</option>
            <option value="pink">VPS Pink</option><option value="midnight">Midnight</option><option value="ocean">Ocean</option><option value="purple">Purple</option><option value="minimal">Minimal</option><option value="graphite">Graphite</option>
          </select>
          <label style="display:flex;align-items:center;gap:6px;margin:0;font-size:12.5px;color:var(--text)"><input type="checkbox" name="dark" id="pal-dark" value="1" <?= (int) ($pal['dark'] ?? 0) === 1 ? 'checked' : '' ?>> тёмная</label>
        </div>
        <div class="pal-grid">
          <?php foreach (['primary' => 'Акцент', 'popular' => 'Популярный', 'price' => 'Цена', 'bg' => 'Фон', 'surface' => 'Карточки', 'surface2' => 'Поля', 'text' => 'Текст', 'muted' => 'Приглуш.', 'border' => 'Рамка'] as $k => $lbl):
            $val = (string) ($pal[$k] ?? '#000000');
            $isHex = preg_match('/^#[0-9a-fA-F]{6}$/', $val); ?>
            <label><?= $lbl ?>
              <?php if ($isHex): ?><input type="color" name="<?= $k ?>" id="pal-<?= $k ?>" value="<?= htmlspecialchars($val) ?>"><?php else: ?><input type="text" name="<?= $k ?>" id="pal-<?= $k ?>" value="<?= htmlspecialchars($val) ?>"><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
    </div></details>

    <details class="sb-sec" id="typography"><summary>🔤 Типографика</summary><div class="body">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save_typography"><input type="hidden" name="hash" value="typography">
        <?php $fonts = ['Inter', 'Manrope', 'Montserrat', 'Roboto', 'Unbounded', 'Playfair Display', 'JetBrains Mono']; ?>
        <label>Шрифт заголовков</label><select name="heading"><?php foreach ($fonts as $f): ?><option <?= ($typ['heading'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select>
        <label>Шрифт текста</label><select name="body"><?php foreach ($fonts as $f): ?><option <?= ($typ['body'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select>
        <label>Масштаб заголовков</label><select name="scale"><?php foreach (['normal' => 'обычный', 'large' => 'крупный', 'xl' => 'очень крупный'] as $v => $l): ?><option value="<?= $v ?>" <?= ($typ['scale'] ?? 'normal') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <div class="row" style="gap:10px">
          <div style="flex:1"><label>Ширина</label><select name="width"><option value="contained" <?= ($lay['width'] ?? '') === 'contained' ? 'selected' : '' ?>>по центру</option><option value="full" <?= ($lay['width'] ?? '') === 'full' ? 'selected' : '' ?>>во всю ширину</option></select></div>
          <div style="width:110px"><label>Скругление</label><input name="radius" value="<?= htmlspecialchars((string) ($lay['radius'] ?? '14px')) ?>"></div>
        </div>
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
    </div></details>

    <details class="sb-sec" id="hero"><summary>🖼 Hero</summary><div class="body">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save_hero"><input type="hidden" name="hash" value="hero">
        <label style="display:flex;align-items:center;gap:6px;color:var(--text)"><input type="checkbox" name="enabled" value="1" <?= !empty($hero['enabled']) ? 'checked' : '' ?>> показывать hero</label>
        <label>Макет</label><select name="layout"><?php foreach (['centered' => 'по центру', 'split' => 'текст + арт', 'compact' => 'компактный'] as $v => $l): ?><option value="<?= $v ?>" <?= ($hero['layout'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <label>Заголовок</label><input name="title" value="<?= htmlspecialchars((string) ($hero['title'] ?? '')) ?>">
        <label>Подзаголовок</label><input name="subtitle" value="<?= htmlspecialchars((string) ($hero['subtitle'] ?? '')) ?>">
        <label>Текст кнопки</label><input name="cta" value="<?= htmlspecialchars((string) ($hero['cta'] ?? '')) ?>">
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
    </div></details>

    <details class="sb-sec" id="header"><summary>🧭 Шапка (навигация)</summary><div class="body">
      <?php $hdr = $dc->get('header', []); $hdrItems = is_array($hdr['items'] ?? null) ? $hdr['items'] : ['pricing', 'features', 'how', 'faq', 'contacts']; $hdrLogin = (int) ($hdr['login'] ?? 1); ?>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save_header"><input type="hidden" name="hash" value="header">
        <label>Пункты меню в шапке (показываются, только если секция включена)</label>
        <?php foreach (['pricing' => 'Тарифы', 'features' => 'Преимущества', 'how' => 'Как это работает', 'faq' => 'FAQ', 'contacts' => 'Контакты'] as $v => $l): ?>
          <label style="display:flex;align-items:center;gap:6px;color:var(--text)"><input type="checkbox" name="nav[<?= $v ?>]" value="1" <?= in_array($v, $hdrItems, true) ? 'checked' : '' ?>> <?= $l ?></label>
        <?php endforeach; ?>
        <label style="display:flex;align-items:center;gap:6px;color:var(--text);margin-top:8px"><input type="checkbox" name="login" value="1" <?= $hdrLogin ? 'checked' : '' ?>> показывать «Войти»</label>
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
    </div></details>

    <details class="sb-sec" id="pricing"><summary>💳 Тарифы (вид)</summary><div class="body">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save_pricing"><input type="hidden" name="hash" value="pricing">
        <label>Раскладка</label>
        <div class="lay-pick"><?php foreach (['3col' => '3 колонки', '2col' => '2 колонки', '4col' => '4 колонки', 'featured-center' => 'featured по центру', 'horizontal' => 'горизонт.', 'editorial' => 'editorial'] as $v => $l): ?>
          <label><input type="radio" name="layout" value="<?= $v ?>" <?= ($pr['layout'] ?? '') === $v ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
        <label style="margin-top:10px">Стиль карточки</label>
        <div class="card-pick"><?php foreach (['classic' => 'Классика', 'premium' => 'Premium', 'glass' => 'Glass', 'feature-first' => 'Фичи', 'horizontal' => 'Строка', 'editorial' => 'Editorial'] as $v => $l): ?>
          <label><input type="radio" name="card" value="<?= $v ?>" <?= ($pr['card'] ?? '') === $v ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
      <p class="muted" style="font-size:11.5px;margin:8px 0 0">Карточки показывают реальные тарифы биллинга (раздел «Биллинг → Тарифы»).</p>
    </div></details>

    <details class="sb-sec" id="sections"><summary>🧱 Секции и порядок</summary><div class="body">
      <form method="post" id="sec-form"><?= $csrf ?><input type="hidden" name="action" value="save_sections"><input type="hidden" name="hash" value="sections"><input type="hidden" name="order" id="sec-order">
        <div id="sec-list">
          <?php foreach ($orderedSections as $s): if (!isset($allSections[$s])) {
                continue;
            } ?>
            <div class="sec-row" data-id="<?= $s ?>"><span class="dh">⠿</span>
              <label style="margin:0;display:flex;align-items:center;gap:6px;color:var(--text);flex:1"><input type="checkbox" name="enabled[<?= $s ?>]" value="1" <?= in_array($s, $curSections, true) ? 'checked' : '' ?>> <?= htmlspecialchars($allSections[$s]) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
    </div></details>

    <details class="sb-sec" id="content"><summary>✏️ Контент (FAQ, фичи, контакты)</summary><div class="body">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save_content"><input type="hidden" name="hash" value="content">
        <label>Заголовок блока «Преимущества»</label><input name="feat_title" value="<?= htmlspecialchars((string) ($dc->features()['title'] ?? 'Почему выбирают нас')) ?>">
        <label>Преимущества (по строке: «Заголовок | Описание»)</label>
        <textarea name="features" rows="4"><?= htmlspecialchars(site_pairs_text($dc->features()['items'] ?? [], 't', 'd')) ?></textarea>
        <label>FAQ (по строке: «Вопрос | Ответ»)</label>
        <textarea name="faq" rows="4"><?= htmlspecialchars(site_pairs_text($dc->faq()['items'] ?? [], 'q', 'a')) ?></textarea>
        <label>Контакты</label>
        <input name="c_email" placeholder="email" value="<?= htmlspecialchars((string) ($contacts['email'] ?? '')) ?>">
        <input name="c_tg" placeholder="Telegram" value="<?= htmlspecialchars((string) ($contacts['telegram'] ?? '')) ?>" style="margin-top:6px">
        <input name="c_vk" placeholder="VK" value="<?= htmlspecialchars((string) ($contacts['vk'] ?? '')) ?>" style="margin-top:6px">
        <input name="c_support" placeholder="Поддержка" value="<?= htmlspecialchars((string) ($contacts['support'] ?? '')) ?>" style="margin-top:6px">
        <label>Подвал (copyright)</label><input name="copyright" value="<?= htmlspecialchars((string) $dc->get('footer.copyright', '')) ?>">
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
    </div></details>

    <details class="sb-sec" id="cabinet"><summary>👤 Личный кабинет</summary><div class="body">
      <?php $cab = $dc->cabinet(); $cabLayout = (string) ($cab['layout'] ?? 'sidebar'); $cabBlocks = is_array($cab['blocks'] ?? null) ? $cab['blocks'] : ['subscription', 'usage', 'devices', 'plans', 'payments', 'profile']; ?>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save_cabinet"><input type="hidden" name="hash" value="cabinet">
        <label>Раскладка кабинета</label>
        <div class="lay-pick"><?php foreach (['sidebar' => 'Боковое меню', 'topnav' => 'Верхнее меню', 'cards' => 'Виджеты', 'premium' => 'Premium', 'minimal' => 'Минимал'] as $v => $l): ?>
          <label><input type="radio" name="cab_layout" value="<?= $v ?>" <?= $cabLayout === $v ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
        <label style="margin-top:10px">Блоки кабинета</label>
        <?php foreach (['subscription' => 'Подписка', 'usage' => 'Трафик', 'devices' => 'Устройства', 'plans' => 'Тарифы', 'payments' => 'Платежи', 'profile' => 'Профиль'] as $v => $l): ?>
          <label style="display:flex;align-items:center;gap:6px;color:var(--text)"><input type="checkbox" name="cab_enabled[<?= $v ?>]" value="1" <?= in_array($v, $cabBlocks, true) ? 'checked' : '' ?>> <?= $l ?></label>
        <?php endforeach; ?>
        <p class="muted" style="font-size:11.5px;margin:8px 0 0">Данные (подписка/трафик/устройства/платежи) — реальные из биллинга. Отсутствующие блоки скрываются.</p>
        <div style="margin-top:10px"><button type="submit">Сохранить</button></div>
      </form>
    </div></details>

    <form method="post" class="sb-publish"><?= $csrf ?><input type="hidden" name="action" value="publish">
      <span class="muted" style="font-size:12.5px;flex:1">Черновик v<?= (int) $draft['version'] ?>. Публикация сделает сайт живым.</span>
      <a class="btn secondary" href="/site.php?preview=1" target="_blank" style="font-size:12.5px">Открыть превью</a>
      <button type="submit">Опубликовать</button>
    </form>
  </div>

  <!-- ПРАВО: живой предпросмотр -->
  <div class="sb-preview">
    <div class="row" style="justify-content:space-between;margin-bottom:8px">
      <div class="sb-dev" id="sb-surface">
        <button data-s="website" class="active">Сайт</button><button data-s="cabinet">Кабинет</button>
      </div>
      <div class="sb-dev" id="sb-devs">
        <button data-w="100%" class="active">Desktop</button><button data-w="834px">Tablet</button><button data-w="390px">Mobile</button>
      </div>
    </div>
    <div class="sb-frame"><iframe id="sb-view" src="/site.php?preview=1&device=desktop&ts=<?= time() ?>" title="preview"></iframe></div>
  </div>
</div>

<script>
var PRESETS={
 pink:{primary:'#ff4f87',popular:'#ff4f87',price:'#ff3d6e',bg:'#17171a',surface:'#1d1d22',surface2:'#222228',text:'#f5f5f7',muted:'#a1a1aa',border:'rgba(255,255,255,.1)',dark:1},
 midnight:{primary:'#7c5cff',popular:'#7c5cff',price:'#fff',bg:'#0e0f1a',surface:'#17182a',surface2:'#1f2138',text:'#eef0ff',muted:'#9aa0c0',border:'rgba(255,255,255,.12)',dark:1},
 ocean:{primary:'#0ea5a0',popular:'#0ea5a0',price:'#0f766e',bg:'#f3f7f8',surface:'#fff',surface2:'#e7eef0',text:'#0f1b1d',muted:'#5c7378',border:'rgba(15,27,29,.1)',dark:0},
 purple:{primary:'#6d28d9',popular:'#6d28d9',price:'#6d28d9',bg:'#faf8ff',surface:'#fff',surface2:'#f1ecfb',text:'#1c1630',muted:'#6b647e',border:'rgba(28,22,48,.1)',dark:0},
 minimal:{primary:'#2563eb',popular:'#2563eb',price:'#14141a',bg:'#f7f8fb',surface:'#fff',surface2:'#eef1f6',text:'#14141a',muted:'#667085',border:'rgba(16,24,40,.1)',dark:0},
 graphite:{primary:'#e5e7eb',popular:'#9ca3af',price:'#f3f4f6',bg:'#111317',surface:'#191c22',surface2:'#21242c',text:'#eceef2',muted:'#9aa1ad',border:'rgba(255,255,255,.1)',dark:1}
};
function applyPreset(k){var p=PRESETS[k];if(!p)return;Object.keys(p).forEach(function(key){if(key==='dark'){var d=document.getElementById('pal-dark');if(d)d.checked=!!p.dark;return;}var el=document.getElementById('pal-'+key);if(el)el.value=p[key];});}
// device + surface toggle
var frame=document.getElementById('sb-view'), curSurface='website';
function reload(){frame.src='/site.php?preview=1'+(curSurface==='cabinet'?'&surface=cabinet':'')+'&device=desktop&ts='+Date.now();}
document.getElementById('sb-devs').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;frame.style.maxWidth=b.dataset.w;[].forEach.call(this.children,function(x){x.classList.toggle('active',x===b);});});
document.getElementById('sb-surface').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;curSurface=b.dataset.s;[].forEach.call(this.children,function(x){x.classList.toggle('active',x===b);});reload();});
// drag reorder секций
(function(){var list=document.getElementById('sec-list'),drag=null;if(!list)return;
 list.querySelectorAll('.sec-row').forEach(function(it){var h=it.querySelector('.dh');
   h.addEventListener('mousedown',function(){it.draggable=true;});
   it.addEventListener('dragstart',function(e){drag=it;it.style.opacity=.5;e.dataTransfer.effectAllowed='move';});
   it.addEventListener('dragend',function(){it.style.opacity='';it.draggable=false;});
   it.addEventListener('dragover',function(e){e.preventDefault();var t=e.currentTarget;if(drag&&drag!==t){var r=t.getBoundingClientRect();list.insertBefore(drag,(e.clientY-r.top)/r.height>.5?t.nextSibling:t);}});
 });
 document.getElementById('sec-form').addEventListener('submit',function(){document.getElementById('sec-order').value=[].map.call(list.querySelectorAll('.sec-row'),function(x){return x.dataset.id;}).join(',');});
})();
// сохранить открытую секцию при reload
document.querySelectorAll('.sb-sec').forEach(function(d){d.addEventListener('toggle',function(){if(d.open){try{localStorage.setItem('sb_open',d.id);}catch(e){}}});});
try{var op=localStorage.getItem('sb_open');if(op){var el=document.getElementById(op);if(el){document.querySelectorAll('.sb-sec[open]').forEach(function(x){if(x!==el)x.open=false;});el.open=true;}}}catch(e){}
</script>
<?php View::footer(); ?>
