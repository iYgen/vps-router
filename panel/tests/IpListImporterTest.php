<?php

namespace Tests;

use App\IpListImporter;
use PHPUnit\Framework\TestCase;

class IpListImporterTest extends TestCase
{
    public function testParseBatBasicLines(): void
    {
        $content = "route add 104.16.0.0 mask 255.255.0.0 0.0.0.0\r\n"
            . "route add 5.200.14.128 mask 255.255.255.128 0.0.0.0\n"
            . "\n"
            . "REM какой-то комментарий, не строка route\n"
            . "route add 162.158.0.0 mask 255.254.0.0 0.0.0.0";

        $cidrs = IpListImporter::parseBat($content);

        $this->assertSame([
            '104.16.0.0/16',
            '5.200.14.128/25',
            '162.158.0.0/15',
        ], $cidrs);
    }

    public function testParseBatIsCaseInsensitiveAndDedupes(): void
    {
        $content = "ROUTE ADD 8.8.8.0 MASK 255.255.255.0 0.0.0.0\n"
            . "route add 8.8.8.0 mask 255.255.255.0 0.0.0.0\n";

        $this->assertSame(['8.8.8.0/24'], IpListImporter::parseBat($content));
    }

    public function testParseBatIgnoresGarbageLines(): void
    {
        $content = "not a route line\nroute add not.an.ip mask 255.255.255.0 0.0.0.0\n";
        $this->assertSame([], IpListImporter::parseBat($content));
    }

    public function testParseBatEmptyContent(): void
    {
        $this->assertSame([], IpListImporter::parseBat(''));
    }

    public function testEncodePathEscapesSpacesPerSegment(): void
    {
        $method = new \ReflectionMethod(IpListImporter::class, 'encodePath');
        $method->setAccessible(true);

        $this->assertSame(
            'Global/Clash%20Royale/BrawlStars_Clash%20Royale.bat',
            $method->invoke(null, 'Global/Clash Royale/BrawlStars_Clash Royale.bat')
        );
    }

    /**
     * Живой прогон на проде нашёл: папка с несколькими .bat-файлами
     * (Discord.bat + Discord_Old.bat + discordgg.bat) даёт одинаковое имя
     * "Discord" для всех — второй и третий файл должны получить
     * уточнённое имя, а не упасть на UNIQUE.
     */
    public function testResolveGroupNameDisambiguatesOnCollision(): void
    {
        $method = new \ReflectionMethod(IpListImporter::class, 'resolveGroupName');
        $method->setAccessible(true);

        $suffix = uniqid();
        \App\Models\RuleGroup::create("Discord-$suffix", null);

        $name = $method->invoke(null, "Global/Discord-$suffix/Discord_Old.bat", null);
        $this->assertSame("Discord-$suffix — Discord_Old", $name);
    }

    public function testResolveGroupNameUsesFolderNameWhenFree(): void
    {
        $method = new \ReflectionMethod(IpListImporter::class, 'resolveGroupName');
        $method->setAccessible(true);

        $folder = 'FreshFolder-' . uniqid();
        $name = $method->invoke(null, "Global/$folder/whatever.bat", null);
        $this->assertSame($folder, $name);
    }
}
