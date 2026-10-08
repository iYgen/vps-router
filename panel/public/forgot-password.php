<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireValidCsrf();
    $username = trim($_POST['username'] ?? '');
    if ($username !== '') {
        Auth::requestPasswordReset($username);
    }
    $sent = true;
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('forgot.title')) ?></title>
<link href="/assets/fonts/inter.css" rel="stylesheet">
<style>
:root {
  color-scheme: dark;
  --bg: #151519; --surface: #1d1d22; --border: rgba(255,255,255,.08); --border-hover: rgba(255,255,255,.14);
  --text: #f5f5f7; --text-muted: #71717a;
  --accent: #ff4f87; --accent-hover: #ff689a; --accent-purple: #8b7cff; --accent-glow: rgba(255,79,135,.12);
  --success: #35d07f;
}
* { box-sizing: border-box; }
body {
  margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
  font-family: 'Inter', system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text);
  position: relative; overflow: hidden;
}
body::before, body::after { content: ''; position: absolute; width: 60vw; height: 60vw; border-radius: 50%; filter: blur(80px); pointer-events: none; }
body::before { top: -20%; right: -10%; background: radial-gradient(circle, rgba(139,124,255,.10), transparent 60%); }
body::after { bottom: -20%; left: -10%; background: radial-gradient(circle, rgba(255,79,135,.08), transparent 60%); }
.auth-card { position: relative; z-index: 1; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 32px 30px; width: 320px; box-shadow: 0 24px 80px rgba(0,0,0,.45); }
.auth-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; }
.auth-brand-mark { width: 30px; height: 30px; border-radius: 9px; background: linear-gradient(135deg, var(--accent), var(--accent-purple)); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: #fff; flex-shrink: 0; }
.auth-brand-text strong { display: block; font-size: 14px; }
.auth-brand-text span { display: block; font-size: 11px; color: var(--text-muted); }
h1 { font-size: 15px; margin: 0 0 12px; font-weight: 600; }
p.hint { font-size: 12.5px; color: var(--text-muted); margin: 0 0 16px; line-height: 1.5; }
input { width: 100%; padding: 10px 12px; margin-bottom: 12px; border: 1px solid var(--border); border-radius: 8px; background: #18181c; color: var(--text); font-family: inherit; font-size: 13.5px; transition: border-color 120ms ease, box-shadow 120ms ease; }
input::placeholder { color: var(--text-muted); }
input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
button { width: 100%; padding: 10px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; cursor: pointer; font-family: inherit; font-size: 13.5px; font-weight: 500; transition: background 120ms ease; }
button:hover { background: var(--accent-hover); }
.success { color: var(--success); font-size: 12.5px; margin-bottom: 12px; line-height: 1.5; }
.back-link { display: block; text-align: center; margin-top: 14px; margin-bottom: 0; color: var(--text-muted); font-size: 12.5px; }
</style>
</head>
<body>
<form method="post" class="auth-card">
  <div class="auth-brand">
    <div class="auth-brand-mark">•</div>
    <div class="auth-brand-text"><strong><?= htmlspecialchars(t('login.brand')) ?></strong><span><?= htmlspecialchars(t('login.brand_sub')) ?></span></div>
  </div>
  <h1><?= htmlspecialchars(t('forgot.heading')) ?></h1>
  <?php if ($sent): ?>
    <div class="success"><?= htmlspecialchars(t('forgot.sent')) ?></div>
  <?php else: ?>
    <p class="hint"><?= htmlspecialchars(t('forgot.intro')) ?></p>
    <?= Auth::csrfField() ?>
    <input type="text" name="username" placeholder="<?= htmlspecialchars(t('forgot.username')) ?>" autofocus required>
    <button type="submit"><?= htmlspecialchars(t('forgot.submit')) ?></button>
  <?php endif; ?>
  <a href="/login.php" class="back-link">← <?= htmlspecialchars(t('forgot.back')) ?></a>
</form>
</body>
</html>
