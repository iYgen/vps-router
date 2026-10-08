<?php

namespace Tests;

use App\ExternalListImporter;
use PHPUnit\Framework\TestCase;

class ExternalListImporterTest extends TestCase
{
    public function testParseAutoDetectsDomainsAndCidr(): void
    {
        $raw = implode("\n", [
            '# комментарий', '', '*.youtube.com', '.example.com',
            '203.0.113.0/24', '8.8.8.8', 'not a domain !!!', 'good-domain.org',
        ]);
        $r = ExternalListImporter::parse($raw, 'auto');
        $this->assertContains('youtube.com', $r['domains']);   // *. срезан
        $this->assertContains('example.com', $r['domains']);   // ведущая точка срезана
        $this->assertContains('good-domain.org', $r['domains']);
        $this->assertContains('203.0.113.0/24', $r['ips']);
        $this->assertContains('8.8.8.8/32', $r['ips']);        // голый IP -> /32
        $this->assertFalse($r['truncated']);
    }

    public function testDomainsTypeIgnoresCidr(): void
    {
        $r = ExternalListImporter::parse("a.com\n10.0.0.0/8", 'domains');
        $this->assertContains('a.com', $r['domains']);
        $this->assertSame([], $r['ips']);
    }

    public function testSourcesListed(): void
    {
        $ids = array_column(ExternalListImporter::sources(), 'id');
        $this->assertContains('antizapret-domains', $ids);
    }

    public function testImportRejectsPrivateUrlAndBadInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // приватный хост — SSRF-защита (и заодно без exit не дойдёт)
        ExternalListImporter::import('http://127.0.0.1/list.txt', 1, null, null);
    }
}
