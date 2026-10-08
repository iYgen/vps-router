<?php

namespace Tests;

use App\Diagnostics;
use PHPUnit\Framework\TestCase;

class DiagnosticsPortProbeTest extends TestCase
{
    public function testParallelProbeSeesOpenAndClosedPorts(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $open = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        // Свободный порт: заняли и сразу отпустили — на нём никто не слушает.
        $tmp = stream_socket_server('tcp://127.0.0.1:0');
        $closed = (int) substr(strrchr(stream_socket_get_name($tmp, false), ':'), 1);
        fclose($tmp);

        $result = Diagnostics::probePortsParallel('127.0.0.1', [$open, $closed], 2);
        fclose($server);

        $this->assertSame([$open, $closed], array_keys($result));
        $this->assertTrue($result[$open]['open']);
        $this->assertFalse($result[$closed]['open']);
    }

    public function testProbeSshReportsMissingBanner(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        $probe = Diagnostics::probeSsh('127.0.0.1', $port, 1); // принимает TCP, но молчит
        fclose($server);

        $this->assertTrue($probe['tcp']);
        $this->assertNull($probe['banner']);
    }
}
