<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\PointsService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Points → votes redemption. Spending points must mint a CPI-EXCLUDED bonus vote
 * (bumps vote_count, NOT organic_vote_count) only when the cycle is voting and the
 * member has the balance; otherwise nothing is spent and no vote is minted.
 */
class PointsRedeemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_users')->insert(['id' => 1, 'name' => 'Ada Obi', 'email' => 'ada@x.io', 'points' => 1000, 'status' => 'active', 'created_at' => '2026-06-26 00:00:00']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'title' => 'Test Category', 'slug' => 'test-category', 'sort_order' => 1]);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Test Nominee', 'status' => 'approved', 'vote_count' => 20, 'organic_vote_count' => 20]);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'points_enabled'], ['value' => '1']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'points_per_vote'], ['value' => '500']);
    }

    private function voteCount(): int { return (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'); }
    private function bonusVotes(): int { return (int) DB::table('gates_votes')->where('nominee_id', 1)->where('vote_type', 'bonus')->count(); }

    public function test_disabled_blocks_redemption(): void
    {
        DB::table('gates_settings')->where('key_name', 'points_enabled')->delete();
        $r = PointsService::redeemForVote(1, 1);
        $this->assertFalse($r['ok']);
        $this->assertSame(1000, PointsService::balance(1));
        $this->assertSame(20, $this->voteCount());
    }

    public function test_successful_redemption_mints_cpi_excluded_vote(): void
    {
        $r = PointsService::redeemForVote(1, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(500, $r['new_balance']);
        $this->assertSame(500, PointsService::balance(1));        // 1000 - 500
        $this->assertSame(21, $this->voteCount());                 // public tally +1
        $this->assertSame(20, (int) DB::table('gates_nominees')->where('id', 1)->value('organic_vote_count')); // CPI base untouched
        $this->assertSame(1, $this->bonusVotes());
        // ledger spend recorded
        $led = PointsService::ledger(1);
        $this->assertSame(-500, (int) $led[0]['delta']);
        $this->assertSame('spend.vote', $led[0]['reason']);
    }

    public function test_insufficient_points_rejected(): void
    {
        DB::table('gates_users')->where('id', 1)->update(['points' => 100]);
        $r = PointsService::redeemForVote(1, 1);
        $this->assertFalse($r['ok']);
        $this->assertSame(100, PointsService::balance(1)); // unchanged
        $this->assertSame(20, $this->voteCount());
        $this->assertSame(0, $this->bonusVotes());
    }

    public function test_voting_closed_rejected(): void
    {
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'judging']);
        $r = PointsService::redeemForVote(1, 1);
        $this->assertFalse($r['ok']);
        $this->assertSame(1000, PointsService::balance(1));
        $this->assertSame(20, $this->voteCount());
    }
}
