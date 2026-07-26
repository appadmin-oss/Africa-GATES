<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Paid voting: off by default, server-side pricing (cheaper of per-vote vs
 * ₦1,000 bundle), idempotent minting on confirmed orders, and — critically —
 * paid votes bump the PUBLIC tally only, never the organic CPI signal.
 */
final class PaidVoteServiceTest extends TestCase
{
    private function settings(array $kv): void
    {
        foreach ($kv as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
    }

    private function order(array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId(array_merge([
            'donor_name' => 'A Supporter', 'donor_email' => 's@x.io',
            'amount_naira' => 1000, 'tier' => 'paid-vote',
            'bonus_votes' => 10, 'votes_used' => 0, 'intent_nominee_id' => 5,
            'payment_ref' => 'AFG-PVOTE-abc123', 'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s'),
        ], $over));
    }

    /**
     * Seed the category -> cycle chain minting now requires. Paid votes may
     * only be minted while voting is OPEN, and openness is computed from the
     * cycle's date windows, so a nominee with no cycle is (correctly) not
     * mintable at all.
     */
    private function seedOpenCycle(string $votingClose = '+7 days'): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime($votingClose)),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 2, 'cycle_id' => 1, 'slug' => 'cat-2', 'title' => 'Category',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOpenCycle();
        DB::table('gates_nominees')->insert(['id' => 5, 'name' => 'Ada Obi', 'category_id' => 2, 'status' => 'approved', 'vote_count' => 3, 'organic_vote_count' => 3]);
    }

    public function test_mint_refuses_when_voting_closed_before_the_payment_confirmed(): void
    {
        // The gateway webhook or a slow browser callback can land long after
        // checkout. Money must never mint votes into a closed ballot.
        DB::table('gates_award_cycles')->where('id', 1)->update([
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $id = $this->order();

        $r = PaidVoteService::mint($id);

        $this->assertFalse($r['ok']);
        $this->assertSame('VOTING_CLOSED', $r['code']);
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', 5)->value('vote_count'), 'public tally must not move');
        $this->assertSame(0, DB::table('gates_votes')->count(), 'no vote row may be written');
        // votes_used stays 0 — the queryable "paid but never minted" signal.
        $this->assertSame(0, (int) DB::table('gates_donations')->where('id', $id)->value('votes_used'));
    }

    public function test_mint_refuses_when_the_category_has_no_resolvable_cycle(): void
    {
        // Fail closed: an orphaned category must not be mintable.
        DB::table('gates_award_categories')->where('id', 2)->update(['cycle_id' => 9999]);
        $id = $this->order();

        $r = PaidVoteService::mint($id);

        $this->assertFalse($r['ok']);
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', 5)->value('vote_count'));
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse(PaidVoteService::enabled());
        $this->assertFalse(PaidVoteService::freeVotingDisabled());
    }

    public function test_free_voting_disable_requires_paid_voting_on(): void
    {
        $this->settings(['paid_voting_disable_free' => '1']);
        $this->assertFalse(PaidVoteService::freeVotingDisabled(), 'free voting cannot vanish while paid voting is off');
        $this->settings(['paid_voting_enabled' => '1']);
        $this->assertTrue(PaidVoteService::freeVotingDisabled());
    }

    public function test_pricing_uses_cheaper_of_per_vote_and_bundle(): void
    {
        $this->settings(['vote_price_naira' => '150', 'vote_votes_per_1000' => '10']); // bundle → ₦100/vote
        $this->assertSame(150, PaidVoteService::price(1));      // below a bundle → per-vote price rules
        $this->assertSame(750, PaidVoteService::price(5));      // still below the 10-vote bundle
        $this->assertSame(1150, PaidVoteService::price(11));    // one bundle (₦1000) + 1 × ₦150 remainder
        $this->assertSame(1000, PaidVoteService::price(10));    // 10 × 150 = 1500 vs bundle 1000
        $this->assertSame(2000, PaidVoteService::price(20));    // bundle 2000 vs per-vote 3000
    }

    public function test_pricing_floor_and_defaults(): void
    {
        // Defaults: ₦100/vote, 10 votes per ₦1,000 — equivalent rates.
        $this->assertSame(100, PaidVoteService::price(1));
        $this->assertSame(1000, PaidVoteService::price(10));
        $this->assertGreaterThanOrEqual(100, PaidVoteService::price(0)); // clamped
    }

    public function test_mint_confirmed_order_bumps_public_tally_only(): void
    {
        $id = $this->order();
        $r = PaidVoteService::mint($id);
        $this->assertTrue($r['ok']);
        $this->assertSame(10, $r['minted']);

        $nom = DB::table('gates_nominees')->where('id', 5)->first();
        $this->assertSame(13, (int) $nom->vote_count, 'public tally gains the paid weight');
        $this->assertSame(3, (int) $nom->organic_vote_count, 'CPI organic signal untouched by money');

        $vote = DB::table('gates_votes')->where('donation_id', $id)->first();
        $this->assertSame('paid', $vote->vote_type);
        $this->assertSame(10, (int) $vote->weight);
        $this->assertStringStartsWith('paidvote:', (string) $vote->voter_email_hash);
    }

    public function test_mint_is_idempotent_across_webhook_and_callback(): void
    {
        $id = $this->order();
        $this->assertSame(10, PaidVoteService::mint($id)['minted']);
        $this->assertSame(0, PaidVoteService::mint($id)['minted'], 'second caller mints nothing');
        $this->assertSame(1, DB::table('gates_votes')->where('donation_id', $id)->count());
        $this->assertSame(13, (int) DB::table('gates_nominees')->where('id', 5)->value('vote_count'));
    }

    public function test_mint_refuses_unconfirmed_or_foreign_orders(): void
    {
        $pending = $this->order(['status' => 'pending', 'payment_ref' => 'AFG-PVOTE-p1']);
        $this->assertFalse(PaidVoteService::mint($pending)['ok']);

        $donation = $this->order(['tier' => 'donation', 'payment_ref' => 'AFG-GIVE-d1']);
        $this->assertFalse(PaidVoteService::mint($donation)['ok'], 'plain donations never auto-mint votes');

        $this->assertSame(0, DB::table('gates_votes')->count());
    }
}
