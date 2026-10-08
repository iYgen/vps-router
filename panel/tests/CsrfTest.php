<?php

namespace Tests;

use App\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Auth::requireValidCsrf()/requireValidCsrfJson() вызывают die()/exit()
 * при неудаче — намеренно не вызываем их напрямую в юнит-тестах (убило бы
 * процесс PHPUnit). Проверяем то, что действительно можно проверить без
 * завершения процесса: генерацию и сравнение токена.
 */
class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SESSION['csrf_token']);
    }

    public function testTokenIsStableWithinSession(): void
    {
        $first = Auth::csrfToken();
        $second = Auth::csrfToken();
        $this->assertSame($first, $second);
    }

    public function testTokenChangesAfterSessionReset(): void
    {
        $first = Auth::csrfToken();
        unset($_SESSION['csrf_token']);
        $second = Auth::csrfToken();
        $this->assertNotSame($first, $second);
    }

    public function testCsrfFieldEscapesAndEmbedsToken(): void
    {
        $token = Auth::csrfToken();
        $field = Auth::csrfField();
        $this->assertStringContainsString('name="csrf_token"', $field);
        $this->assertStringContainsString(htmlspecialchars($token, ENT_QUOTES), $field);
    }

    public function testCorrectTokenPassesHashEqualsCheck(): void
    {
        $token = Auth::csrfToken();
        $this->assertTrue(hash_equals($_SESSION['csrf_token'], $token));
    }

    public function testWrongTokenFailsHashEqualsCheck(): void
    {
        Auth::csrfToken();
        $this->assertFalse(hash_equals($_SESSION['csrf_token'], 'definitely-wrong-token'));
    }
}
