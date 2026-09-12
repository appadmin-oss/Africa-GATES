<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Admin\Controllers\AdminsController;
use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Safety contract for admin account management:
 *  - an out-of-set stored role (e.g. legacy 'judge') is never silently escalated
 *    to superadmin by the auto-selected first <option>;
 *  - the last active superadmin cannot be demoted or disabled (lockout guard).
 */
class AdminRoleSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['flash_error'], $_SESSION['flash_ok']);
        parent::tearDown();
    }

    private function save(int $id, array $body): void
    {
        $_SESSION['admin_id'] = 1;
        $ctrl = new AdminsController(Twig::create(__DIR__ . '/../../templates'), new AuditService());
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/admins/' . $id)->withParsedBody($body);
        $ctrl->save($req, new Response(), ['id' => $id]);
    }

    private function role(int $id): string
    {
        return (string) DB::table('gates_admins')->where('id', $id)->value('role');
    }

    public function test_out_of_set_stored_role_is_never_escalated(): void
    {
        DB::table('gates_admins')->insert(['id' => 7, 'email' => 'j@x.io', 'name' => 'J', 'role' => 'judge', 'is_active' => 1, 'created_at' => '2026-01-01 00:00:00']);
        // Simulate the browser auto-submitting the first option ('superadmin').
        $this->save(7, ['name' => 'J', 'email' => 'j@x.io', 'role' => 'superadmin', 'is_active' => '1']);
        $this->assertSame('judge', $this->role(7)); // unchanged — not escalated
    }

    public function test_valid_role_change_is_applied(): void
    {
        DB::table('gates_admins')->insert(['id' => 8, 'email' => 'e@x.io', 'name' => 'E', 'role' => 'viewer', 'is_active' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->save(8, ['name' => 'E', 'email' => 'e@x.io', 'role' => 'editor', 'is_active' => '1']);
        $this->assertSame('editor', $this->role(8));
    }

    public function test_cannot_demote_last_superadmin(): void
    {
        DB::table('gates_admins')->insert(['id' => 1, 'email' => 's@x.io', 'name' => 'S', 'role' => 'superadmin', 'is_active' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->save(1, ['name' => 'S', 'email' => 's@x.io', 'role' => 'viewer', 'is_active' => '1']);
        $this->assertSame('superadmin', $this->role(1)); // demotion refused
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function test_can_demote_when_another_superadmin_exists(): void
    {
        DB::table('gates_admins')->insert(['id' => 1, 'email' => 's@x.io', 'name' => 'S', 'role' => 'superadmin', 'is_active' => 1, 'created_at' => '2026-01-01 00:00:00']);
        DB::table('gates_admins')->insert(['id' => 2, 'email' => 's2@x.io', 'name' => 'S2', 'role' => 'superadmin', 'is_active' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->save(1, ['name' => 'S', 'email' => 's@x.io', 'role' => 'admin', 'is_active' => '1']);
        $this->assertSame('admin', $this->role(1));
    }
}
