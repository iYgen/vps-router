<?php

namespace Tests;

use App\Installer;
use App\InstallRunner;
use PHPUnit\Framework\TestCase;

class InstallWizardTest extends TestCase
{
    public function testRunnerWhitelistOnly(): void
    {
        $this->assertTrue(InstallRunner::isAllowed('preflight'));
        $this->assertTrue(InstallRunner::isAllowed('share443'));
        $this->assertTrue(InstallRunner::isAllowed('components'));
        $this->assertTrue(InstallRunner::isAllowed('cert'));
        $this->assertFalse(InstallRunner::isAllowed('rm'));
        $this->assertFalse(InstallRunner::isAllowed('preflight; rm -rf /'));
        $this->assertFalse(InstallRunner::isAllowed(''));
    }

    public function testComponentsConfWritesOnlyAmneziaFlag(): void
    {
        $path = Installer::componentsConfPath();
        @unlink($path);

        Installer::writeComponentsConf(true);
        $this->assertSame("AMNEZIA=1\n", (string) file_get_contents($path));

        Installer::writeComponentsConf(false);
        $this->assertSame("AMNEZIA=0\n", (string) file_get_contents($path), 'перезапись, а не дозапись');
        @unlink($path);
    }

    public function testCertConfValidDomain(): void
    {
        $path = Installer::certConfPath();
        @unlink($path);

        Installer::writeCertConf('panel.example.com', 'admin@example.com');
        $out = (string) file_get_contents($path);
        $this->assertStringContainsString('DOMAIN=panel.example.com', $out);
        $this->assertStringContainsString('EMAIL=admin@example.com', $out);

        // Без e-mail — строки EMAIL нет.
        Installer::writeCertConf('vpn.example.org');
        $out2 = (string) file_get_contents($path);
        $this->assertStringContainsString('DOMAIN=vpn.example.org', $out2);
        $this->assertStringNotContainsString('EMAIL=', $out2);
        @unlink($path);
    }

    public function testCertConfRejectsBadInput(): void
    {
        foreach (['', 'no-dot', 'bad domain.com', "a.com\nBAD=1", 'space .com', '-lead.com'] as $bad) {
            try {
                Installer::writeCertConf($bad);
                $this->fail("должен отклонить домен: '$bad'");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        // Плохой e-mail при валидном домене тоже отклоняется.
        $this->expectException(\InvalidArgumentException::class);
        Installer::writeCertConf('ok.example.com', 'not-an-email');
    }

    public function testInstalledMarkerClosesWizardEvenWithoutUsers(): void
    {
        $marker = Installer::installedMarkerPath();
        @unlink($marker);
        // Без маркера и (в тестовой БД) без админов мастер считается открытым.
        $wasInstalledBefore = Installer::isInstalled();

        file_put_contents($marker, "2026-01-01T00:00:00+00:00\n");
        $this->assertTrue(Installer::isInstalled(), 'маркер должен закрывать мастер');
        // С закрытым мастером доступ запрещён даже по обратной совместимости (без токена).
        $this->assertFalse(Installer::accessAllowed(null));
        $this->assertFalse(Installer::accessAllowed('any-token'));

        @unlink($marker);
        $this->assertSame($wasInstalledBefore, Installer::isInstalled(), 'после снятия маркера состояние прежнее');
    }

    public function testRunnerStartRejectsUnknownActionBeforeAnyExec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InstallRunner::start('definitely-not-whitelisted');
    }

    public function testRunnerLogPathIsScopedToAction(): void
    {
        $this->assertStringEndsWith('preflight.log', InstallRunner::logFile('preflight'));
    }

    public function testInstallTokenReadWrite(): void
    {
        $f = Installer::tokenFile();
        @unlink($f);
        $this->assertNull(Installer::installToken(), 'без файла токена — null');

        file_put_contents($f, "secret-token-123\n");
        $this->assertSame('secret-token-123', Installer::installToken(), 'токен читается и триммится');

        // Верный токен пускает ровно тогда, когда панель ещё не установлена.
        $this->assertSame(!Installer::isInstalled(), Installer::accessAllowed('secret-token-123'));
        // Неверный токен не пускает никогда.
        $this->assertFalse(Installer::accessAllowed('wrong'));
        $this->assertFalse(Installer::accessAllowed(null));

        @unlink($f);
        $this->assertNull(Installer::installToken());
    }
}
