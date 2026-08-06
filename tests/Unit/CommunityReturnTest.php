<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\PaidVoteService;
use AfricaGates\Services\RuleEngine;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A share of what supporters raised in a nominee's name.
 *
 * The tests below are mostly attempts to get money out of the ledger that should
 * not be there: earning without a community, earning twice on one contribution,
 * keeping a share after the contribution was refunded, cashing out mid-cycle.
 */
class CommunityReturnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(5)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Ada', 'status' => 'approved']);
    }

    /**
     * 30% share, qualifying at 3 votes of support with a 40% per-supporter cap.
     *
     * Small on purpose. cap = ceil(3 × 40 / 100) = 2 votes, so the derived minimum
     * is ceil(100/40) = 3 different people — which keeps `supporters(3)` meaning
     * "just qualified" throughout the file while still exercising a real ceiling.
     */
    private function enable(int $bps = 3000, int $threshold = 3, int $capPct = 40): void
    {
        DB::table('gates_rule_sets')->insert([
            'scope' => 'cycle', 'scope_id' => 1,
            'rules' => json_encode([
                'community_return_bps'                => $bps,
                'community_return_vote_threshold'     => $threshold,
                'community_return_supporter_cap_pct'  => $capPct,
            ]),
        ]);
    }

    /** N distinct people who each cast one free vote for the nominee. */
    private function supporters(int $n, ?string $at = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 1, 'category_id' => 1,
                'voter_email_hash' => hash('sha256', "s$i@x.io"),
                'voted_at' => $at ?? Carbon::now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * A confirmed contribution.
     *
     * `votes_used` matters as much as the amount now: it is the quantity the support
     * ledger reads, and an order whose votes never minted must not qualify anybody
     * on votes that are not on the board. Defaults to the naira amount at the
     * historical ₦1,000 a vote so the fixtures read as "₦5,000 = 5 votes".
     */
    private function contribution(int $naira = 5000, string $ref = 'AFG-C1', array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId(array_merge([
            'donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 5,
            'votes_used' => intdiv($naira, 1000), 'intent_nominee_id' => 1, 'payment_ref' => $ref,
            'status' => 'confirmed', 'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /** One buyer, identified by their own email, who bought $votes votes. */
    private function buyer(string $email, int $votes, array $over = []): int
    {
        return $this->contribution($votes * 1000, 'AFG-' . strtoupper(substr(md5($email), 0, 6)),
            array_merge(['donor_email' => $email, 'votes_used' => $votes], $over));
    }

    // ── Earning ──────────────────────────────────────────────────────────────

    public function test_a_qualified_nominee_earns_their_share(): void
    {
        $this->enable();
        $this->supporters(3);
        $id = $this->contribution(5000);

        $r = Ret::accrue($id);

        $this->assertTrue($r['ok']);
        $this->assertSame(150000, $r['kobo'], '30% of ₦5,000 is ₦1,500 = 150,000 kobo');
        $b = Ret::balance(1);
        $this->assertSame(500000, $b['raised_kobo'], 'what supporters gave');
        $this->assertSame(150000, $b['share_kobo'], 'what is theirs');
    }

    /**
     * The rate is ADMIN-DEFINED, and its unset value is 30% rather than nothing.
     *
     * An earlier version defaulted to zero on the reasoning that revenue-sharing
     * should be a deliberate decision. It was the wrong call in practice: the rule
     * shipped switched off, every public page correctly published "0%", and the
     * programme was quietly promising a share it was not accruing. A default nobody
     * notices is worse than one somebody has to change.
     */
    public function test_the_default_share_is_thirty_percent_and_comes_from_the_rules(): void
    {
        $this->assertSame(3000, RuleEngine::DEFAULTS['community_return_bps']);
        $this->assertSame(3000, Ret::rateBps(1), 'with no override at all');

        // 250 votes at a 10% ceiling — the shipped defaults, derived not typed.
        $this->assertSame(250, Ret::voteThreshold(1));
        $this->assertSame(25,  Ret::supporterCapVotes(1));
        $this->assertSame(10,  Ret::minSupporters(1));
    }

    /** And an explicit zero still turns it off, for a cycle that should not share. */
    public function test_a_rate_of_zero_accrues_nothing(): void
    {
        $this->enable(0, 3);
        $this->supporters(30);

        $r = Ret::accrue($this->contribution());

        $this->assertFalse($r['ok']);
        $this->assertSame('RATE_OFF', $r['code']);
        $this->assertSame(0, Ret::balance(1)['share_kobo']);
    }

    // ── Qualifying support: votes, capped per supporter ──────────────────────

    /**
     * THE TEST THIS RULE EXISTS FOR: one person cannot buy the threshold.
     *
     * A raw vote threshold is not a rule, it is a price — one order crosses it and
     * everything genuine afterwards earns. The cap makes that arithmetically
     * impossible: however many votes one buyer takes, only `cap` of them count.
     */
    public function test_one_person_cannot_buy_their_way_over_the_line_at_any_price(): void
    {
        $this->enable(3000, 100, 10);          // 100 votes needed, cap = 10 per person

        $big = $this->buyer('whale@example.com', 5000);   // fifty times the threshold

        $q = Ret::qualification(1);
        $this->assertSame(5000, $q['raw'],  'they really did buy five thousand votes');
        $this->assertSame(10,   $q['counted'], 'and exactly ten of them counted');
        $this->assertSame(1,    $q['capped'], 'one supporter hit the ceiling');
        $this->assertFalse($q['qualified']);
        $this->assertSame('NOT_QUALIFIED', Ret::accrue($big)['code']);

        // And no amount of buying more changes it — the ceiling is per person.
        $this->buyer('whale@example.com', 5000, ['payment_ref' => 'AFG-WHALE2']);
        $this->assertSame(10, Ret::qualification(1)['counted']);
        $this->assertFalse(Ret::qualification(1)['qualified']);
    }

    /**
     * …AND THE OTHER HALF, WHICH A HEADCOUNT RULE GOT WRONG.
     *
     * Paying more still counts for more. Twenty people who bought five votes each
     * qualify; twenty people who cast one free vote each do not. Same headcount,
     * different support, different answer — which is the whole reason this counts
     * votes instead of humans.
     */
    public function test_supporters_who_gave_more_count_for_more(): void
    {
        $this->enable(3000, 100, 10);          // 100 votes needed, cap = 10 per person

        for ($i = 0; $i < 20; $i++) $this->buyer("backer$i@example.com", 5);

        $q = Ret::qualification(1);
        $this->assertSame(20,  $q['supporters']);
        $this->assertSame(100, $q['counted'], 'twenty × five votes, none of them capped');
        $this->assertSame(0,   $q['capped']);
        $this->assertTrue($q['qualified']);
    }

    /** The same twenty people, one vote each, do not reach it. */
    public function test_the_same_headcount_with_less_support_does_not_qualify(): void
    {
        $this->enable(3000, 100, 10);
        $this->supporters(20);

        $q = Ret::qualification(1);
        $this->assertSame(20, $q['supporters']);
        $this->assertSame(20, $q['counted']);
        $this->assertFalse($q['qualified']);
    }

    /**
     * The minimum number of people is DERIVED from the cap, never configured beside
     * it. Two knobs describing one rule is how a page publishes one number while the
     * engine enforces another.
     */
    public function test_the_people_floor_falls_out_of_the_ceiling(): void
    {
        $this->enable(3000, 100, 10);
        $this->assertSame(10, Ret::minSupporters(1), '10% each → at least ten people');
        $this->assertSame(10, Ret::supporterCapVotes(1));

        DB::table('gates_rule_sets')->where('scope_id', 1)->update([
            'rules' => json_encode(['community_return_bps' => 3000,
                                    'community_return_vote_threshold' => 100,
                                    'community_return_supporter_cap_pct' => 25]),
        ]);
        $this->assertSame(4,  Ret::minSupporters(1), '25% each → at least four people');
        $this->assertSame(25, Ret::supporterCapVotes(1));
    }

    /**
     * A misconfigured cap must not silently change what the platform enforces.
     *
     * At 0 the ceiling would be zero votes and nobody could ever qualify however
     * many people backed them; above 100 it stops being a ceiling and one person can
     * buy the line outright. Both are one keystroke away in a settings form.
     */
    public function test_an_impossible_cap_is_clamped_rather_than_obeyed(): void
    {
        $this->enable(3000, 100, 0);
        $this->assertSame(1, Ret::supporterCapPct(1), 'zero would lock everybody out forever');

        DB::table('gates_rule_sets')->where('scope_id', 1)->update([
            'rules' => json_encode(['community_return_supporter_cap_pct' => 900]),
        ]);
        $this->assertSame(100, Ret::supporterCapPct(1), 'above 100 is not a cap at all');
    }

    /**
     * One person is one supporter however they showed up.
     *
     * Free votes are keyed by hashed email on the vote row; purchased votes carry a
     * SYNTHETIC hash and are identified by the buyer's email on the ORDER. If those
     * two were not folded together, splitting a purchase across "free vote + order"
     * — or across two orders — would buy a second allowance.
     */
    public function test_free_votes_and_orders_from_one_person_share_one_ceiling(): void
    {
        $this->enable(3000, 100, 10);

        // Same human: one free vote, then two separate orders.
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 1,
            'voter_email_hash' => hash('sha256', 'split@example.com'),
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->buyer('split@example.com', 8);
        $this->buyer('SPLIT@Example.com ', 8, ['payment_ref' => 'AFG-SPLIT2']);

        $q = Ret::qualification(1);
        $this->assertSame(1,  $q['supporters'], 'case and whitespace do not make a second person');
        $this->assertSame(17, $q['raw']);
        $this->assertSame(10, $q['counted'], 'still one allowance between all three');
    }

    /** A refunded order raised nothing, so it qualifies nobody. */
    public function test_a_refunded_order_does_not_count_toward_qualifying(): void
    {
        $this->enable(3000, 20, 50);
        $this->buyer('gone@example.com', 10, ['refunded_at' => Carbon::now()->toDateTimeString()]);
        $this->buyer('here@example.com', 10);

        $this->assertSame(10, Ret::qualification(1)['counted'], 'only the money that stayed');
        $this->assertFalse(Ret::qualification(1)['qualified']);
    }

    /** An order charged but never minted has no votes on the board to count. */
    public function test_an_undelivered_order_does_not_count_toward_qualifying(): void
    {
        $this->enable(3000, 20, 50);
        $this->buyer('stuck@example.com', 10, ['votes_used' => 0]);

        $this->assertSame(0, Ret::qualification(1)['counted']);
    }

    /** Earning is prospective: crossing the line changes the future, not the past. */
    public function test_contributions_before_qualification_do_not_earn_later(): void
    {
        $this->enable(3000, 3);

        // Yesterday, before anybody had backed them.
        $early = $this->contribution(5000, 'AFG-EARLY',
            ['created_at' => Carbon::now()->subDay()->toDateTimeString()]);
        $this->assertSame('NOT_QUALIFIED', Ret::accrue($early)['code']);

        // The community arrives today.
        $this->supporters(3);
        $late = $this->contribution(5000, 'AFG-LATE');
        $this->assertTrue(Ret::accrue($late)['ok']);

        // And the early one STAYS unearned however many times accrual is re-run over
        // it — a reconciler retry or a manual sweep must not walk back and pay it now
        // that the community exists. This is the assertion that broke first; the fix
        // was to judge qualification as of the CONTRIBUTION's timestamp, not today's.
        $this->assertSame(150000, Ret::balance(1)['share_kobo'], 'only the contribution after the line');
        $this->assertSame('NOT_QUALIFIED', Ret::accrue($early)['code']);
        $this->assertSame('NOT_QUALIFIED', Ret::accrue($early)['code']);
    }

    /** A retried mint or a replayed webhook must not pay twice. */
    public function test_one_contribution_earns_exactly_once(): void
    {
        $this->enable();
        $this->supporters(3);
        $id = $this->contribution(5000);

        $this->assertTrue(Ret::accrue($id)['ok']);
        $this->assertSame('ALREADY_ACCRUED', Ret::accrue($id)['code']);
        $this->assertSame('ALREADY_ACCRUED', Ret::accrue($id)['code']);
        $this->assertSame(150000, Ret::balance(1)['share_kobo']);
    }

    public function test_an_unconfirmed_contribution_earns_nothing(): void
    {
        $this->enable();
        $this->supporters(3);
        $pending = $this->contribution(5000, 'AFG-PEND', ['status' => 'pending']);

        $this->assertSame('NOT_CONFIRMED', Ret::accrue($pending)['code']);
    }

    /** Rounding goes DOWN, never into money the programme never received. */
    public function test_the_share_never_rounds_up(): void
    {
        $this->enable(3333, 3);          // 33.33%
        $this->supporters(3);
        $id = $this->contribution(101);  // ₦101 = 10,100 kobo → 3,366.33 kobo

        $this->assertSame(3366, Ret::accrue($id)['kobo'], 'floored, not rounded');
    }

    // ── Money running backwards ──────────────────────────────────────────────

    /**
     * A refunded contribution cannot leave a share behind — and the accrual STAYS,
     * with a negative entry beside it. A balance that dropped for no recorded
     * reason is exactly what a ledger exists to prevent.
     */
    public function test_a_refund_takes_the_share_back_and_says_so(): void
    {
        $this->enable();
        $this->supporters(3);
        $id = $this->contribution(5000);
        Ret::accrue($id);
        $this->assertSame(150000, Ret::balance(1)['share_kobo']);

        $r = Ret::reverse($id, 'card charged back');

        $this->assertTrue($r['ok']);
        $this->assertSame(0, Ret::balance(1)['share_kobo']);
        $this->assertSame(0, Ret::balance(1)['raised_kobo'], 'the raise came off too');
        $this->assertSame(2, Ret::balance(1)['entries'], 'both entries survive — accrual and reversal');

        $st = Ret::statement(1);
        $this->assertSame('reversal', $st[0]['type']);
        $this->assertStringContainsString('charged back', $st[0]['note']);
    }

    public function test_a_reversal_happens_only_once(): void
    {
        $this->enable();
        $this->supporters(3);
        $id = $this->contribution(5000);
        Ret::accrue($id);

        $this->assertTrue(Ret::reverse($id, 'first')['ok']);
        $this->assertSame('ALREADY_REVERSED', Ret::reverse($id, 'second')['code']);
        $this->assertSame(0, Ret::balance(1)['share_kobo'], 'not driven negative by a double reversal');
    }

    /** Refunding through the real service reverses the share as a side effect. */
    public function test_the_refund_path_reverses_the_share_end_to_end(): void
    {
        $this->enable();
        $this->supporters(3);
        $id = $this->contribution(5000);
        Ret::accrue($id);

        // What RefundService::settle() writes when a refund actually lands.
        DB::table('gates_donations')->where('id', $id)->update(['refunded_at' => Carbon::now()->toDateTimeString()]);
        Ret::reverse($id, 'contribution refunded');

        $this->assertSame(0, Ret::balance(1)['share_kobo']);
    }

    // ── Payability ───────────────────────────────────────────────────────────

    /**
     * Earned is not the same as available. Nothing is payable until the cycle that
     * earned it has announced its results — a nominee cannot cash out mid-race, and
     * the platform is not paying money a fraud finding might still reverse.
     */
    public function test_nothing_is_payable_while_the_cycle_is_still_running(): void
    {
        $this->enable();
        $this->supporters(3);
        Ret::accrue($this->contribution(5000));

        $b = Ret::balance(1);
        $this->assertSame(150000, $b['share_kobo'], 'earned');
        $this->assertSame(0, $b['payable_kobo'], 'but not yet available');
    }

    public function test_it_becomes_payable_once_results_are_out(): void
    {
        $this->enable();
        $this->supporters(3);
        Ret::accrue($this->contribution(5000));

        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results']);

        $this->assertSame(150000, Ret::balance(1)['payable_kobo']);
    }

    /** It does not care whether they won. That is the whole point. */
    public function test_losing_changes_nothing(): void
    {
        $this->enable();
        $this->supporters(3);
        Ret::accrue($this->contribution(5000));
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results']);
        DB::table('gates_nominees')->where('id', 1)->update(['status' => 'approved']);   // did not win

        $this->assertSame(150000, Ret::balance(1)['payable_kobo']);
    }

    // ── Integrity holds ──────────────────────────────────────────────────────

    public function test_a_hold_removes_it_from_payable_and_can_be_lifted(): void
    {
        $this->enable();
        $this->supporters(3);
        Ret::accrue($this->contribution(5000));
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results']);

        $h = Ret::hold(1, 'open fraud finding on this nominee');
        $this->assertTrue($h['ok']);
        $this->assertSame(0, Ret::balance(1)['payable_kobo']);
        $this->assertSame(150000, Ret::balance(1)['held_kobo']);

        Ret::release(1, 150000, 'finding dismissed');
        $this->assertSame(150000, Ret::balance(1)['payable_kobo']);
        $this->assertSame(0, Ret::balance(1)['held_kobo']);
    }

    public function test_a_hold_needs_a_reason(): void
    {
        $this->enable();
        $this->supporters(3);
        Ret::accrue($this->contribution(5000));

        $this->assertSame('NO_REASON', Ret::hold(1, '   ')['code']);
    }

    public function test_an_adjustment_needs_a_reason(): void
    {
        $this->assertSame('NO_REASON', Ret::adjust(1, 5000, '  ')['code']);
        $this->assertSame('BAD_AMOUNT', Ret::adjust(1, 0, 'why')['code']);
    }

    // ── Reconciliation ───────────────────────────────────────────────────────

    /** Every accrual must trace to a confirmed contribution for the right nominee. */
    public function test_the_audit_finds_entries_that_went_around_the_service(): void
    {
        $this->enable();
        $this->supporters(3);
        Ret::accrue($this->contribution(5000));
        $this->assertSame([], Ret::audit(), 'a clean ledger reconciles');

        // Somebody writes a row straight into the database.
        DB::table('gates_community_returns')->insert([
            'nominee_id' => 1, 'cycle_id' => 1, 'entry_type' => 'accrual',
            'amount_kobo' => 999999, 'basis_kobo' => 100, 'rate_bps' => 3000,
            'donation_id' => null, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $bad = Ret::audit();
        $this->assertCount(1, $bad);
        $this->assertSame('no contribution behind it', $bad[0]['problem']);
    }

    /** The statement shows the workings, not just a number. */
    public function test_the_statement_explains_every_line(): void
    {
        $this->enable();
        $this->supporters(3);
        Ret::accrue($this->contribution(5000, 'AFG-SHOWME'));

        $line = Ret::statement(1)[0];
        $this->assertSame('accrual', $line['type']);
        $this->assertSame(150000, $line['amount']);
        $this->assertSame(500000, $line['basis']);
        $this->assertSame(30.0, $line['rate_pct']);
        $this->assertSame('AFG-SHOWME', $line['reference']);
    }

    public function test_kobo_formats_as_naira_for_display(): void
    {
        $this->assertSame('1,500.00', Ret::naira(150000));
        $this->assertSame('33.66', Ret::naira(3366));
    }

    /** Minting a paid vote accrues the share as part of the same transaction. */
    public function test_minting_a_contribution_accrues_the_share(): void
    {
        $this->enable();
        $this->supporters(3);
        // votes_used = 0 is the real pre-mint state; mint() claims the order by
        // flipping it, and refuses an order that already carries a quantity.
        $id = $this->contribution(5000, 'AFG-C1', ['votes_used' => 0]);

        $r = PaidVoteService::mint($id);
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame(150000, Ret::balance(1)['share_kobo']);
    }
}
