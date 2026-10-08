<?php

namespace Tests;

use App\Singbox\ConfigValidator;
use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private function baseConfig(): array
    {
        return [
            'outbounds' => [
                ['type' => 'direct', 'tag' => 'direct-rf'],
                ['type' => 'block', 'tag' => 'block'],
                ['type' => 'vless', 'tag' => 'exit-6'],
            ],
            'route' => [
                'rule_set' => [
                    ['tag' => 'group-1', 'type' => 'local', 'format' => 'source', 'path' => '/x/group-1.json'],
                    ['tag' => 'geosite-youtube', 'type' => 'remote', 'format' => 'binary', 'url' => 'https://x'],
                ],
                'rules' => [
                    ['action' => 'sniff'],
                    ['rule_set' => ['group-1', 'geosite-youtube'], 'outbound' => 'exit-6'],
                ],
                'final' => 'direct-rf',
            ],
        ];
    }

    public function testValidConfigHasNoErrors(): void
    {
        $config = $this->baseConfig();
        $ruleSets = ['group-1' => ['version' => 1, 'rules' => [['domain' => ['a.test']]]]];
        $this->assertSame([], ConfigValidator::validate($config, $ruleSets));
    }

    public function testLocalRuleSetWithoutContentIsError(): void
    {
        $config = $this->baseConfig();
        // group-1 объявлен как local, но содержимого нет → файл не будет записан.
        $errors = ConfigValidator::validate($config, []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('group-1', implode("\n", $errors));
    }

    public function testUnknownOutboundIsError(): void
    {
        $config = $this->baseConfig();
        $config['route']['rules'][1]['outbound'] = 'exit-999';
        $errors = ConfigValidator::validate($config, ['group-1' => []]);
        $this->assertStringContainsString('exit-999', implode("\n", $errors));
    }

    public function testUnknownRuleSetReferenceIsError(): void
    {
        $config = $this->baseConfig();
        $config['route']['rules'][1]['rule_set'][] = 'group-404';
        $errors = ConfigValidator::validate($config, ['group-1' => []]);
        $this->assertStringContainsString('group-404', implode("\n", $errors));
    }

    public function testVersionWarnings(): void
    {
        $config = $this->baseConfig();
        $config['dns'] = ['servers' => [['type' => 'local', 'tag' => 'dns-local']], 'rules' => [], 'final' => 'dns-local'];
        $config['endpoints'] = [['type' => 'wireguard', 'tag' => 'exit-7']];

        // Старый движок -> предупреждения про DNS (1.12) и endpoints (1.11).
        $old = ConfigValidator::versionWarnings($config, '1.10.0');
        $this->assertNotEmpty($old);
        $this->assertStringContainsString('1.12.0', implode("\n", $old));

        // Свежий движок -> предупреждений нет.
        $this->assertSame([], ConfigValidator::versionWarnings($config, '1.14.1'));
        // Версия неизвестна -> пропускаем (не шумим).
        $this->assertSame([], ConfigValidator::versionWarnings($config, null));
    }

    public function testDnsDetourAndResolverChecked(): void
    {
        $config = $this->baseConfig();
        $config['dns'] = [
            'servers' => [
                ['type' => 'local', 'tag' => 'dns-local'],
                ['type' => 'https', 'tag' => 'dns-exit-6', 'server' => '1.1.1.1', 'detour' => 'exit-404'],
            ],
            'rules' => [['rule_set' => ['group-1'], 'server' => 'dns-missing']],
            'final' => 'dns-local',
        ];
        $config['route']['default_domain_resolver'] = ['server' => 'dns-nope'];
        $errors = ConfigValidator::validate($config, ['group-1' => []]);
        $joined = implode("\n", $errors);
        $this->assertStringContainsString('exit-404', $joined);      // неизвестный detour
        $this->assertStringContainsString('dns-missing', $joined);   // неизвестный dns-сервер в правиле
        $this->assertStringContainsString('dns-nope', $joined);      // неизвестный резолвер по умолчанию
    }
}
