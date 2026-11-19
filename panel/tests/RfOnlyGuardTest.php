<?php

namespace Tests;

use App\Singbox\RfOnlyGuard;
use PHPUnit\Framework\TestCase;

class RfOnlyGuardTest extends TestCase
{
    public function testRulesGoFirstAndToDirectRf(): void
    {
        $in = [['rule_set' => ['group-1'], 'outbound' => 'exit-6']];
        $out = RfOnlyGuard::apply($in, ['ipwho.is', 'mos.ru'], ['94.79.51.0/24']);
        $this->assertSame(['domain_suffix' => ['ipwho.is', 'mos.ru'], 'outbound' => 'direct-rf'], $out[0]);
        $this->assertSame(['ip_cidr' => ['94.79.51.0/24'], 'outbound' => 'direct-rf'], $out[1]);
        $this->assertSame('exit-6', $out[2]['outbound']);
    }

    public function testNoRfDomainsWin(): void
    {
        $out = RfOnlyGuard::apply([], ['claude.ai', 'api.openai.com', 'ipinfo.io'], [], ['claude.ai', 'openai.com']);
        $this->assertSame(['ipinfo.io'], $out[0]['domain_suffix']);
    }

    public function testEmptyListsAddNothing(): void
    {
        $in = [['outbound' => 'exit-1']];
        $this->assertSame($in, RfOnlyGuard::apply($in, [], []));
    }

    public function testParseCidrs(): void
    {
        $this->assertSame(
            ['94.79.51.0/24', '8.8.8.8/32', '2a02:6b8::/32'],
            RfOnlyGuard::parseCidrs("94.79.51.0/24 # mos\n8.8.8.8, 2a02:6b8::/32\n1.0.0.0/4\nbad/24\n10.0.0.0/40")
        );
    }

    public function testDefaultMark(): void
    {
        $this->assertTrue(RfOnlyGuard::isDefault(null));
        $this->assertTrue(RfOnlyGuard::isDefault(' @default '));
        $this->assertFalse(RfOnlyGuard::isDefault(''));
    }

    public function testDefaultListsAreSane(): void
    {
        $this->assertContains('ipwho.is', RfOnlyGuard::IP_CHECK_DOMAINS);
        $this->assertContains('ipinfo.io', RfOnlyGuard::IP_CHECK_DOMAINS);
        $this->assertContains('mos.ru', RfOnlyGuard::RU_DOMAINS);
        // Заблокированные в РФ сайты и чужие CDN принудительно через РФ не пускаем.
        foreach (['rutracker.org', 'kinozal.tv', 'rutor.info', 'kino.pub', 'youtube.com', 'google.com'] as $d) {
            $this->assertNotContains($d, RfOnlyGuard::RU_DOMAINS);
        }
        foreach (RfOnlyGuard::RU_CIDRS as $c) {
            [$ip, $len] = explode('/', $c);
            $this->assertGreaterThanOrEqual(13, (int) $len, $c);
            foreach (['104.16.', '104.2', '172.6', '142.25', '172.217.', '157.240.', '52.', '3.', '13.'] as $foreign) {
                $this->assertStringStartsNotWith($foreign, $ip, "$c похоже на чужой CDN");
            }
            $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), $c);
        }
        // Все значения по умолчанию проходят собственный парсер без потерь.
        $this->assertSame(RfOnlyGuard::RU_CIDRS, RfOnlyGuard::parseCidrs(implode("\n", RfOnlyGuard::RU_CIDRS)));
        $this->assertSame(RfOnlyGuard::IP_CHECK_DOMAINS, RfOnlyGuard::parseDomains(implode("\n", RfOnlyGuard::IP_CHECK_DOMAINS)));
    }
}
