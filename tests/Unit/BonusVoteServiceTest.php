<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\BonusVoteService;

/**
 * Phase 2 — paid / bonus votes. A confirmed donation's bonus votes redeem into
 * ONE weighted gates_votes row that bumps vote_count by the weight, so the
 * existing cohort-normalised community CPI absorbs them with no formula change.
 */
class BonusVoteServiceTest extends TestCase
{
    private function seed(
        string $cycleStatus = 'voting',
        string $donationStatus = 'confirmed',
        int $bonus = 5,
        int $startVotes = 0,
    ): void {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'),
            'status' => $cycleStatus,
            'voting_close' => Carbon::now()->addDays(7)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'cat-10', 'title' => 'Category',
        ]);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 10, 'name' => 'Nominee', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => $startVotes,
        ]);
        DB::table('gates_donations')->insert([
            'id' => 1, 'donor_name' => 'Donor', 'donor_email' => 'd@x.io',
            'amount_naira' => 10000, 'bonus_votes' => $bonus, 'votes_used' => 0,
            'status' => $donationStatus,
        ]);
    }

    public function test_redeem_mints_weighted_vote_and_increments_by_weight(): void
    {
        $this->seed(startVotes: 10); // prove it ADDS the weight, not sets it

        $r = (new BonusVoteService())->redeem(1, 1, 3);

        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['weight']);

        $row = DB::table('gates_votes')->first();
        $this->assertSame('bonus', $row->vote_type);
        $this->assertSame(3, (int) $row->weight);
        $this->assertSame(1, (int) $row->donation_id);

        // vote_count 10 → 13 (increment by weight), donation 0 → 3 used.
        $this->assertSame(13, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
        $this->assertSame(3, (int) DB::table('gates_donations')->where('id', 1)->value('votes_used'));
    }

    public function test_cannot_redeem_more_than_remaining(): void
    {
        $this->seed(bonus: 2);

        $r = (new BonusVoteService())->redeem(1, 1, 3); // only 2 available

        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_votes')->count());
        $this->assertSame(0, (int) DB::table('gates_donations')->where('id', 1)->value('votes_used'));
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }

    public function test_unconfirmed_donation_rejected(): void
    {
        $this->seed(donationStatus: 'pending');

        $r = (new BonusVoteService())->redeem(1, 1, 1);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_votes')->count());
    }

    public function test_rejected_when_cycle_not_voting(): void
    {
        $this->seed(cycleStatus: 'judging'); // voting closed

        $r = (new BonusVoteService())->redeem(1, 1, 1);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_votes')->count());
        $this->assertSame(0, (int) DB::table('gates_donations')->where('id', 1)->value('votes_used'));
    }

    public function test_multiple_redemptions_accumulate(): void
    {
        // A donation may back several rows in one category — the synthetic voter
        // hash must NOT trip the per-human UNIQUE(email, category) constraint.
        $this->seed(bonus: 5);
        $svc = new BonusVoteService();

        $this->assertTrue($svc->redeem(1, 1, 2)['ok']);
        $this->assertTrue($svc->redeem(1, 1, 3)['ok']);

        $this->assertSame(2, DB::table('gates_votes')->count());
        $this->assertSame(5, $svc->bonusWeightFor(1));
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
        $this->assertSame(5, (int) DB::table('gates_donations')->where('id', 1)->value('votes_used'));
    }

    public function test_bonus_weight_is_capped_relative_to_organic_votes(): void
    {
        // 100 organic votes, default 50% cap → at most 50 bonus weight.
        $this->seed(bonus: 200, startVotes: 100);
        $svc = new BonusVoteService();

        $over = $svc->redeem(1, 1, 60);
        $this->assertFalse($over['ok']);
        $this->assertStringContainsString('capped', strtolower($over['message']));
        $this->assertSame(100, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));

        $ok = $svc->redeem(1, 1, 50); // exactly at the cap
        $this->assertTrue($ok['ok']);
        $this->assertSame(150, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }
}
