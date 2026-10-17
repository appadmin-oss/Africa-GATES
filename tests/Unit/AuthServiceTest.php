<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Services\{AuthService, LogService, AuditService};
use AfricaGates\Services\RateLimitService;

class AuthServiceTest extends TestCase
{
    private function service(): AuthService
    {
        return new AuthService(new LogService(), new AuditService(), new RateLimitService());
    }

    private function seedAdmin(string $email = 'a@x.io', string $password = 'secret'): void
    {
        DB::table('gates_admins')->insert([
            'email' => $email, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'name' => 'A', 'role' => 'superadmin', 'is_active' => 1, 'failed_attempts' => 0,
        ]);
    }

    public function test_correct_password_authenticates(): void
    {
        $this->seedAdmin();
        $this->assertNotNull($this->service()->attemptLogin('a@x.io', 'secret', '8.8.8.8'));
    }

    public function test_unknown_email_returns_null(): void
    {
        $this->assertNull($this->service()->attemptLogin('nobody@x.io', 'whatever', '8.8.8.8'));
    }

    public function test_ip_throttle_blocks_even_correct_credentials(): void
    {
        $this->seedAdmin();
        $svc = $this->service();

        // Burn the per-IP allowance (10/hr) with unknown-email attempts.
        for ($i = 0; $i < 10; $i++) {
            $svc->attemptLogin('nobody@x.io', 'x', '9.9.9.9');
        }
        // 11th from the same IP is blocked despite correct credentials...
        $this->assertNull($svc->attemptLogin('a@x.io', 'secret', '9.9.9.9'));
        // ...but a different IP with correct credentials still works.
        $this->assertNotNull($svc->attemptLogin('a@x.io', 'secret', '8.8.8.8'));
    }
    /**
     * A magic link is single-use, and the spend has to be the check. Two requests
     * presenting the same link together both read it unused; with a bare update-by-id
     * after the SELECT both stamped it and both signed in. Here the other request's stamp
     * lands immediately before ours — after our read — and ours must lose.
     */
    public function test_a_magic_link_spent_between_the_read_and_the_stamp_signs_nobody_in(): void
    {
        $this->seedAdmin();
        $svc = $this->service();
        [$raw] = $svc->createMagicLink('a@x.io');

        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if (!$armed) return;
            if (stripos(ltrim($sql), 'update') === 0 && str_contains($sql, 'gates_magic_links')) {
                $armed = false;
                DB::table('gates_magic_links')->update(['used_at' => date('Y-m-d H:i:s')]);
            }
        });
        try {
            $admin = $svc->consumeMagicLink($raw);
            $fired = !$armed;
        } finally {
            $armed = false;   // the callback outlives this test on the shared connection
        }

        $this->assertTrue($fired, 'the interleaving never happened, so this test proves nothing');
        $this->assertNull($admin, 'one magic link signed two requests in');
    }

    public function test_a_magic_link_still_signs_in_once(): void
    {
        $this->seedAdmin();
        $svc = $this->service();
        [$raw] = $svc->createMagicLink('a@x.io');
        $this->assertNotNull($svc->consumeMagicLink($raw));
        $this->assertNull($svc->consumeMagicLink($raw), 'a spent link worked again');
    }
}
