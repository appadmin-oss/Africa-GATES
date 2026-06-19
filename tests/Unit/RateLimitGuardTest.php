<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\RateLimitService;

/**
 * Guards for the auth throttles introduced in Tasks B3 (magic-link) and B5
 * (login). Exercised at the RateLimitService level so the policy (counts /
 * windows) is pinned; the controllers/services wire these actions in.
 */
class RateLimitGuardTest extends TestCase
{
    public function test_magic_link_throttled_per_ip_after_five(): void
    {
        $rl = new RateLimitService();
        $ip = hash('sha256', '1.2.3.4');
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($rl->check($ip, 'admin_magic_ip', 5, 3600), "request #" . ($i + 1));
        }
        $this->assertFalse($rl->check($ip, 'admin_magic_ip', 5, 3600), '6th blocked');
    }

    public function test_magic_link_throttled_per_email_after_three(): void
    {
        $rl = new RateLimitService();
        $email = hash('sha256', 'admin@x.io');
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($rl->check($email, 'admin_magic_email', 3, 3600));
        }
        $this->assertFalse($rl->check($email, 'admin_magic_email', 3, 3600));
    }

    public function test_login_throttled_per_ip_after_ten(): void
    {
        $rl = new RateLimitService();
        $ip = hash('sha256', '9.9.9.9');
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($rl->check($ip, 'admin_login_ip', 10, 3600));
        }
        $this->assertFalse($rl->check($ip, 'admin_login_ip', 10, 3600));
    }
}
