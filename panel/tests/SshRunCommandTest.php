<?php

namespace Tests;

use App\Ssh;
use PHPUnit\Framework\TestCase;

class SshRunCommandTest extends TestCase
{
    public function testRejectsBadPortWithoutNetwork(): void
    {
        $r = Ssh::runCommand('203.0.113.10', 0, 'root', "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----", 'systemctl is-active sing-box');
        $this->assertFalse($r['ok']);
        $this->assertSame('Некорректный порт', $r['error']);
    }

    public function testRejectsUnsafeHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ssh::runCommand('bad host; rm -rf', 22, 'root', 'key', 'systemctl is-active sing-box');
    }

    public function testBadKeyReturnsError(): void
    {
        $r = Ssh::runCommand('203.0.113.10', 22, 'root', 'not-a-valid-key', 'x');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Ключ', (string) $r['error']);
    }
}
