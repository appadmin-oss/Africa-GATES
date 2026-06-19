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
}
