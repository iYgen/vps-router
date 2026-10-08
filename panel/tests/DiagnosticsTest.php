<?php

namespace Tests;

use App\Diagnostics;
use PHPUnit\Framework\TestCase;

/**
 * Diagnostics::run() целиком делает сетевые запросы (включая check-host.net
 * с 5-секундной задержкой на поллинг) — не гоняем это в юнит-тестах, чтобы
 * не замедлять CI и не зависеть от сети. Проверяем только чистую логику
 * через рефлексию; полный прогон run() проверен вручную на проде.
 */
class DiagnosticsTest extends TestCase
{
    public function testCheckDnsSkipsResolutionForRawIp(): void
    {
        $method = new \ReflectionMethod(Diagnostics::class, 'checkDns');
        $method->setAccessible(true);

        $result = $method->invoke(null, '195.245.239.103');
        $this->assertTrue($result['resolved']);
    }
}
