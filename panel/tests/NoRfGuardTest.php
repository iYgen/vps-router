<?php

namespace Tests;

use App\Models\Rule;
use App\Models\RuleGroup;
use App\Singbox\ExitOutboundBuilder;
use App\Singbox\NoRfGuard;
use PHPUnit\Framework\TestCase;

class NoRfGuardTest extends TestCase
{
    private function group(string $suffix, ?int $exitId): array
    {
        $id = RuleGroup::create('norf-' . uniqid(), null);
        Rule::create($id, 'domain_suffix', $suffix);
        return ['id' => $id, 'enabled' => 1, 'exit_server_id' => $exitId, 'server_set_id' => null];
    }

    public function testDomainInExitGroupGoesToThatExitFirst(): void
    {
        $g = $this->group('chatgpt.com', 77);
        $rules = NoRfGuard::apply([['rule_set' => ['group-' . $g['id']], 'outbound' => 'exit-77']], [$g], [77 => ['id' => 77]], ['chatgpt.com']);
        $this->assertSame(['domain_suffix' => ['chatgpt.com'], 'outbound' => ExitOutboundBuilder::tag(77)], $rules[0]);
        $this->assertEmpty(array_filter($rules, fn($r) => ($r['action'] ?? '') === 'reject'));
    }

    public function testUnroutedDomainIsRejectedBeforeDirect(): void
    {
        $direct = $this->group('google.com', null);  // «Google напрямую» перехватил бы gemini.google.com
        $input = [
            ['rule_set' => ['geosite-openai'], 'outbound' => 'exit-5'],
            ['rule_set' => ['group-' . $direct['id']], 'outbound' => 'direct-rf'],
        ];
        $rules = NoRfGuard::apply($input, [$direct], [5 => ['id' => 5]], ['gemini.google.com', 'claude.ai']);
        // geosite-группа с exit выше — по-прежнему первой; reject — сразу перед direct-rf
        $this->assertSame('exit-5', $rules[0]['outbound']);
        $this->assertSame(['domain_suffix' => ['gemini.google.com', 'claude.ai'], 'action' => 'reject'], $rules[1]);
        $this->assertSame('direct-rf', $rules[2]['outbound']);
    }

    public function testRejectAppendedWhenNoDirectRules(): void
    {
        $rules = NoRfGuard::apply([['rule_set' => ['x'], 'outbound' => 'exit-1']], [], [], ['openai.com']);
        $this->assertSame('reject', end($rules)['action']);
    }

    public function testExitMissingMeansBlock(): void
    {
        $g = $this->group('openai.com', 999);  // exit удалён/недоступен → RouteBuilder дал бы direct-rf
        $rules = NoRfGuard::apply([], [$g], [], ['api.openai.com']);
        $this->assertSame(['domain_suffix' => ['api.openai.com'], 'action' => 'reject'], $rules[0]);
    }

    public function testParse(): void
    {
        $this->assertSame(['chatgpt.com', 'claude.ai', 'x.ai'], NoRfGuard::parse("https://chatgpt.com/c/1\n# комментарий\n Claude.AI , x.ai\nnot a domain\n"));
    }
}
