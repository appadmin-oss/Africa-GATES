<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{UserAccountService, PointsService};
use Illuminate\Database\Capsule\Manager as DB;

/** Account profile editing + the points lifetime summary shown on the dashboard. */
class AccountProfileTest extends TestCase
{
    public function test_update_profile_validates_and_saves(): void
    {
        DB::table('gates_users')->insert(['id' => 1, 'name' => 'Old Name', 'email' => 'a@x.io', 'phone' => '08010000000', 'points' => 0, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
        $svc = new UserAccountService();
        $this->assertFalse($svc->updateProfile(1, 'Single', '08011112222')['ok']);  // not a full name
        $this->assertFalse($svc->updateProfile(1, 'New Name', '123')['ok']);          // bad phone
        $this->assertTrue($svc->updateProfile(1, 'New Name', '08099998888')['ok']);
        $this->assertSame('New Name', (string) DB::table('gates_users')->where('id', 1)->value('name'));
        $this->assertSame('08099998888', (string) DB::table('gates_users')->where('id', 1)->value('phone'));
    }

    public function test_points_summary(): void
    {
        DB::table('gates_users')->insert(['id' => 1, 'name' => 'A B', 'email' => 'a@x.io', 'points' => 700, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
        DB::table('gates_points_ledger')->insert(['user_id' => 1, 'delta' => 1000, 'reason' => 'earn.shop_order', 'balance_after' => 1000, 'created_at' => '2026-01-01 00:00:00']);
        DB::table('gates_points_ledger')->insert(['user_id' => 1, 'delta' => -300, 'reason' => 'spend.vote', 'balance_after' => 700, 'created_at' => '2026-01-02 00:00:00']);
        $s = PointsService::summary(1);
        $this->assertSame(1000, $s['earned']);
        $this->assertSame(300, $s['spent']);
        $this->assertSame(700, $s['balance']);
    }
}
