<?php

namespace Tests;

use App\Ssh;
use PHPUnit\Framework\TestCase;

class SshMetricsParsingTest extends TestCase
{
    private function parse(string $output): array
    {
        $method = new \ReflectionMethod(Ssh::class, 'parseMetrics');
        $method->setAccessible(true);
        return $method->invoke(null, $output);
    }

    public function testParsesTypicalLinuxOutput(): void
    {
        $output = <<<OUT
---LOAD---
0.15 0.10 0.05 1/234 5678
---MEM---
              total        used        free      shared  buff/cache   available
Mem:           1987         512         200          50         1275        1300
Swap:             0           0           0
---DISK---
Filesystem     1024-blocks     Used Available Capacity Mounted on
/dev/sda1         20961280  8123456  11837824      41% /
OUT;

        $result = $this->parse($output);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['raw_error']);
        $this->assertSame(['1min' => 0.15, '5min' => 0.10, '15min' => 0.05], $result['load']);
        $this->assertSame(1987, $result['mem']['total_mb']);
        $this->assertSame(687, $result['mem']['used_mb']);
        $this->assertSame(35, $result['mem']['used_percent']);
        $this->assertSame(20961280, $result['disk']['total_kb']);
        $this->assertSame(41, $result['disk']['used_percent']);
    }

    public function testCpuPercentUsesCoreCount(): void
    {
        $result = $this->parse("---LOAD---\n1.00 0.50 0.25 1/50 123\n---CPUS---\n4\n");

        $this->assertSame(4, $result['cpus']);
        $this->assertSame(25, $result['cpu_percent']);
    }

    public function testGracefullyDegradesOnEmptyOutput(): void
    {
        $result = $this->parse('');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['load']);
        $this->assertNull($result['mem']);
        $this->assertNull($result['disk']);
        $this->assertNotNull($result['raw_error']);
    }

    public function testHandlesMissingSectionGracefully(): void
    {
        // free/df недоступны (минимальный образ) — есть только loadavg.
        $output = "---LOAD---\n0.02 0.01 0.00 1/50 123\n---MEM---\n---DISK---\n";

        $result = $this->parse($output);

        $this->assertTrue($result['ok']);
        $this->assertSame(0.02, $result['load']['1min']);
        $this->assertNull($result['mem']);
        $this->assertNull($result['disk']);
    }
}
