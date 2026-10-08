<?php

namespace Tests;

use App\Auth;
use App\Database;
use App\Models\AuditLog;
use PHPUnit\Framework\TestCase;

class AuthSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = Database::get();
        $pdo->exec('DELETE FROM users');
        $pdo->exec('DELETE FROM audit_log');
        $pdo->exec('DELETE FROM login_blocks');
        $pdo->exec('DELETE FROM password_resets');

        $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)')
            ->execute(['admin', password_hash('correct-horse-battery', PASSWORD_DEFAULT), 'admin@example.com']);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
    }

    public function testFailedLoginIsLoggedWithAuthCategory(): void
    {
        $this->assertFalse(Auth::attempt('admin', 'wrong-password'));

        $rows = AuditLog::recent(10, 'auth');
        $this->assertNotEmpty($rows);
        $this->assertSame('login_failed', $rows[0]['action']);
        $this->assertSame('203.0.113.50', $rows[0]['ip']);
    }

    public function testSuccessfulLoginIsLoggedSeparately(): void
    {
        $this->assertTrue(Auth::attempt('admin', 'correct-horse-battery'));

        $rows = AuditLog::recent(10, 'auth');
        $this->assertSame('login_success', $rows[0]['action']);
    }

    public function testFifthFailureBlocksIpAndSixthAttemptIsRejectedEvenWithCorrectPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(Auth::attempt('admin', 'wrong-password'));
        }

        $this->assertTrue(Auth::isIpBlocked('203.0.113.50'));

        // Даже с верным паролем шестая попытка отклоняется — пароль не должен
        // даже проверяться, пока IP заблокирован.
        $this->assertFalse(Auth::attempt('admin', 'correct-horse-battery'));

        $blockedRows = AuditLog::recent(10, 'auth');
        $this->assertSame('login_blocked', $blockedRows[0]['action']);
    }

    public function testDifferentIpIsNotAffectedByAnotherIpsFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Auth::attempt('admin', 'wrong-password');
        }
        $this->assertTrue(Auth::isIpBlocked('203.0.113.50'));
        $this->assertFalse(Auth::isIpBlocked('198.51.100.1'));
    }

    public function testRequestPasswordResetForUnknownUsernameIsSilentNoOp(): void
    {
        Auth::requestPasswordReset('no-such-user');
        $count = (int) Database::get()->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testRequestPasswordResetForUserWithoutEmailIsSilentNoOp(): void
    {
        Database::get()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute(['noemail', password_hash('whatever12', PASSWORD_DEFAULT)]);

        Auth::requestPasswordReset('noemail');
        $count = (int) Database::get()->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testResetPasswordHappyPath(): void
    {
        Auth::requestPasswordReset('admin');

        // Токен в открытом виде нигде не хранится (в БД только его sha256) —
        // подменяем хэш только что созданной записи на хэш известного тестового
        // токена, вместо перехвата письма.
        $id = Database::get()->query('SELECT id FROM password_resets ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertNotFalse($id);

        $token = 'test-token-value';
        Database::get()->prepare('UPDATE password_resets SET token_hash = ? WHERE id = ?')
            ->execute([hash('sha256', $token), $id]);

        $this->assertTrue(Auth::resetPassword($token, 'brand-new-password'));

        $stmt = Database::get()->prepare('SELECT password_hash FROM users WHERE username = ?');
        $stmt->execute(['admin']);
        $this->assertTrue(password_verify('brand-new-password', $stmt->fetchColumn()));
    }

    public function testResetPasswordRejectsShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Auth::resetPassword('irrelevant-token', 'short');
    }

    public function testResetPasswordRejectsExpiredToken(): void
    {
        $userId = Database::get()->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
        $token = 'expired-token-value';
        Database::get()->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, datetime('now', '-1 hour'))"
        )->execute([$userId, hash('sha256', $token)]);

        $this->assertFalse(Auth::resetPassword($token, 'brand-new-password'));
    }

    public function testResetPasswordRejectsAlreadyUsedToken(): void
    {
        $userId = Database::get()->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
        $token = 'used-token-value';
        Database::get()->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at, used_at) VALUES (?, ?, datetime('now', '+1 hour'), datetime('now'))"
        )->execute([$userId, hash('sha256', $token)]);

        $this->assertFalse(Auth::resetPassword($token, 'brand-new-password'));
    }

    public function testAuditLogRecentFiltersByCategory(): void
    {
        Auth::attempt('admin', 'wrong-password'); // category=auth
        AuditLog::record('settings.save', 'x'); // category=action (default)

        $authRows = AuditLog::recent(10, 'auth');
        foreach ($authRows as $row) {
            $this->assertSame('auth', $row['category']);
        }

        $actionRows = AuditLog::recent(10, 'action');
        foreach ($actionRows as $row) {
            $this->assertSame('action', $row['category']);
        }
    }
}
