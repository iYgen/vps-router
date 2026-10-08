<?php

namespace Tests;

use App\RealityKeys;
use PHPUnit\Framework\TestCase;

class RealityKeysTest extends TestCase
{
    public function testGeneratedKeysAreBase64UrlWithoutPadding(): void
    {
        $kp = RealityKeys::generateKeypair();

        foreach (['private', 'public'] as $part) {
            $this->assertStringNotContainsString('+', $kp[$part]);
            $this->assertStringNotContainsString('/', $kp[$part]);
            $this->assertStringNotContainsString('=', $kp[$part]);
            $this->assertSame(43, strlen($kp[$part]), "$part key должен быть 43 символа (32 байта, base64url без padding)");
        }
        $this->assertNotSame($kp['private'], $kp['public']);
    }

    public function testNormalizeConvertsLegacyStandardBase64WithoutChangingBytes(): void
    {
        $raw = random_bytes(32);
        $legacy = base64_encode($raw); // старый формат: +/=

        $normalized = RealityKeys::normalizeBase64Url($legacy);

        $this->assertStringNotContainsString('+', $normalized);
        $this->assertStringNotContainsString('/', $normalized);
        $this->assertStringNotContainsString('=', $normalized);
        // Байты должны совпадать с оригиналом — это перекодировка, не новый ключ.
        $restored = base64_decode(strtr($normalized, '-_', '+/') . str_repeat('=', (4 - strlen($normalized) % 4) % 4));
        $this->assertSame($raw, $restored);
    }

    public function testNormalizeIsIdempotentOnAlreadyCorrectValue(): void
    {
        $kp = RealityKeys::generateKeypair();
        $this->assertSame($kp['public'], RealityKeys::normalizeBase64Url($kp['public']));
    }

    public function testShortIdIsSixteenHexChars(): void
    {
        $sid = RealityKeys::shortId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sid);
    }
}
