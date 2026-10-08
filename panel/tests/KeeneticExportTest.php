<?php

namespace Tests;

use App\KeeneticExport;
use App\Models\ExitServer;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use PHPUnit\Framework\TestCase;

class KeeneticExportTest extends TestCase
{
    public function testOnlyExportsExitBoundDestinations(): void
    {
        $exit = ExitServer::create([
            'name' => 'ke-exit-' . uniqid(), 'endpoint_host' => 'e.example', 'endpoint_port' => 443,
            'status' => 'active', 'protocol' => 'vless', 'protocol_params' => ['_raw' => ['type' => 'vless']], 'source' => 'free',
        ]);
        // Маршрут через exit — попадает в выгрузку.
        $viaExit = RuleGroup::create('ke-yt-' . uniqid(), $exit);
        Rule::create($viaExit, 'domain_suffix', 'youtube.com');
        Rule::create($viaExit, 'ip_cidr', '203.0.113.0/24');
        // Маршрут «напрямую» — НЕ попадает.
        $direct = RuleGroup::create('ke-direct-' . uniqid(), null);
        Rule::create($direct, 'domain_suffix', 'example-direct.test');

        $x = KeeneticExport::routedDestinations(null, false);
        $this->assertContains('youtube.com', $x['domains']);
        $this->assertContains('203.0.113.0/24', $x['ips']);
        $this->assertNotContains('example-direct.test', $x['domains']);

        $txt = KeeneticExport::asText(null, false);
        $this->assertStringContainsString('youtube.com', $txt);
        $this->assertStringContainsString('Keenetic', $txt);
    }

    public function testBatConvertsCidrToRouteAddCommands(): void
    {
        $exit = ExitServer::create([
            'name' => 'ke-bat-' . uniqid(), 'endpoint_host' => 'e.example', 'endpoint_port' => 443,
            'status' => 'active', 'protocol' => 'vless', 'protocol_params' => ['_raw' => ['type' => 'vless']], 'source' => 'free',
        ]);
        $g = RuleGroup::create('ke-bat-g-' . uniqid(), $exit);
        Rule::create($g, 'ip_cidr', '204.8.99.146/32');
        Rule::create($g, 'ip_cidr', '10.20.30.0/24');

        $bat = KeeneticExport::asBat(null, false);
        // Точный формат как в примере пользователя: route ADD <ip> MASK <mask> 0.0.0.0
        $this->assertStringContainsString('route ADD 204.8.99.146 MASK 255.255.255.255 0.0.0.0', $bat);
        // /24 -> 255.255.255.0.
        $this->assertStringContainsString('route ADD 10.20.30.0 MASK 255.255.255.0 0.0.0.0', $bat);
        // Только маршруты: без @echo off, REM, set GW и %GW%.
        $this->assertStringNotContainsString('%GW%', $bat);
        $this->assertStringNotContainsString('@echo', $bat);
        $this->assertStringNotContainsString('REM', $bat);
        // Каждая непустая строка начинается с "route ADD".
        foreach (array_filter(explode("\r\n", $bat), fn($l) => trim($l) !== '') as $line) {
            $this->assertStringStartsWith('route ADD ', $line);
        }
        // .bat — CRLF-переводы строк.
        $this->assertStringContainsString("\r\n", $bat);
    }

    public function testBatChunksSplitByLimit(): void
    {
        $exit = ExitServer::create([
            'name' => 'ke-chunk-' . uniqid(), 'endpoint_host' => 'e.example', 'endpoint_port' => 443,
            'status' => 'active', 'protocol' => 'vless', 'protocol_params' => ['_raw' => ['type' => 'vless']], 'source' => 'free',
        ]);
        // Отдельный роутер, чтобы считать только свои маршруты (БД общая в тестах).
        $routerId = Server::create(['name' => 'ke-chunk-r-' . uniqid(), 'role' => 'router']);
        $g = RuleGroup::create('ke-chunk-g-' . uniqid(), $exit, null, null, null, 'manual', null, $routerId);
        // 2500 уникальных /32 -> должно получиться 3 файла по <=1000.
        for ($i = 0; $i < 2500; $i++) {
            Rule::create($g, 'ip_cidr', '198.51.' . intdiv($i, 256) . '.' . ($i % 256) . '/32');
        }

        $files = KeeneticExport::asBatChunks($routerId, false, 1000);
        $this->assertCount(3, $files);
        foreach ($files as $name => $content) {
            $this->assertStringEndsWith('.bat', $name);
            $routeLines = array_filter(explode("\r\n", $content), fn($l) => str_starts_with($l, 'route ADD'));
            $this->assertLessThanOrEqual(1000, count($routeLines));
        }
        // Суммарно все 2500 маршрутов сохранены.
        $total = 0;
        foreach ($files as $content) {
            $total += count(array_filter(explode("\r\n", $content), fn($l) => str_starts_with($l, 'route ADD')));
        }
        $this->assertSame(2500, $total);
    }

    public function testSingleChunkKeepsBatExtension(): void
    {
        $exit = ExitServer::create([
            'name' => 'ke-one-' . uniqid(), 'endpoint_host' => 'e.example', 'endpoint_port' => 443,
            'status' => 'active', 'protocol' => 'vless', 'protocol_params' => ['_raw' => ['type' => 'vless']], 'source' => 'free',
        ]);
        $routerId = Server::create(['name' => 'ke-one-r-' . uniqid(), 'role' => 'router']);
        $g = RuleGroup::create('ke-one-g-' . uniqid(), $exit, null, null, null, 'manual', null, $routerId);
        Rule::create($g, 'ip_cidr', '203.0.113.5/32');

        $files = KeeneticExport::asBatChunks($routerId, false, 1000);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.bat', array_key_first($files));
        $this->assertStringNotContainsString('part', array_key_first($files));
    }

    public function testZipHasValidSignatureAndEntries(): void
    {
        $zip = KeeneticExport::zip(['a.bat' => "route ADD 1.1.1.1 MASK 255.255.255.255 0.0.0.0\r\n", 'b.bat' => "x\r\n"]);
        // Локальная сигнатура PK\003\004 в начале.
        $this->assertSame("PK\x03\x04", substr($zip, 0, 4));
        // End of central directory сигнатура PK\005\006 присутствует.
        $this->assertStringContainsString("PK\x05\x06", $zip);
        // Имена файлов внутри архива.
        $this->assertStringContainsString('a.bat', $zip);
        $this->assertStringContainsString('b.bat', $zip);
    }
}
