<?php

namespace Tests;

use App\Auth;
use App\Database;
use App\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function testRfc6238Vectors(): void
    {
        $secret = Totp::base32encode('12345678901234567890');
        $this->assertSame('287082', Totp::codeAt($secret, 59));
        $this->assertSame('081804', Totp::codeAt($secret, 1111111109));
        $this->assertSame('279037', Totp::codeAt($secret, 2000000000));
    }

    public function testVerifyWindowAndReject(): void
    {
        $secret = Totp::generateSecret();
        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret)));
        $this->assertFalse(Totp::verify($secret, '000000'));
        $this->assertFalse(Totp::verify($secret, 'abc'));
    }

    public function testBase32RoundTrip(): void
    {
        $data = random_bytes(20);
        $this->assertSame($data, Totp::base32decode(Totp::base32encode($data)));
    }

    public function testEnrollmentEnablesAndGates2fa(): void
    {
        $u = 'totp-user-' . uniqid();
        Database::get()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([$u, password_hash('correct-horse-battery', PASSWORD_DEFAULT)]);
        $id = (int) Database::get()->lastInsertId();
        $_SESSION = ['user_id' => $id];

        // Begin enrollment → secret in session.
        $enroll = Auth::beginTotpEnrollment($u);
        $this->assertArrayHasKey('secret', $enroll);
        $this->assertStringStartsWith('otpauth://totp/', $enroll['uri']);

        // Confirm with a valid code → 2FA enabled + recovery codes returned.
        $codes = Auth::confirmTotpEnrollment($id, Totp::codeAt($enroll['secret']));
        $this->assertNotEmpty($codes);
        $this->assertTrue(Auth::totpEnabled($id));

        // Login now requires 2FA: attempt() succeeds but does not establish session.
        $_SESSION = [];
        $this->assertTrue(Auth::attempt($u, 'correct-horse-battery'));
        $this->assertTrue(Auth::needs2fa());
        $this->assertFalse(Auth::check());

        // Wrong code fails, correct code completes login.
        $this->assertFalse(Auth::verify2fa('000000'));
        $this->assertTrue(Auth::verify2fa(Totp::codeAt($enroll['secret'])));
        $this->assertTrue(Auth::check());

        // A recovery code works once, then is consumed.
        $_SESSION = [];
        Auth::attempt($u, 'correct-horse-battery');
        $one = $codes[0];
        $this->assertTrue(Auth::verify2fa($one));
        $_SESSION = [];
        Auth::attempt($u, 'correct-horse-battery');
        $this->assertFalse(Auth::verify2fa($one), 'recovery code must be single-use');
    }
}
