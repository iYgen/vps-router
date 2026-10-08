<?php

namespace App;

use App\Models\AuditLog;

class Auth
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const FAILURE_WINDOW_MINUTES = 15;
    private const BLOCK_MINUTES = 15;

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function isIpBlocked(string $ip): bool
    {
        $stmt = Database::get()->prepare("SELECT 1 FROM login_blocks WHERE ip = ? AND blocked_until > datetime('now') LIMIT 1");
        $stmt->execute([$ip]);
        return (bool) $stmt->fetchColumn();
    }

    public static function attempt(string $username, string $password): bool
    {
        $ip = self::clientIp();

        if (self::isIpBlocked($ip)) {
            // Пароль намеренно не проверяем вообще — иначе лимит можно
            // обойти простым перебором до совпадения, игнорируя блокировку.
            AuditLog::recordAuth($username, 'login_blocked', $ip, 'попытка входа с заблокированного IP');
            return false;
        }

        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            usleep(random_int(200000, 400000)); // затрудняем тайминг-атаки/брутфорс
            AuditLog::recordAuth($username, 'login_failed', $ip);
            self::registerFailureAndMaybeBlock($ip);
            return false;
        }

        // Второй фактор (TOTP): пароль верный, но вход не завершаем — переводим
        // в состояние ожидания кода. Полноценная сессия ставится в verify2fa().
        if (!empty($user['totp_enabled'])) {
            session_regenerate_id(true);
            $_SESSION['pending_2fa'] = ['user_id' => (int) $user['id'], 'username' => $user['username']];
            AuditLog::recordAuth($username, 'login_2fa_required', $ip);
            return true;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        AuditLog::recordAuth($username, 'login_success', $ip);

        return true;
    }

    /** Пароль принят, но нужен код второго фактора (см. attempt/verify2fa). */
    public static function needs2fa(): bool
    {
        return !empty($_SESSION['pending_2fa']) && empty($_SESSION['user_id']);
    }

    /**
     * Завершает вход по коду TOTP или коду восстановления. Rate-limit — тот же,
     * что у пароля (по IP). При успехе ставит полноценную сессию.
     */
    public static function verify2fa(string $code): bool
    {
        $ip = self::clientIp();
        if (self::isIpBlocked($ip) || empty($_SESSION['pending_2fa'])) {
            return false;
        }
        $pending = $_SESSION['pending_2fa'];
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$pending['user_id']]);
        $user = $stmt->fetch();
        if (!$user || empty($user['totp_enabled'])) {
            unset($_SESSION['pending_2fa']);
            return false;
        }

        $code = trim($code);
        $ok = false;
        $secret = $user['totp_secret'] ? Secrets::decryptOrPlain($user['totp_secret']) : '';
        if ($secret !== '' && \App\Totp::verify($secret, $code)) {
            $ok = true;
        } elseif (self::consumeRecoveryCode((int) $user['id'], $code)) {
            $ok = true;
            AuditLog::recordAuth($user['username'], 'login_2fa_recovery', $ip);
        }

        if (!$ok) {
            usleep(random_int(200000, 400000));
            AuditLog::recordAuth($user['username'], 'login_2fa_failed', $ip);
            self::registerFailureAndMaybeBlock($ip);
            return false;
        }

        unset($_SESSION['pending_2fa']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        AuditLog::recordAuth($user['username'], 'login_success', $ip);
        return true;
    }

    /** @return array{secret:string,uri:string} начинает привязку (секрет — в сессии до подтверждения) */
    public static function beginTotpEnrollment(string $account, string $issuer = 'vps_router'): array
    {
        $secret = \App\Totp::generateSecret();
        $_SESSION['totp_setup_secret'] = $secret;
        return ['secret' => $secret, 'uri' => \App\Totp::uri($secret, $account, $issuer)];
    }

    /**
     * Подтверждает привязку введённым из приложения кодом. При успехе включает
     * 2FA и возвращает одноразовые коды восстановления (показать один раз!).
     *
     * @return string[] recovery codes
     */
    public static function confirmTotpEnrollment(int $userId, string $code): array
    {
        $secret = $_SESSION['totp_setup_secret'] ?? '';
        if ($secret === '' || !\App\Totp::verify($secret, $code)) {
            throw new \InvalidArgumentException('Неверный код — проверьте время на телефоне и повторите');
        }
        $recovery = \App\Totp::generateRecoveryCodes();
        $hashed = json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $recovery));
        Database::get()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery = ? WHERE id = ?')
            ->execute([Secrets::encrypt($secret), $hashed, $userId]);
        unset($_SESSION['totp_setup_secret']);
        AuditLog::record('user.totp_enable', '');
        return $recovery;
    }

    /** Отключение 2FA — требует и текущий пароль, и действующий код (или recovery). */
    public static function disableTotp(int $userId, string $password, string $code): void
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new \InvalidArgumentException('Неверный текущий пароль');
        }
        $secret = $user['totp_secret'] ? Secrets::decryptOrPlain($user['totp_secret']) : '';
        if (!($secret !== '' && \App\Totp::verify($secret, trim($code))) && !self::consumeRecoveryCode($userId, trim($code))) {
            throw new \InvalidArgumentException('Неверный код второго фактора');
        }
        Database::get()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery = NULL WHERE id = ?')
            ->execute([$userId]);
        AuditLog::record('user.totp_disable', '');
    }

    public static function totpEnabled(int $userId): bool
    {
        $stmt = Database::get()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Гасит использованный код восстановления (одноразовый). */
    private static function consumeRecoveryCode(int $userId, string $code): bool
    {
        $stmt = Database::get()->prepare('SELECT totp_recovery FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $raw = $stmt->fetchColumn();
        $codes = $raw ? json_decode($raw, true) : [];
        if (!is_array($codes)) {
            return false;
        }
        foreach ($codes as $i => $hash) {
            if (is_string($hash) && password_verify($code, $hash)) {
                unset($codes[$i]);
                Database::get()->prepare('UPDATE users SET totp_recovery = ? WHERE id = ?')
                    ->execute([json_encode(array_values($codes)), $userId]);
                return true;
            }
        }
        return false;
    }

    private static function registerFailureAndMaybeBlock(string $ip): void
    {
        $stmt = Database::get()->prepare(
            "SELECT COUNT(*) FROM audit_log
             WHERE category = 'auth' AND action = 'login_failed' AND ip = ?
               AND ts > datetime('now', ?)"
        );
        $stmt->execute([$ip, '-' . self::FAILURE_WINDOW_MINUTES . ' minutes']);
        $failures = (int) $stmt->fetchColumn();

        if ($failures >= self::MAX_FAILED_ATTEMPTS) {
            $stmt = Database::get()->prepare(
                "INSERT INTO login_blocks (ip, blocked_until, reason) VALUES (?, datetime('now', ?), ?)"
            );
            $stmt->execute([$ip, '+' . self::BLOCK_MINUTES . ' minutes', "$failures неудачных попыток входа за " . self::FAILURE_WINDOW_MINUTES . ' минут']);
            AuditLog::recordAuth('', 'ip_blocked', $ip, "$failures неудачных попыток — блок на " . self::BLOCK_MINUTES . ' минут');
        }
    }

    /**
     * Всегда молча "успешна" независимо от того, нашёлся ли username и
     * задан ли у него email — вызывающий код (forgot-password.php)
     * показывает один и тот же текст в любом случае, чтобы не палить
     * существование аккаунта перебором логинов.
     */
    public static function requestPasswordReset(string $username): void
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) {
            return;
        }

        $recent = Database::get()->prepare(
            "SELECT 1 FROM password_resets WHERE user_id = ? AND created_at > datetime('now', '-5 minutes') LIMIT 1"
        );
        $recent->execute([$user['id']]);
        if ($recent->fetchColumn()) {
            return; // уже отправляли недавно — не спамим повторными запросами
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $stmt = Database::get()->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, datetime('now', '+1 hour'))"
        );
        $stmt->execute([$user['id'], $tokenHash]);

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $link = "$scheme://$host/reset-password.php?token=$token";
        Mailer::send(
            $user['email'],
            'Сброс пароля — VPS Router',
            "Запрошен сброс пароля для аккаунта «{$user['username']}».\n\nСсылка действительна 1 час:\n$link\n\nЕсли это были не вы — просто проигнорируйте письмо, пароль не изменится."
        );

        AuditLog::recordAuth($username, 'password_reset_requested', self::clientIp());
    }

    public static function resetPassword(string $token, string $newPassword): bool
    {
        if (strlen($newPassword) < 10) {
            throw new \InvalidArgumentException('Пароль должен быть не короче 10 символов');
        }

        $tokenHash = hash('sha256', $token);
        $stmt = Database::get()->prepare(
            "SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > datetime('now') LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $reset = $stmt->fetch();
        if (!$reset) {
            return false;
        }

        $pdo = Database::get();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $reset['user_id']]);
        $pdo->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE id = ?")
            ->execute([$reset['id']]);

        $userStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $userStmt->execute([$reset['user_id']]);
        AuditLog::recordAuth((string) $userStmt->fetchColumn(), 'password_reset', self::clientIp());

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    /**
     * Меняет логин текущего пользователя. Требует подтверждения паролем —
     * логин это учётные данные для входа, менять их без пароля нельзя.
     */
    public static function changeUsername(string $newUsername, string $currentPassword): void
    {
        $newUsername = trim($newUsername);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $newUsername)) {
            throw new \InvalidArgumentException('Логин: 3–32 символа, латиница, цифры, . _ -');
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            throw new \RuntimeException('Текущий пароль неверен');
        }
        $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
        $exists->execute([$newUsername, $_SESSION['user_id']]);
        if ($exists->fetchColumn()) {
            throw new \InvalidArgumentException('Такой логин уже занят');
        }
        $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$newUsername, $_SESSION['user_id']]);
        $_SESSION['username'] = $newUsername;
        AuditLog::recordAuth($newUsername, 'username_changed', self::clientIp());
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        $token = htmlspecialchars(self::csrfToken(), ENT_QUOTES);
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"$token\">";
    }

    public static function requireValidCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(400);
            die('Invalid CSRF token');
        }
    }

    /** Как requireValidCsrf(), но отвечает JSON 403 вместо die() с HTML — для api/*.php. */
    public static function requireValidCsrfJson(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            Http::error('invalid_csrf_token', 403);
        }
    }

    /** Требует активную сессию для api/*.php: JSON 401 вместо редиректа на /login.php. */
    public static function requireLoginJson(): void
    {
        if (!self::check()) {
            Http::error('unauthenticated', 401);
        }
    }
}
