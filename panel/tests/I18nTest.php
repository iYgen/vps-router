<?php

namespace Tests;

use App\I18n;
use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase
{
    public function testRuAndEnHaveIdenticalKeys(): void
    {
        $ru = require __DIR__ . '/../lang/ru.php';
        $en = require __DIR__ . '/../lang/en.php';
        $missingInEn = array_diff_key($ru, $en);
        $missingInRu = array_diff_key($en, $ru);
        $this->assertSame([], array_keys($missingInEn), 'нет перевода в en для: ' . implode(', ', array_keys($missingInEn)));
        $this->assertSame([], array_keys($missingInRu), 'нет перевода в ru для: ' . implode(', ', array_keys($missingInRu)));
    }

    public function testNoEmptyValues(): void
    {
        foreach (['ru', 'en'] as $l) {
            foreach (require __DIR__ . "/../lang/$l.php" as $k => $v) {
                $this->assertNotSame('', trim((string) $v), "$l: пустое значение у $k");
            }
        }
    }

    public function testTranslationAndFallback(): void
    {
        I18n::setLang('en');
        $this->assertSame('Settings', I18n::t('settings.title'));
        I18n::setLang('ru');
        $this->assertSame('Настройки', I18n::t('settings.title'));
        // Неизвестный ключ возвращается как есть.
        $this->assertSame('nope.key', I18n::t('nope.key'));
        // sprintf-подстановка.
        $this->assertStringContainsString('5', I18n::t('devices.this_server', 5));
    }

    public function testAvailableLanguages(): void
    {
        $av = I18n::available();
        $this->assertArrayHasKey('ru', $av);
        $this->assertArrayHasKey('en', $av);
    }
}
