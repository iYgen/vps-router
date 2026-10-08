<?php

namespace Tests;

use App\RiskScanner;
use PHPUnit\Framework\TestCase;

class RiskScannerTest extends TestCase
{
    public function testHostnameMatchesExactAndSubdomain(): void
    {
        $method = new \ReflectionMethod(RiskScanner::class, 'hostnameMatches');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, 'de.example.com', 'de.example.com'));
        $this->assertTrue($method->invoke(null, 'example.com', 'de.example.com'));
        $this->assertFalse($method->invoke(null, 'example.com', 'evil-example.com'));
        $this->assertFalse($method->invoke(null, '', 'anything.com'));
    }

    /** Недостижимый TEST-NET адрес (RFC 5737) — все порты закрыты/недоступны,
     *  scan() не должен падать и должен вернуть структуру с нулевым риском. */
    public function testScanUnreachableHostReturnsSafeStructure(): void
    {
        $result = RiskScanner::scan('192.0.2.1', 'unreachable.example');

        $this->assertSame(0, $result['score']);
        $this->assertNotEmpty($result['findings']);
        $this->assertArrayHasKey('checked_at', $result);
    }
}
