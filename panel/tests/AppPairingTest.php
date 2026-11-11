<?php

namespace Tests;

use App\AppPairing;
use App\Auth;
use App\Database;
use PHPUnit\Framework\TestCase;

class AppPairingTest extends TestCase
{
    private int $userId;

    protected function setUp(): void
    {
        $pdo = Database::get();
        foreach (['users', 'audit_log', 'login_blocks', 'app_pairings', 'app_tokens'] as $t) {
            $pdo->exec("DELETE FROM $t");
        }
        $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)')
            ->execute(['admin', password_hash('x-very-long-password', PASSWORD_DEFAULT), 'admin@example.com']);
        $this->userId = (int) $pdo->lastInsertId();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.60';
        $_SESSION = [];
    }

    public function testCodeIsSingleUseAndGivesWorkingToken(): void
    {
        $code = AppPairing::createCode($this->userId, null);
        $r = AppPairing::redeem($code, 'Pixel 8');
        $this->assertNotNull($r);
        $this->assertSame('admin', $r['username']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r['token']);

        // Второй раз тот же код не срабатывает
        $this->assertNull(AppPairing::redeem($code, 'Pixel 8'));

        $t = AppPairing::findToken($r['token']);
        $this->assertNotNull($t);
        $this->assertSame($this->userId, $t['user_id']);
        $this->assertTrue(Auth::loginAsApp($t['user_id'], 'test'));
        $this->assertSame('admin', Auth::username());
    }

    public function testExpiredCodeIsRejected(): void
    {
        $code = AppPairing::createCode($this->userId, 5);
        Database::get()->exec("UPDATE app_pairings SET expires_at = datetime('now', '-1 minute')");
        $this->assertNull(AppPairing::redeem($code, 'Pixel'));
    }

    public function testDeviceFromQrIsKeptAndRevokeWorks(): void
    {
        $r = AppPairing::redeem(AppPairing::createCode($this->userId, 7), 'Phone');
        $this->assertSame(7, $r['client_id']);
        $t = AppPairing::findToken($r['token']);
        $this->assertSame(7, $t['client_id']);

        AppPairing::revoke($this->userId, $t['id']);
        $this->assertNull(AppPairing::findToken($r['token']));
    }

    public function testOnlyHashesAreStored(): void
    {
        $code = AppPairing::createCode($this->userId, null);
        $r = AppPairing::redeem($code, 'Phone');
        $dump = json_encode(Database::get()->query('SELECT * FROM app_pairings')->fetchAll())
            . json_encode(Database::get()->query('SELECT * FROM app_tokens')->fetchAll());
        $this->assertStringNotContainsString($code, $dump);
        $this->assertStringNotContainsString($r['token'], $dump);
    }
}
