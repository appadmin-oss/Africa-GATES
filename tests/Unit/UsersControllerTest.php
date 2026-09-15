<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Admin\Controllers\UsersController;
use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The admin points-adjustment lever: admin+ only, audited, ledger-backed, and
 * never allowed to drive a balance negative.
 */
class UsersControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'admin';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        DB::table('gates_users')->insert(['id' => 1, 'name' => 'Ada Obi', 'email' => 'ada@x.io', 'points' => 100, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function adjust(array $body): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/users/1/points')->withParsedBody($body);
        return (new UsersController(new AuditService()))->adjustPoints($req, new Response(), ['id' => 1]);
    }

    private function points(): int
    {
        return (int) DB::table('gates_users')->where('id', 1)->value('points');
    }

    public function test_admin_can_grant_points_and_it_is_ledgered(): void
    {
        $res = $this->adjust(['delta' => '500', 'note' => 'comp for a failed purchase credit']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(600, $this->points());
        $this->assertSame(1, (int) DB::table('gates_points_ledger')->where('user_id', 1)->where('reason', 'admin.adjust')->count());
        $this->assertNotEmpty($_SESSION['flash_ok'] ?? '');
    }

    public function test_admin_can_deduct_points(): void
    {
        $this->adjust(['delta' => '-40', 'note' => 'reversing an erroneous grant']);
        $this->assertSame(60, $this->points());
    }

    public function test_moderator_is_refused(): void
    {
        $_SESSION['admin_role'] = 'moderator';
        $this->adjust(['delta' => '500', 'note' => 'nope']);
        $this->assertSame(100, $this->points());                 // unchanged
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }

    public function test_deduction_below_zero_is_rejected(): void
    {
        $this->adjust(['delta' => '-500', 'note' => 'too much']);
        $this->assertSame(100, $this->points());                 // unchanged — award() returns null
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }

    public function test_reason_is_required(): void
    {
        $this->adjust(['delta' => '50', 'note' => '']);
        $this->assertSame(100, $this->points());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }

    public function test_zero_delta_is_rejected(): void
    {
        $this->adjust(['delta' => '0', 'note' => 'noop']);
        $this->assertSame(100, $this->points());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }
}
