<?php

use App\NetBoost;
use PHPUnit\Framework\TestCase;

class NetBoostTest extends TestCase
{
    public function testParsesStatusLine(): void
    {
        $s = NetBoost::parseStatus("[provision] Ускорение включено\nNETBOOST enabled=1 cc=bbr qdisc=fq kernel=6.1.0-18-amd64\n");
        $this->assertSame(['enabled' => true, 'cc' => 'bbr', 'qdisc' => 'fq', 'kernel' => '6.1.0-18-amd64', 'active' => true], $s);
        $off = NetBoost::parseStatus('NETBOOST enabled=0 cc=cubic qdisc=fq_codel kernel=5.4.0');
        $this->assertFalse($off['active']);
        $this->assertNull(NetBoost::parseStatus('ssh: connection refused'));
    }
}
