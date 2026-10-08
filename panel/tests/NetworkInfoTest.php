<?php

namespace Tests;

use App\NetworkInfo;
use PHPUnit\Framework\TestCase;

class NetworkInfoTest extends TestCase
{
    protected function setUp(): void
    {
        // Windows позволяет второму сокету забиндиться на тот же адрес:порт
        // без SO_EXCLUSIVEADDRUSE (проверено вручную) — эта логика значима
        // только на Linux, где реально крутится панель (подтверждено на
        // проде: "Address already in use" при повторном bind).
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Проверка EADDRINUSE значима только на Linux (целевая ОС панели) — Windows-сокеты допускают повторный bind.');
        }
    }

    public function testIsPortFreeDetectsOccupiedPort(): void
    {
        // Порт 0 — ОС сама выбирает свободный эфемерный порт, без риска
        // коллизии с чем-то уже занятым на машине, где гоняются тесты.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        [$ip, $port] = explode(':', stream_socket_get_name($server, false));

        $this->assertFalse(NetworkInfo::isPortFree($ip, (int) $port));
        fclose($server);
        $this->assertTrue(NetworkInfo::isPortFree($ip, (int) $port));
    }

    public function testFindFreePortSkipsOccupiedCandidate(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        [$ip, $port] = explode(':', stream_socket_get_name($server, false));
        $port = (int) $port;

        $free = NetworkInfo::findFreePort($ip, [$port, $port + 1]);
        fclose($server);

        $this->assertSame($port + 1, $free);
    }

    public function testFindFreePortReturnsNullWhenAllOccupied(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        [$ip, $port] = explode(':', stream_socket_get_name($server, false));
        $port = (int) $port;

        $this->assertNull(NetworkInfo::findFreePort($ip, [$port]));
        fclose($server);
    }
}
