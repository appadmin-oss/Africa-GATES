<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\PointsService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Voting-points economy: configurable earn/redeem rates, a transactional ledger
 * whose balance can never go negative, and idempotent purchase crediting.
 */
class PointsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_users')->insert(['id' => 1, 'name' => 'Ada Obi', 'email' => 'ada@x.io', 'points' => 0, 'status' => 'active', 'created_at' => '2026-06-26 00:00:00']);
        DB::table('gates_settings')->where('key_name', 'like', 'points_%')->delete();
    }

    private function set(string $k, string $v): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
    }

    public function test_rate_defaults_and_overrides(): void
    {
        $this->assertFalse(PointsService::enabled());
        $this->assertSame(50, PointsService::earnPer1000());
        $this->assertSame(500, PointsService::pointsPerVote());
        $this->set('points_per_1000_naira', '100');
        $this->set('points_per_vote', '250');
        $this->assertSame(100, PointsService::earnPer1000());
        $this->assertSame(250, PointsService::pointsPerVote());
    }

    public function test_points_for_spend_and_votes_for_points(): void
    {
        $this->assertSame(500, PointsService::pointsForSpend(10000)); // 10000/1000*50
        $this->assertSame(100, PointsService::pointsForSpend(2000));
        $this->assertSame(0, PointsService::pointsForSpend(0));
        $this->assertSame(2, PointsService::votesForPoints(1000)); // 1000/500
    }

    public function test_award_writes_ledger_and_balance(): void
    {
        $bal = PointsService::award(1, 500, 'earn.shop_order', 'shop_order', 'AFG-1', 'test');
        $this->assertSame(500, $bal);
        $this->assertSame(500, PointsService::balance(1));
        $rows = PointsService::ledger(1);
        $this->assertCount(1, $rows);
        $this->assertSame(500, (int) $rows[0]['delta']);
        $this->assertSame(500, (int) $rows[0]['balance_after']);
    }

    public function test_spend_rejects_overdraw(): void
    {
        PointsService::award(1, 500, 'earn.shop_order');
        $this->assertTrue(PointsService::spend(1, 200, 'spend.vote'));
        $this->assertSame(300, PointsService::balance(1));
        $this->assertFalse(PointsService::spend(1, 1000, 'spend.vote')); // would go negative
        $this->assertSame(300, PointsService::balance(1));               // unchanged
    }

    public function test_earn_from_purchase_is_gated_and_idempotent(): void
    {
        $this->assertSame(0, PointsService::earnFromPurchase(1, 10000, 'shop_order', 'REF1')); // disabled → 0
        $this->set('points_enabled', '1');
        $this->assertSame(500, PointsService::earnFromPurchase(1, 10000, 'shop_order', 'REF1'));
        $this->assertSame(0, PointsService::earnFromPurchase(1, 10000, 'shop_order', 'REF1'));  // same ref → no double credit
        $this->assertSame(500, PointsService::balance(1));
    }
}
