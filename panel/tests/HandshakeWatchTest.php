<?php

namespace Tests;

use App\Database;
use App\HandshakeWatch;
use App\Models\Setting;
use PHPUnit\Framework\TestCase;

class HandshakeWatchTest extends TestCase
{
    private const CFG = ['min_failed' => 15, 'min_ratio' => 0.15];

    protected function setUp(): void
    {
        $db = Database::get();
        $db->exec('DELETE FROM hs_minutes');
        $db->exec('DELETE FROM hs_waves');
        Setting::set('hs_capture', '0');
        Setting::set('hs_alert_email', '0');
        Setting::set('hs_min_failed', '15');
        Setting::set('hs_min_ratio', '0.15');
        Setting::set('hs_quiet_minutes', '5');
    }

    private function line(string $ts, string $msg): string
    {
        return "{$ts}+03:00 node1 sing-box[831583]: +0300 2026-11-10 19:18:01 {$msg}";
    }

    public function testParseCountsAttemptsFailuresAndClients(): void
    {
        $lines = [
            $this->line('2026-11-10T19:18:01', 'INFO [111 0ms] inbound/vless[reality-in]: inbound connection from 198.51.100.23:33177'),
            $this->line('2026-11-10T19:18:01', 'INFO [111 0ms] inbound/vless[reality-in]: [c15] inbound connection to www.youtube.com:443'),
            $this->line('2026-11-10T19:18:02', 'INFO [222 0ms] inbound/vless[reality-in]: inbound connection from 198.51.100.23:33178'),
            $this->line('2026-11-10T19:18:59', 'ERROR [222 1m0s] inbound/vless[reality-in]: process connection from 198.51.100.23:33178: TLS handshake: REALITY: processed invalid connection'),
            // обычное закрытие — не неудача
            $this->line('2026-11-10T19:19:00', 'ERROR [111 1m0s] inbound/vless[reality-in]: process connection from 198.51.100.23:33177: EOF'),
            $this->line('2026-11-10T19:19:01', 'INFO [333 0ms] inbound/vless[reality-in]: inbound connection from [2a00:1450::1]:4000'),
            'garbage line',
        ];
        $r = HandshakeWatch::parseLines($lines);
        // 19:18 MSK = 16:18 UTC
        $this->assertSame(['total' => 2, 'failed' => 1], $r['minutes']['2026-11-10 16:18']['198.51.100.23']);
        $this->assertSame(0, $r['minutes']['2026-11-10 16:19']['198.51.100.23']['failed'] ?? 0);
        $this->assertSame(1, $r['minutes']['2026-11-10 16:19']['2a00:1450::1']['total']);
        $this->assertSame(['c15' => 1], $r['clients']['198.51.100.23']);
    }

    public function testClientMappingSurvivesBatches(): void
    {
        $a = HandshakeWatch::parseLines([
            $this->line('2026-11-10T19:18:01', 'INFO [9 0ms] inbound/vless[reality-in]: inbound connection from 10.1.2.3:1'),
        ]);
        $b = HandshakeWatch::parseLines([
            $this->line('2026-11-10T19:18:02', 'INFO [9 0ms] inbound/vless[reality-in]: [c9] inbound connection to a.b:443'),
        ], $a['idIp']);
        $this->assertSame(['c9' => 1], $b['clients']['10.1.2.3']);
    }

    public function testIsBadAndStreak(): void
    {
        $this->assertTrue(HandshakeWatch::isBad(100, 20, self::CFG));
        $this->assertFalse(HandshakeWatch::isBad(1000, 20, self::CFG), 'low share is not bad');
        $this->assertFalse(HandshakeWatch::isBad(10, 10, self::CFG), 'too few failures');
        $this->assertSame('2026-11-10 16:19', HandshakeWatch::streakStart(['2026-11-10 16:10', '2026-11-10 16:19', '2026-11-10 16:20'], 2));
        $this->assertNull(HandshakeWatch::streakStart(['2026-11-10 16:10', '2026-11-10 16:12'], 2));
    }

    public function testWaveOpensOnStreakAndClosesAfterQuiet(): void
    {
        $ip = '10.9.9.9';
        $t0 = strtotime('2026-11-10 16:00:00 UTC');
        $m = fn(int $min) => gmdate('Y-m-d H:i', $t0 + $min * 60);

        HandshakeWatch::storeMinutes([$m(0) => [$ip => ['total' => 100, 'failed' => 40]]]);
        $this->assertSame(0, HandshakeWatch::detect($t0 + 30)['opened'], 'one bad minute is not a wave');

        HandshakeWatch::storeMinutes([$m(1) => [$ip => ['total' => 80, 'failed' => 50]]]);
        $r = HandshakeWatch::detect($t0 + 90, [$ip => ['c15' => 3]]);
        $this->assertSame(1, $r['opened']);

        $w = HandshakeWatch::waves()[0];
        $this->assertSame($m(0), $w['started_at']);
        $this->assertNull($w['ended_at']);
        $this->assertSame(90, (int) $w['failed_total']);
        $this->assertSame(50, (int) $w['peak_per_min']);
        $this->assertSame('private', $w['isp']);
        $this->assertSame('c15', $w['clients'][0]['tag']);
        $this->assertSame('skipped', $w['capture_status']);

        // ещё не тихо достаточно долго
        $this->assertSame(0, HandshakeWatch::detect($t0 + 4 * 60)['closed']);
        // 6+ тихих минут после последней плохой (16:01) → закрыта
        $this->assertSame(1, HandshakeWatch::detect($t0 + 8 * 60)['closed']);
        $this->assertSame($m(2), HandshakeWatch::waves()[0]['ended_at']);

        // те же старые минуты не открывают волну заново
        $this->assertSame(0, HandshakeWatch::detect($t0 + 9 * 60)['opened']);
        $this->assertSame(1, HandshakeWatch::wavesCount());
    }

    public function testAnalyzeCaptureDetectsCutHello(): void
    {
        $c = '198.51.100.23';
        $s = '203.0.113.10';
        $txt = implode("\n", [
            // поток 1: полный хендшейк
            "1700000000.000 eth0 In  IP {$c}.1000 > {$s}.2222: Flags [S], seq 1, win 65535, length 0",
            "1700000000.010 eth0 In  IP {$c}.1000 > {$s}.2222: Flags [P.], seq 1:518, ack 1, length 517",
            "1700000000.040 eth0 Out IP {$s}.2222 > {$c}.1000: Flags [P.], seq 1:1201, ack 518, length 1200",
            // поток 2: первый сегмент ClientHello полный, продолжение не дошло, ответа нет
            "1700000001.000 eth0 In  IP {$c}.1001 > {$s}.2222: Flags [S], seq 1, win 65535, length 0",
            "1700000001.010 eth0 In  IP {$c}.1001 > {$s}.2222: Flags [.], seq 1:1449, ack 1, length 1448",
            "1700000061.000 eth0 Out IP {$s}.2222 > {$c}.1001: Flags [R.], seq 1, ack 1449, length 0",
            // поток 3: такой же
            "1700000002.000 eth0 In  IP {$c}.1002 > {$s}.2222: Flags [S], seq 1, win 65535, length 0",
            "1700000002.010 eth0 In  IP {$c}.1002 > {$s}.2222: Flags [.], seq 1:1449, ack 1, length 1448",
        ]);
        $r = HandshakeWatch::analyzeCapture($txt, $c);
        $this->assertSame(3, $r['flows']);
        $this->assertSame(1, $r['answered']);
        $this->assertSame(2, $r['unanswered']);
        $this->assertSame(2, $r['hello_split_lost']);
        $this->assertSame(['1448' => 2, '517' => 1], $r['first_seg']);
        $this->assertSame(40, $r['answer_ms_median']); // от SYN до первого байта сервера
        $this->assertSame('hello_cut', $r['verdict']);
        $this->assertSame(1, $r['ends']['server_rst']);
    }

    public function testAnalyzeCaptureHealthy(): void
    {
        $c = '10.0.0.5';
        $txt = "1.0 IP {$c}.5 > 10.0.0.1.2222: Flags [S], seq 1, length 0\n"
            . "1.01 IP {$c}.5 > 10.0.0.1.2222: Flags [P.], seq 1:518, length 517\n"
            . "1.05 IP 10.0.0.1.2222 > {$c}.5: Flags [P.], seq 1:100, length 99\n";
        $this->assertSame('looks_ok', HandshakeWatch::analyzeCapture($txt, $c)['verdict']);
        $this->assertSame('no_traffic', HandshakeWatch::analyzeCapture('', $c)['verdict']);
    }

    public function testParseWhois(): void
    {
        [$isp, $cc] = HandshakeWatch::parseWhois("NetName:        EXAMPLE-NET-BLOCK\nnetname:        EXAMPLE-BROADBAND\ndescr:          Dynamic IP Pool for Broadband Customers\ncountry:        NL\n");
        $this->assertSame('EXAMPLE-NET-BLOCK — Dynamic IP Pool for Broadband Customers', $isp);
        $this->assertSame('NL', $cc);
        [$isp2] = HandshakeWatch::parseWhois("org-name:       Example Telecom LLC\nnetname: EXAMPLE-TEL\n");
        $this->assertSame('Example Telecom LLC', $isp2);
    }

    public function testCapturePathRejectsTraversal(): void
    {
        $this->assertNull(HandshakeWatch::capturePath('../../etc/passwd'));
        $this->assertNull(HandshakeWatch::capturePath('wave-1.pcap/../x'));
        $this->assertStringEndsWith('/captures/wave-12.pcap', (string) HandshakeWatch::capturePath('wave-12.pcap'));
    }
}
