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
            'status' => 'approved', 'vote_count' => $startVotes, 'organic_vote_count' => $startVotes,
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
        // Organic stays put: it means one thing — a free vote from a code-verified
        // person — and the pages that print "N of those were contributed" read the
        // difference. It is a disclosure, not a second ranking figure.
        $this->assertSame(10, (int) DB::table('gates_nominees')->where('id', 1)->value('organic_vote_count'));
    }

    /**
     * THE CEILING FOLLOWS SUPPORT THAT EXISTS, AND CANNOT RAISE ITSELF.
     *
     * ── WHAT THIS TEST USED TO ASSERT ────────────────────────────────────────
     *
     * "The cap must follow ORGANIC (20 → 50% = 10), NOT the inflated vote_count."
     * With 20 organic against a 200 tally it required `redeem(50)` to be refused.
     *
     * ── WHY IT CHANGED ───────────────────────────────────────────────────────
     *
     * `organic_vote_count` can only be written by `VoteService::castVote()`, which
     * answers 403 wherever `paid_voting_disable_free` is set. On such a deployment the
     * column is permanently zero, so this ceiling collapsed to its floor and every
     * nominee was capped at TEN granted votes forever, whatever anybody contributed —
     * refused with "capped at 10 (50% of organic support)" to an operator whose site
     * cannot have organic support. It was also guarding a figure the index had stopped
     * reading: the community half normalises over the full tally now.
     *
     * ── AND THE PROPERTY THAT SURVIVED, WHICH IS THE REAL ONE ────────────────
     *
     * The fear behind the old assertion was a ceiling that inflates itself, and that
     * fear is correct — bonus weight increments `vote_count`, so a cap read straight off
     * the tally would rise with every grant it allowed and stop being a cap. The base is
     * the tally MINUS what has already been granted, and the second half of this test
     * walks the nominee to the ceiling and proves it does not move.
     */
    public function test_the_ceiling_follows_real_support_and_never_raises_itself(): void
    {
        // 200 votes cast or bought, none of them granted. organic is 0 — as it is on
        // every paid-only deployment, and as it was NOT in this fixture before.
        $this->seed(bonus: 200);
        DB::table('gates_nominees')->where('id', 1)->update([
            'vote_count' => 200, 'organic_vote_count' => 0,
        ]);
        $svc = new BonusVoteService();

        // base 200, 50% → 100.
        $this->assertFalse($svc->redeem(1, 1, 150)['ok'], '150 is over a ceiling of 100');
        $this->assertSame(0, DB::table('gates_votes')->count());

        $this->assertTrue($svc->redeem(1, 1, 100)['ok'],
            'a nominee with 200 votes was refused 100 grants — the ceiling is reading '
            . 'organic_vote_count again, which is zero here by design');

        // THE RATCHET. The tally is 300 now, 100 of it granted, so the base is still 200
        // and the ceiling is still 100 — already spent. Read straight off the tally it
        // would be 150, and the next grant would raise it again.
        $this->assertSame(300, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
        $this->assertFalse($svc->redeem(1, 1, 1)['ok'],
            'the ceiling rose because the grants it permitted counted toward it');
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
