<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\UserAccountService;

/**
 * Public account registration + password sign-in: validation, unique email,
 * and constant-time-ish password verification.
 */
class UserAccountServiceTest extends TestCase
{
    private function svc(): UserAccountService { return new UserAccountService(); }

    public function test_registration_validation(): void
    {
        $s = $this->svc();
        $this->assertFalse($s->register('', 'a@b.co', '08012345678', 'password1')['ok']);          // no name
        $this->assertFalse($s->register('Ada', 'a@b.co', '08012345678', 'password1')['ok']);        // single word
        $this->assertFalse($s->register('Ada Obi', 'bad-email', '08012345678', null)['ok']);        // bad email
        $this->assertFalse($s->register('Ada Obi', 'a@b.co', '12', null)['ok']);                    // short phone
        $this->assertFalse($s->register('Ada Obi', 'a@b.co', '08012345678', 'short')['ok']);        // weak password
    }

    public function test_register_then_login_and_duplicate(): void
    {
        $s = $this->svc();
        $r = $s->register('Ada Obi', 'ada@x.io', '0801 234 5678', 'password1');
        $this->assertTrue($r['ok']);
        $this->assertGreaterThan(0, $r['id']);

        // Duplicate email is rejected.
        $this->assertFalse($s->register('Ada Two', 'ada@x.io', '08099999999', 'password2')['ok']);

        // Password sign-in.
        $this->assertNotNull($s->attemptLogin('ada@x.io', 'password1'));
        $this->assertNull($s->attemptLogin('ada@x.io', 'wrongpass'));
        $this->assertNull($s->attemptLogin('nobody@x.io', 'password1'));
    }

    public function test_passwordless_account_cannot_password_login(): void
    {
        $s = $this->svc();
        $s->register('OTP Only', 'otp@x.io', '08012345678', null); // no password (OTP-only)
        $this->assertNull($s->attemptLogin('otp@x.io', 'anything'));
    }
}
