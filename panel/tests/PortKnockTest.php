<?php

namespace Tests;

use App\Models\Server;
use App\Provisioner;
use App\Ssh;
use PHPUnit\Framework\TestCase;

class PortKnockTest extends TestCase
{
    public function testSetKnockConfigPersistsAndIsReturnedByFind(): void
    {
        $id = Server::create(['name' => 'Knock-' . uniqid(), 'role' => 'exit']);
        Server::setKnockConfig($id, true, 22, [12345, 23456, 34567]);

        $server = Server::find($id);
        $this->assertSame(1, (int) $server['knock_enabled']);
        $this->assertSame(22, (int) $server['knock_protected_port']);
        $this->assertSame([12345, 23456, 34567], json_decode($server['knock_ports'], true));
    }

    public function testKnockIfConfiguredIsNoopWhenDisabled(): void
    {
        // knock_enabled=0 — не должно даже пытаться стучать (быстро завершается,
        // а не висит на реальном сетевом вызове).
        $start = microtime(true);
        Provisioner::knockIfConfigured([
            'knock_enabled' => 0, 'host' => '127.0.0.1', 'knock_ports' => json_encode([1, 2, 3]),
        ]);
        $this->assertLessThan(0.2, microtime(true) - $start);
    }

    public function testKnockIfConfiguredIsNoopWhenNoPortsStored(): void
    {
        $start = microtime(true);
        Provisioner::knockIfConfigured([
            'knock_enabled' => 1, 'host' => '127.0.0.1', 'knock_ports' => null,
        ]);
        $this->assertLessThan(0.2, microtime(true) - $start);
    }

    public function testKnockIfConfiguredIsNoopWhenNoHost(): void
    {
        $start = microtime(true);
        Provisioner::knockIfConfigured([
            'knock_enabled' => 1, 'host' => '', 'knock_ports' => json_encode([1, 2, 3]),
        ]);
        $this->assertLessThan(0.2, microtime(true) - $start);
    }

    public function testSshKnockAttemptsEachPortInOrderWithoutThrowing(): void
    {
        // Порты гарантированно закрыты (bind затем сразу close) — реальный
        // TCP SYN уходит на каждый, соединение отказывается быстро, без
        // ожидания таймаута, так что тест остаётся быстрым.
        $ports = [];
        foreach (range(1, 3) as $i) {
            $server = stream_socket_server('tcp://127.0.0.1:0');
            [, $port] = explode(':', stream_socket_get_name($server, false));
            fclose($server);
            $ports[] = (int) $port;
        }

        Ssh::knock('127.0.0.1', $ports, 0.05, 1);
        $this->addToAssertionCount(1); // дошли сюда — knock() не бросил исключение
    }
}
