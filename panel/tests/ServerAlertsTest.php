<?php

namespace Tests;

use App\Models\Setting;
use App\ServerAlerts;
use PHPUnit\Framework\TestCase;

class ServerAlertsTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::set('alert_email', '');
        Setting::set('alerts_offline', '1');
    }

    public function testRecipientPrefersSettingOverride(): void
    {
        Setting::set('alert_email', 'ops@example.com');
        $this->assertSame('ops@example.com', ServerAlerts::recipient());
    }

    public function testRecipientIgnoresInvalidSetting(): void
    {
        Setting::set('alert_email', 'not-an-email');
        // Должен НЕ вернуть мусор (либо null, либо валидный e-mail админа).
        $r = ServerAlerts::recipient();
        $this->assertTrue($r === null || filter_var($r, FILTER_VALIDATE_EMAIL) !== false);
        $this->assertNotSame('not-an-email', $r);
    }

    public function testDisabledIsNoop(): void
    {
        Setting::set('alerts_offline', '0');
        Setting::set('alert_email', 'ops@example.com');
        // Не должно бросать и не должно пытаться слать (выключено).
        ServerAlerts::onStatusChange(['id' => 1, 'name' => 'X', 'host' => '1.2.3.4'], 'online', 'offline', 'test');
        $this->assertFalse(ServerAlerts::enabled());
    }

    public function testSameStatusIsNoop(): void
    {
        Setting::set('alerts_offline', '1');
        // old == new → ранний выход, без обращения к почте.
        ServerAlerts::onStatusChange(['id' => 1, 'name' => 'X'], 'online', 'online');
        $this->assertTrue(true);
    }

    public function testNonEdgeTransitionIsNoop(): void
    {
        // offline → warning: оба «вниз», новой тревоги быть не должно (без получателя не падает).
        Setting::set('alerts_offline', '1');
        Setting::set('alert_email', '');
        ServerAlerts::onStatusChange(['id' => 1, 'name' => 'X'], 'offline', 'warning');
        $this->assertTrue(true);
    }
}
