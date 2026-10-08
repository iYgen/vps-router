<?php

namespace Tests;

use App\Ssh;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет, что host/user никогда не долетают до phpseclib в виде,
 * который можно интерпретировать как флаг/инъекцию. Реальных сетевых
 * подключений эти тесты не делают.
 */
class SshCommandSafetyTest extends TestCase
{
    /** @dataProvider maliciousHosts */
    public function testMaliciousHostRejected(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ssh::testConnection($host, 22, 'root', 'dummy-key');
    }

    public static function maliciousHosts(): array
    {
        return [
            'leading dash (opt-injection)' => ['-oProxyCommand=touch /tmp/pwned'],
            'shell metachar semicolon' => ['127.0.0.1; rm -rf /'],
            'command substitution' => ['$(whoami)'],
            'backticks' => ['`id`'],
            'space' => ['example com'],
            'pipe' => ['host|cat /etc/passwd'],
        ];
    }

    /** @dataProvider maliciousUsers */
    public function testMaliciousUserRejected(string $user): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ssh::testConnection('example.com', 22, $user, 'dummy-key');
    }

    public static function maliciousUsers(): array
    {
        return [
            'leading dash' => ['-oProxyCommand=evil'],
            'semicolon' => ['root; ls'],
            'space' => ['root user'],
        ];
    }

    public function testValidHostAndUserPassValidation(): void
    {
        // Валидный host проходит валидацию и падает уже на этапе разбора
        // заведомо мусорного приватного ключа — не на InvalidArgumentException.
        $result = Ssh::testConnection('example.com', 22, 'root', 'not-a-real-key');
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['raw_error']);
    }

    public function testInvalidPortRejectedWithoutException(): void
    {
        $result = Ssh::testConnection('example.com', 70000, 'root', 'dummy-key');
        $this->assertFalse($result['ok']);
    }
}
