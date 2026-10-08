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

    /**
     * Pricing is a percentage ladder now, not a ₦1,000 bundle. The bundle rate said
     * "votes per ₦1,000", which expressed a discount only by implication and changed
     * meaning silently whenever the per-vote price moved. A percentage is what the
     * admin is actually deciding, so it is what is stored.
     */
    public function test_pricing_applies_the_tier_discount_reached(): void
    {
        $this->settings([
            'vote_price_naira' => '150',
            'vote_tiers' => json_encode([
                ['qty' => 1, 'off' => 0], ['qty' => 5, 'off' => 10], ['qty' => 20, 'off' => 25],
            ]),
        ]);
        $this->assertSame(150,  PaidVoteService::price(1));   // no tier reached
        $this->assertSame(600,  PaidVoteService::price(4));   // below the 5 tier: 4 × 150
        $this->assertSame(675,  PaidVoteService::price(5));   // 750 less 10%
        $this->assertSame(1485, PaidVoteService::price(11));  // 1650 less 10% — the 5 tier still rules
        $this->assertSame(2250, PaidVoteService::price(20));  // 3000 less 25%
    }

    /** A percentage must never round in the buyer's favour and undercharge the gateway. */
    public function test_a_fractional_discount_rounds_up(): void
    {
        $this->settings([
            'vote_price_naira' => '333',
            'vote_tiers' => json_encode([['qty' => 3, 'off' => 10]]),
        ]);
        $this->assertSame(900, PaidVoteService::price(3), '999 less 10% is 899.1 — charge 900, not 899');
    }

    /** 90% is the cap: a 100% discount is a free vote sold as a paid, unchargeable ₦0 order. */
    public function test_the_discount_is_capped_and_the_floor_holds(): void
    {
        $this->settings([
            'vote_price_naira' => '100',
            'vote_tiers' => json_encode([['qty' => 2, 'off' => 100]]),
        ]);
        $this->assertSame(0,  PaidVoteService::discountPctFor(1), 'below the tier, nothing applies');
        $this->assertSame(90, PaidVoteService::discountPctFor(5), 'clamped to 90, never 100');
        $this->assertGreaterThanOrEqual(100, PaidVoteService::price(2), 'never below the gateway minimum');
    }

    public function test_pricing_floor_and_defaults(): void
    {
        // Default ladder: ₦100/vote, first discount at 10 votes (5%).
        $this->assertSame(100, PaidVoteService::price(1));
        $this->assertSame(950, PaidVoteService::price(10));   // 1000 less the default 5%
        $this->assertGreaterThanOrEqual(100, PaidVoteService::price(0)); // clamped
    }

    /** Malformed JSON must degrade to the default ladder — this is read on the ballot. */
    public function test_broken_tier_json_falls_back_instead_of_failing(): void
    {
        $this->settings(['vote_tiers' => '{not json at all']);
        $this->assertSame(PaidVoteService::DEFAULT_TIERS, PaidVoteService::tiers());
        $this->settings(['vote_tiers' => json_encode([['qty' => 0, 'off' => 5], ['qty' => -3, 'off' => 9]])]);
        $this->assertSame(PaidVoteService::DEFAULT_TIERS, PaidVoteService::tiers(), 'every row invalid → defaults');
    }

    /** The chips the ballot renders ARE the tier quantities — one source, so they cannot disagree. */
    public function test_chips_come_from_the_same_ladder_as_the_prices(): void
    {
        $this->settings(['vote_tiers' => json_encode([
            ['qty' => 30, 'off' => 20], ['qty' => 1, 'off' => 0], ['qty' => 6, 'off' => 5],
        ])]);
        $this->assertSame([1, 6, 30], PaidVoteService::chips(), 'sorted ascending whatever the input order');
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

    /**
     * A nominee DISQUALIFIED between payment and confirmation gets no votes.
     *
     * ── THE GAP THIS CLOSES ──────────────────────────────────────────────────
     *
     * The checkout enforces the status allowlist; mint() did not, and the two are
     * separated by however long the gateway takes — longer still when the webhook
     * retries. A moderator who disqualified somebody in that window (fraud, a withdrawn
     * nomination, a rule breach found late) watched the votes land anyway: the tally on a
     * rejected page moved, the site-wide total counted it, and the buyer was told their
     * votes were in.
     *
     * This is NOT the late-confirmation case, which deliberately DOES mint, because the
     * ballot was open when we took the money and the lag is ours. Disqualification means
     * there is nothing left to credit, and crediting it anyway would put the platform's
     * own integrity decision behind a payment.
     *
     * `pending` is the status the platform actually uses for this: the admin action is
     * literally named "remove" and it sets exactly that
     * ({@see \AfricaGates\Admin\Controllers\NomineesController::action}). The schema's
     * CHECK constraint permits only pending/approved/winner/runner_up, so this — plus a
     * merge, covered below — is the whole reachable set.
     */
    public function test_mint_refuses_a_nominee_disqualified_after_payment(): void
    {
        // Exactly what /admin/nominees/{id}/remove does after the money was taken.
        DB::table('gates_nominees')->where('id', 5)->update(['status' => 'pending']);
        $id = $this->order(['payment_ref' => 'AFG-PVOTE-dq1']);

        $r = PaidVoteService::mint($id);

        $this->assertFalse($r['ok'], 'a nominee taken off the ballot must not be credited');
        $this->assertSame('NOMINEE_NOT_ELIGIBLE', $r['code']);
        // The buyer is told plainly, and the money is not kept.
        $this->assertStringContainsString('refundable', $r['message']);
        // votes_used stays 0 — the signal RefundService sweeps to send the money back.
        $this->assertSame(0, (int) DB::table('gates_donations')->where('id', $id)->value('votes_used'));
        $this->assertSame(0, DB::table('gates_votes')->count());
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', 5)->value('vote_count'),
            'the public tally must not move for a nominee off the ballot');
    }

    /** A winner or runner-up, on the other hand, is still a legitimate target. */
    public function test_mint_still_works_for_a_crowned_nominee(): void
    {
        DB::table('gates_nominees')->where('id', 5)->update(['status' => 'winner']);

        $r = PaidVoteService::mint($this->order(['payment_ref' => 'AFG-PVOTE-win1']));

        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame(10, $r['minted']);
    }

    /** And a merged-away nominee is never minted into either. */
    public function test_mint_refuses_a_merged_nominee(): void
    {
        if (!DB::schema()->hasColumn('gates_nominees', 'merged_into')) {
            $this->markTestSkipped('merged_into not present on this schema');
        }
        DB::table('gates_nominees')->where('id', 5)->update(['merged_into' => 6]);

        $r = PaidVoteService::mint($this->order(['payment_ref' => 'AFG-PVOTE-mg1']));

        $this->assertFalse($r['ok']);
        $this->assertSame('NOMINEE_NOT_ELIGIBLE', $r['code']);
        $this->assertSame(0, DB::table('gates_votes')->count());
    }
}
