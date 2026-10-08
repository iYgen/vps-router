<?php

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;

// Свежая установка (нет админа) → мастер установки.
if (!\App\Installer::isInstalled() && is_file(__DIR__ . '/install.php')) {
    header('Location: /install.php');
    exit;
}

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;
$blocked = Auth::isIpBlocked(Auth::clientIp());
$show2fa = Auth::needs2fa();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blocked) {
    Auth::requireValidCsrf();

    if (isset($_POST['totp_code'])) {
        // Второй шаг: код из приложения (или код восстановления).
        if (Auth::verify2fa($_POST['totp_code'])) {
            header('Location: /dashboard.php');
            exit;
        }
        $blocked = Auth::isIpBlocked(Auth::clientIp());
        $show2fa = Auth::needs2fa();
        $error = $blocked ? null : t('login.2fa_bad');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (Auth::attempt($username, $password)) {
            if (Auth::needs2fa()) {
                $show2fa = true; // пароль верный — просим код
            } else {
                header('Location: /dashboard.php');
                exit;
            }
        } else {
            $blocked = Auth::isIpBlocked(Auth::clientIp());
            $error = $blocked ? null : t('login.bad_creds');
        }
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('login.title')) ?></title>
<link href="/assets/fonts/inter.css" rel="stylesheet">
<style>
:root {
  color-scheme: dark;
  --bg: #151519; --surface: #1d1d22; --border: rgba(255,255,255,.08); --border-hover: rgba(255,255,255,.14);
  --text: #f5f5f7; --text-muted: #71717a;
  --accent: #ff4f87; --accent-hover: #ff689a; --accent-purple: #8b7cff; --accent-glow: rgba(255,79,135,.12);
  --danger: #ff5c5c;
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
.auth-brand { display: flex; align-items: center; justify-content: center; margin-bottom: 24px; }
.auth-brand-logo { height: 34px; width: auto; max-width: 100%; display: block; }
h1 { font-size: 15px; margin: 0 0 18px; font-weight: 600; }
input { width: 100%; padding: 10px 12px; margin-bottom: 12px; border: 1px solid var(--border); border-radius: 8px; background: #18181c; color: var(--text); font-family: inherit; font-size: 13.5px; transition: border-color 120ms ease, box-shadow 120ms ease; }
input::placeholder { color: var(--text-muted); }
input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
button { width: 100%; padding: 10px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; cursor: pointer; font-family: inherit; font-size: 13.5px; font-weight: 500; transition: background 120ms ease; }
button:hover { background: var(--accent-hover); }
.error { color: var(--danger); font-size: 12.5px; margin-bottom: 12px; }
.auth-links { text-align: center; margin: 14px 0 0; }
.auth-links a { color: var(--text-muted); font-size: 12.5px; }
.auth-lang { text-align: center; margin-top: 16px; display: flex; gap: 8px; justify-content: center; }
.auth-lang a { color: var(--text-muted); font-size: 11px; font-weight: 600; text-decoration: none; padding: 2px 7px; border-radius: 6px; border: 1px solid transparent; }
.auth-lang a.active { color: var(--accent); border-color: rgba(255,79,135,.4); }
</style>
</head>
<body>
<form method="post" class="auth-card">
  <div class="auth-brand">
    <img class="auth-brand-logo" src="/assets/img/logo.svg" alt="VPS Router">
  </div>
  <h1><?= htmlspecialchars($show2fa ? t('login.2fa_heading') : t('login.heading')) ?></h1>
  <?php if ($blocked): ?>
    <div class="error"><?= htmlspecialchars(t('login.blocked')) ?></div>
  <?php elseif ($show2fa): ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 14px"><?= htmlspecialchars(t('login.2fa_hint')) ?></p>
    <?= Auth::csrfField() ?>
    <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="<?= htmlspecialchars(t('login.2fa_code')) ?>" autofocus required>
    <button type="submit"><?= htmlspecialchars(t('login.2fa_submit')) ?></button>
  <?php else: ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?= Auth::csrfField() ?>
    <input type="text" name="username" placeholder="<?= htmlspecialchars(t('login.username')) ?>" autofocus required>
    <input type="password" name="password" placeholder="<?= htmlspecialchars(t('login.password')) ?>" required>
    <button type="submit"><?= htmlspecialchars(t('login.submit')) ?></button>
  <?php endif; ?>
  <p class="auth-links"><a href="/forgot-password.php"><?= htmlspecialchars(t('login.forgot')) ?></a></p>
  <div class="auth-lang">
    <?php foreach (\App\I18n::available() as $code => $name): ?>
      <a href="?lang=<?= htmlspecialchars($code) ?>" class="<?= \App\I18n::lang() === $code ? 'active' : '' ?>"><?= htmlspecialchars(strtoupper($code)) ?></a>
    <?php endforeach; ?>
  </div>
  <p style="text-align:center;font-size:11px;color:var(--text-muted);margin:16px 0 0;opacity:.7">© <?= date('Y') ?> Ygen</p>
</form>
</body>
</html>
