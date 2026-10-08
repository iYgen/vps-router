<?php

namespace Tests;

use App\Database;
use App\Http;
use App\Models\AuditLog;
use PHPUnit\Framework\TestCase;

/**
 * Http::guard() вызывает Auth::requireLoginJson()/requireValidCsrfJson(),
 * которые завершают процесс при неудаче — здесь тестируем только приватный
 * logIfSuspicious() напрямую через Reflection, не проходя весь guard().
 */
class HttpWafLogTest extends TestCase
{
    protected function setUp(): void
    {
        Database::get()->exec('DELETE FROM audit_log');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.60';
    }

    private function invokeLogIfSuspicious(): void
    {
        $method = new \ReflectionMethod(Http::class, 'logIfSuspicious');
        $method->setAccessible(true);
        $method->invoke(null);
    }

    public function testSuspiciousUriIsLoggedAsBlockCategory(): void
    {
        $_SERVER['REQUEST_URI'] = "/api/servers.php?id=1' or '1'='1";
        $_GET = [];

        $this->invokeLogIfSuspicious();

        $rows = AuditLog::recent(10, 'block');
        $this->assertNotEmpty($rows);
        $this->assertSame('suspicious_request', $rows[0]['action']);
        $this->assertSame('203.0.113.60', $rows[0]['ip']);
    }

    public function testSuspiciousGetParamIsLogged(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/servers.php';
        $_GET = ['name' => '<script>alert(1)</script>'];

        $this->invokeLogIfSuspicious();

        $rows = AuditLog::recent(10, 'block');
        $this->assertNotEmpty($rows);
        $this->assertSame('suspicious_request', $rows[0]['action']);
    }

    public function testOrdinaryRequestIsNotLogged(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/servers.php?id=1';
        $_GET = ['id' => '1'];

        $this->invokeLogIfSuspicious();

        $this->assertEmpty(AuditLog::recent(10, 'block'));
    }
}
