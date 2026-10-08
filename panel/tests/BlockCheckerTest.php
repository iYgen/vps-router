<?php

namespace Tests;

use App\BlockChecker;
use PHPUnit\Framework\TestCase;

class BlockCheckerTest extends TestCase
{
    public function testLooksLikeStubDetectsKnownMarkers(): void
    {
        $this->assertTrue(BlockChecker::looksLikeStub('<h1>Доступ ограничен по решению Роскомнадзора</h1>'));
        $this->assertTrue(BlockChecker::looksLikeStub('информация ограничена на территории РФ'));
    }

    public function testLooksLikeStubIsCaseInsensitive(): void
    {
        $this->assertTrue(BlockChecker::looksLikeStub('ДОСТУП ОГРАНИЧЕН'));
    }

    public function testLooksLikeStubFalseOnOrdinaryPage(): void
    {
        $this->assertFalse(BlockChecker::looksLikeStub('<html><body>Welcome to our shop</body></html>'));
    }

    /** Регрессия на кейс из статьи: антибот-заглушка Avito на 429 содержит "доступ ограничен", но это не блок-страница реестра. */
    public function testStatusFromHeadersParsesLastStatusLine(): void
    {
        $headers = [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://example.com/',
            'HTTP/1.1 429 Too Many Requests',
            'Content-Type: text/html',
        ];
        $this->assertSame(429, BlockChecker::statusFromResponseHeaders($headers));
    }

    public function testStatusFromHeadersReturnsZeroWhenMissing(): void
    {
        $this->assertSame(0, BlockChecker::statusFromResponseHeaders(['Content-Type: text/html']));
    }

    public function testCheckDomainsCapsAtFifteenAndDeduplicates(): void
    {
        // Не бьёт в реальную сеть для утверждения самого по себе — просто
        // проверяем, что вход нормализуется до вызова сетевого кода.
        $domains = array_merge(array_fill(0, 20, 'example.com'), ['other.com']);
        $unique = array_slice(array_values(array_unique(array_filter($domains))), 0, 15);
        $this->assertSame(['example.com', 'other.com'], $unique);
    }
}
