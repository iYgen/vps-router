<?php

namespace Tests;

use App\Models\Setting;
use App\UpdateChecker;
use App\Version;
use PHPUnit\Framework\TestCase;

class VersionUpdateTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['update_check_url', 'update_repo_url', 'update_latest', 'update_error', 'update_checked_at'] as $k) {
            Setting::set($k, '');
        }
    }

    public function testNormalizeStripsV(): void
    {
        $this->assertSame('1.2.3', Version::normalize('v1.2.3'));
        $this->assertSame('1.2.3', Version::normalize(' V1.2.3 '));
        $this->assertSame('1.2.3', Version::normalize('1.2.3'));
    }

    public function testCompareNumericNotLexical(): void
    {
        $this->assertSame(1, Version::compare('1.10.0', '1.9.0'), '1.10 > 1.9 (не строковое сравнение)');
        $this->assertSame(-1, Version::compare('1.2.0', '1.2.1'));
        $this->assertSame(0, Version::compare('1.2.3', 'v1.2.3'));
        $this->assertSame(1, Version::compare('2.0', '1.9.9'));
    }

    public function testIsUpdateAvailable(): void
    {
        $this->assertTrue(Version::isUpdateAvailable('1.0.0', '1.0.1'));
        $this->assertTrue(Version::isUpdateAvailable('1.0.0', 'v1.1.0'));
        $this->assertFalse(Version::isUpdateAvailable('1.0.0', '1.0.0'));
        $this->assertFalse(Version::isUpdateAvailable('1.2.0', '1.1.0'), 'старее — не обновление');
        $this->assertFalse(Version::isUpdateAvailable('1.0.0', 'garbage'), 'мусор — не тревога');
        $this->assertFalse(Version::isUpdateAvailable('1.0.0', ''));
    }

    public function testParseRemotePlainText(): void
    {
        $this->assertSame('1.2.3', UpdateChecker::parseRemote("1.2.3\n"));
        $this->assertSame('1.2.3', UpdateChecker::parseRemote("v1.2.3"));
        $this->assertSame('2.0.0', UpdateChecker::parseRemote("2.0.0\nsome changelog line"));
        $this->assertNull(UpdateChecker::parseRemote("not a version"));
        $this->assertNull(UpdateChecker::parseRemote(''));
    }

    public function testParseRemoteJson(): void
    {
        $this->assertSame('3.4.5', UpdateChecker::parseRemote('{"version":"3.4.5"}'));
        $this->assertSame('3.4.5', UpdateChecker::parseRemote('{"tag_name":"v3.4.5","name":"rel"}'));
        $this->assertNull(UpdateChecker::parseRemote('{"foo":"bar"}'));
    }

    public function testCheckStoresLatestAndFlagsUpdate(): void
    {
        Setting::set('update_check_url', 'https://example.com/VERSION');
        // Инъекция загрузчика — сеть не трогаем.
        $s = UpdateChecker::check(fn() => "999.0.0\n");

        $this->assertSame('999.0.0', $s['latest']);
        $this->assertTrue($s['update_available']);
        $this->assertNull($s['error']);
        $this->assertNotNull($s['checked_at']);
        // Кэш переживает — status() без сети видит то же.
        $this->assertTrue(UpdateChecker::status()['update_available']);
    }

    public function testCheckHandlesFetchFailure(): void
    {
        Setting::set('update_check_url', 'https://example.com/VERSION');
        $s = UpdateChecker::check(fn() => null);
        $this->assertNotNull($s['error']);
        $this->assertFalse($s['update_available']);
    }

    public function testCheckRejectsNonHttpUrl(): void
    {
        Setting::set('update_check_url', 'ftp://example.com/x');
        $s = UpdateChecker::check(fn() => '1.0.0');
        $this->assertNotNull($s['error']);
    }

    public function testCheckNoUrl(): void
    {
        Setting::set('update_check_url', '');
        $s = UpdateChecker::check(fn() => '1.0.0');
        $this->assertFalse($s['configured']);
        $this->assertNotNull($s['error']);
    }
}
