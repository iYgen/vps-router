<?php

namespace App;

use App\Models\Setting;
use App\Models\Subscriber;

/**
 * Аутентификация подписчика в клиентском портале — ОТДЕЛЬНО от админской Auth.
 * Ключ сессии свой (portal_sid), поэтому вход в портал не даёт доступа к панели
 * и наоборот. CSRF переиспучаем из Auth (токен в той же сессии).
 */
class PortalAuth
{
    private const KEY = 'portal_sid';

    public static function currentId(): ?int
    {
        $id = $_SESSION[self::KEY] ?? null;
        return $id ? (int) $id : null;
    }

    public static function current(): ?array
    {
        $id = self::currentId();
        return $id ? Subscriber::find($id) : null;
    }

    public static function check(): bool
    {
        return self::current() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /portal-login.php');
            exit;
        }
    }

    public static function login(string $email, string $password): bool
    {
        $s = Subscriber::findByEmail($email);
        if (!$s || empty($s['password_hash']) || !password_verify($password, (string) $s['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION[self::KEY] = (int) $s['id'];
        return true;
    }

    /** Регистрация: уникальный email, пароль ≥ 8. Возвращает id или бросает. */
    public static function register(string $name, string $email, string $password): int
    {
        $email = trim($email);
        $name = trim($name);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(I18n::t('portal.err.bad_input'));
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException(I18n::t('portal.err.pw_short'));
        }
        if (Subscriber::findByEmail($email)) {
            throw new \RuntimeException(I18n::t('portal.err.email_taken'));
        }
        $id = Subscriber::create(['name' => $name, 'email' => $email]);
        Subscriber::setPassword($id, $password);
        if (self::verifyRequired()) {
            self::sendVerification($id);
        } else {
            Subscriber::markVerified($id);
        }
        session_regenerate_id(true);
        $_SESSION[self::KEY] = $id;
        return $id;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /** Сменить пароль авторизованного подписчика (проверяя текущий). */
    public static function changePassword(int $id, string $current, string $new): void
    {
        $s = Subscriber::find($id);
        if (!$s || empty($s['password_hash']) || !password_verify($current, (string) $s['password_hash'])) {
            throw new \RuntimeException(I18n::t('portal.err.pw_current'));
        }
        if (strlen($new) < 8) {
            throw new \InvalidArgumentException(I18n::t('portal.err.pw_short'));
        }
        Subscriber::setPassword($id, $new);
    }

    /** Запросить сброс: создаёт токен и шлёт письмо. Наличие email не раскрываем. */
    public static function requestReset(string $email): void
    {
        $s = Subscriber::findByEmail($email);
        if (!$s) {
            return;
        }
        $token = bin2hex(random_bytes(20));
        Subscriber::setResetToken((int) $s['id'], $token, date('Y-m-d H:i:s', time() + 3600));
        \App\PortalMail::send((string) $s['email'], 'reset', [
            '{name}' => (string) $s['name'],
            '{link}' => \App\PortalMail::portalBase() . '/portal-reset.php?token=' . $token,
        ]);
    }

    /** Сбросить пароль по токену и войти. @return bool успех */
    public static function resetWithToken(string $token, string $new): bool
    {
        $s = Subscriber::findByResetToken($token);
        if (!$s) {
            return false;
        }
        if (strlen($new) < 8) {
            throw new \InvalidArgumentException(I18n::t('portal.err.pw_short'));
        }
        Subscriber::setPassword((int) $s['id'], $new);
        Subscriber::clearReset((int) $s['id']);
        session_regenerate_id(true);
        $_SESSION[self::KEY] = (int) $s['id'];
        return true;
    }

    /** Подтверждение email включено (по умолчанию да). */
    public static function verifyRequired(): bool
    {
        return Setting::get('portal_email_verify', '1') === '1';
    }

    /** Нужно ли требовать подтверждения у текущего подписчика перед действиями. */
    public static function needsVerification(?array $subscriber = null): bool
    {
        $s = $subscriber ?? self::current();
        return self::verifyRequired() && $s && empty($s['email_verified']);
    }

    /** Сгенерировать токен и отправить письмо с подтверждением. */
    public static function sendVerification(int $subscriberId): bool
    {
        $s = Subscriber::find($subscriberId);
        if (!$s || empty($s['email'])) {
            return false;
        }
        $token = bin2hex(random_bytes(20));
        Subscriber::setVerifyToken($subscriberId, $token);
        return \App\PortalMail::send((string) $s['email'], 'verify', [
            '{name}' => (string) $s['name'],
            '{link}' => \App\PortalMail::portalBase() . '/portal-verify.php?token=' . $token,
        ]);
    }

    /** Подтвердить по токену. @return bool успех */
    public static function verifyToken(string $token): bool
    {
        $s = Subscriber::findByVerifyToken($token);
        if (!$s) {
            return false;
        }
        Subscriber::markVerified((int) $s['id']);
        return true;
    }
}
