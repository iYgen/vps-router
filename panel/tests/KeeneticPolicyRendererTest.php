<?php

namespace Tests;

use App\Keenetic\KeeneticPolicyRenderer;
use PHPUnit\Framework\TestCase;

class KeeneticPolicyRendererTest extends TestCase
{
    private function samplePolicy(): array
    {
        return [
            'version' => 1,
            'default_action' => 'direct_local',
            'rules' => [
                [
                    'priority' => 1000,
                    'matcher' => ['type' => 'domain_suffix', 'value' => 'youtube.com'],
                    'action' => ['type' => 'proxy', 'target' => 'exit_server:5'],
                    'source_route' => 'YouTube',
                ],
                [
                    'priority' => 990,
                    'matcher' => ['type' => 'ip_cidr', 'value' => '1.2.3.0/24'],
                    'action' => ['type' => 'proxy', 'target' => 'exit_server:5'],
                    'source_route' => 'SomeCidr',
                ],
                [
                    'priority' => 980,
                    'matcher' => ['type' => 'domain_suffix', 'value' => 'other-exit.example.com'],
                    'action' => ['type' => 'proxy', 'target' => 'exit_server:9'],
                    'source_route' => 'OtherExit',
                ],
                [
                    'priority' => 970,
                    'matcher' => ['type' => 'domain_suffix', 'value' => 'pooled.example.com'],
                    'action' => ['type' => 'proxy', 'target' => 'server_set:2'],
                    'source_route' => 'Pooled',
                ],
            ],
        ];
    }

    public function testRendersRoutesMatchingDeviceExitServer(): void
    {
        $renderer = new KeeneticPolicyRenderer();
        $result = $renderer->render($this->samplePolicy(), 5, 'Wireguard0');

        $this->assertCount(2, $result['routes']);
        $values = array_column($result['routes'], 'value');
        $this->assertContains('youtube.com', $values);
        $this->assertContains('1.2.3.0/24', $values);
        foreach ($result['routes'] as $r) {
            $this->assertSame('Wireguard0', $r['interface']);
            $this->assertStringStartsWith('panel:', $r['description']);
        }
    }

    public function testSkipsRulesTargetingDifferentExitServerWithWarning(): void
    {
        $renderer = new KeeneticPolicyRenderer();
        $result = $renderer->render($this->samplePolicy(), 5, 'Wireguard0');

        $this->assertNotEmpty($result['warnings']);
        $joined = implode(' ', $result['warnings']);
        $this->assertStringContainsString('other-exit.example.com', $joined);
    }

    public function testSkipsServerSetTargetsWithWarning(): void
    {
        $renderer = new KeeneticPolicyRenderer();
        $result = $renderer->render($this->samplePolicy(), 5, 'Wireguard0');

        $joined = implode(' ', $result['warnings']);
        $this->assertStringContainsString('pooled.example.com', $joined);
    }

    public function testEmptyRulesProduceNoRoutesNoWarnings(): void
    {
        $renderer = new KeeneticPolicyRenderer();
        $result = $renderer->render(['rules' => []], 5, 'Wireguard0');

        $this->assertSame([], $result['routes']);
        $this->assertSame([], $result['warnings']);
    }
}
