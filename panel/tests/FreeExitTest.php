<?php

namespace Tests;

use App\DomainSensitivity;
use App\FreeSubscriptions;
use App\Models\ExitServer;
use App\Models\Setting;
use App\SingboxConfigBuilder;
use PHPUnit\Framework\TestCase;

class FreeExitTest extends TestCase
{
    private const SAMPLE = '{"outbounds":[
        {"type":"selector","tag":"select","outbounds":["auto"]},
        {"type":"urltest","tag":"auto","outbounds":[]},
        {"type":"direct","tag":"direct"},
        {"type":"vless","tag":"vless-123","server":"est.example.su","server_port":443,"uuid":"u-1","flow":"xtls-rprx-vision","tls":{"enabled":true,"reality":{"enabled":true,"public_key":"PK"},"server_name":"est.example.su"}},
        {"type":"hysteria2","tag":"hysteria2-456","server":"hy.example.net","server_port":8443,"password":"p"},
        {"type":"wireguard","tag":"wg-1","server":"w.example","server_port":51820}
    ],"route":{}}';

    public function testParseFiltersSpecialsAndWireguard(): void
    {
        $nodes = FreeSubscriptions::parse(self::SAMPLE, 'FI');
        $tags = array_column($nodes, 'tag');
        // Keeps vless + hysteria2; drops selector/urltest/direct/wireguard.
        $this->assertContains('vless-123', $tags);
        $this->assertContains('hysteria2-456', $tags);
        $this->assertNotContains('select', $tags);
        $this->assertNotContains('auto', $tags);
        $this->assertNotContains('wg-1', $tags);
        $this->assertSame('FI', $nodes[0]['country']);
        $this->assertSame('est.example.su', $nodes[0]['server']);
        $this->assertArrayHasKey('raw', $nodes[0]);
    }

    public function testSensitivityClassification(): void
    {
        $this->assertSame('safe', DomainSensitivity::classify('youtube.com')['level']);
        $this->assertSame('safe', DomainSensitivity::classify('rr3---sn.googlevideo.com')['level']);
        $this->assertSame('sensitive', DomainSensitivity::classify('online.sberbank.ru')['level']);
        $this->assertSame('sensitive', DomainSensitivity::classify('gosuslugi.ru')['level']);
        $this->assertSame('sensitive', DomainSensitivity::classify('paypal.com')['level']);
        $this->assertSame('neutral', DomainSensitivity::classify('example.com')['level']);
        $this->assertSame('safe', DomainSensitivity::classify('youtube', 'geosite')['level']);
        $this->assertSame('sensitive', DomainSensitivity::classify('category-finance', 'geosite')['level']);
    }

    public function testDangerBoostViaUntrustedExit(): void
    {
        $safeDirect = DomainSensitivity::dangerForRoute('youtube.com', 'domain_suffix', false);
        $safeFree = DomainSensitivity::dangerForRoute('youtube.com', 'domain_suffix', true);
        $this->assertGreaterThan($safeDirect['score'], $safeFree['score']);

        $sensFree = DomainSensitivity::dangerForRoute('sberbank.ru', 'domain_suffix', true);
        $this->assertGreaterThanOrEqual(90, $sensFree['score']);
        $this->assertTrue($sensFree['via_untrusted']);
    }

    public function testFreeExitBuildsRawOutbound(): void
    {
        Setting::set('reality_listen_ip', '203.0.113.10');
        Setting::set('reality_server_name', 'www.example.com');
        Setting::set('reality_private_key', 'testkey');
        Setting::set('reality_short_id', 'abcd1234');

        $raw = ['type' => 'trojan', 'tag' => 'trojan-orig', 'server' => 'free.example.net', 'server_port' => 443, 'password' => 'secret'];
        $id = ExitServer::create([
            'name' => 'free-' . uniqid(),
            'endpoint_host' => 'free.example.net',
            'endpoint_port' => 443,
            'status' => 'active',
            'protocol' => 'trojan',
            'protocol_params' => ['_raw' => $raw],
            'source' => 'free',
            'country' => 'FI',
        ]);

        $es = ExitServer::find($id);
        $this->assertSame('free', $es['source']);

        $built = (new SingboxConfigBuilder())->build();
        $outbound = null;
        foreach ($built['config']['outbounds'] as $ob) {
            if (($ob['tag'] ?? '') === SingboxConfigBuilder::exitOutboundTag($id)) {
                $outbound = $ob;
            }
        }
        $this->assertNotNull($outbound, 'free exit должен попасть в outbounds');
        $this->assertSame('trojan', $outbound['type']);
        $this->assertSame('free.example.net', $outbound['server']);
        // tag переставлен на exit-N, оригинальный tag затёрт.
        $this->assertSame(SingboxConfigBuilder::exitOutboundTag($id), $outbound['tag']);
    }
}
