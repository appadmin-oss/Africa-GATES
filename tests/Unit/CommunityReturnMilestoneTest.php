<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\CommunityReturnWithdrawals as Wd;
use AfricaGates\Services\RuleEngine;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The two-stage community return: a locked share before an edition's milestone is
 * confirmed, the full share after, and a reviewed way to ask for the money.
 *
 * Like CommunityReturnTest, most of these are attempts to get money out that should not
 * come out: at the full rate before the milestone, as cash while it is still locked, twice
 * through two requests, or after a refund or a hold has taken it away.
 */
class CommunityReturnMilestoneTest extends TestCase
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

    /** The edition opts in: 10% locked, 50% after, milestone at ₦10,000. */
    private function milestoneEdition(int $targetNaira = 10000): void
    {
        $r = Milestone::saveEditionRules(1, ['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10,
                                             'milestone_naira' => $targetNaira]);
        $this->assertTrue($r['ok'], $r['message']);
    }

    private function contribution(int $naira, string $ref, array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId(array_merge([
            'donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 5,
            'votes_used' => intdiv($naira, 1000), 'intent_nominee_id' => 1, 'payment_ref' => $ref,
            'status' => 'confirmed', 'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /** Ada has claimed her page, verified, past the cooling-off window, as member 7. */
    private function claimed(): void
    {
        DB::table('gates_users')->insert(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com']);
        DB::table('gates_nominee_claims')->insert([
            'nominee_id' => 1, 'user_id' => 7, 'status' => 'active', 'active_nominee_id' => 1,
            'claimed_at' => Carbon::now()->subDays(20)->toDateTimeString(),
            'activated_at' => Carbon::now()->subDays(20)->toDateTimeString(),
        ]);
    }

    private function publishTerms(array $over = []): void
    {
        (new RuleEngine())->merge('global', null, array_merge([
            'community_return_terms_published' => true,
            'community_return_tax_note'        => 'No tax is withheld; you are responsible for your own tax.',
            'community_return_min_withdrawal_naira' => 100,
        ], $over));
    }

    private const BANK = ['bank' => 'GTBank', 'account_name' => 'Ada Obi', 'account_number' => '0123456789'];

    // ── The model is opt-in ───────────────────────────────────────────────────

    public function test_an_edition_runs_the_threshold_model_unless_it_opts_in(): void
    {
        $this->assertSame(Ret::MODEL_THRESHOLD, Ret::modelForCycle(1));
        $this->milestoneEdition();
        $this->assertSame(Ret::MODEL_MILESTONE, Ret::modelForCycle(1));
        $this->assertSame(Ret::MODEL_THRESHOLD, Ret::modelOf(['community_return_model' => 'nonsense']),
            'an unrecognised model falls back to the rule nominees were already told');
    }

    // ── Before the milestone ─────────────────────────────────────────────────

    public function test_before_the_milestone_every_contribution_earns_the_locked_share(): void
    {
        $this->milestoneEdition();
        $r = Ret::accrue($this->contribution(5000, 'A1'));

        $this->assertTrue($r['ok']);
        $this->assertSame('ACCRUED_LOCKED', $r['code']);
        $this->assertSame(50000, $r['kobo'], '10% of ₦5,000, with no individual threshold to cross first');

        $w = Ret::wallet(1);
        $this->assertSame(50000, $w['locked_kobo']);
        $this->assertSame(0, $w['available_kobo'], 'a locked credit is never presented as cash');
    }

    public function test_the_milestone_cannot_be_confirmed_before_it_is_reached(): void
    {
        $this->milestoneEdition(10000);
        Ret::accrue($this->contribution(5000, 'A1'));

        $r = Milestone::confirm(1, 1);
        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_REACHED', $r['code']);
        $this->assertNull(Milestone::confirmedAt(1));
    }

    public function test_a_threshold_edition_has_no_milestone_to_confirm(): void
    {
        $this->assertSame('NOT_MILESTONE', Milestone::confirm(1, 1)['code']);
    }

    // ── Confirmation ─────────────────────────────────────────────────────────

    public function test_confirming_unlocks_the_credits_and_later_money_earns_the_full_share(): void
    {
        $this->milestoneEdition(10000);
        $this->claimed();
        Ret::accrue($this->contribution(6000, 'A1', ['created_at' => Carbon::now()->subMinutes(10)->toDateTimeString()]));
        Ret::accrue($this->contribution(4000, 'A2', ['created_at' => Carbon::now()->subMinutes(9)->toDateTimeString()]));

        $c = Milestone::confirm(1, 1, 'checked against the gateway');
        $this->assertTrue($c['ok'], $c['message']);
        $this->assertSame(1000000, $c['measured_kobo']);

        $w = Ret::wallet(1);
        $this->assertSame(0, $w['locked_kobo']);
        $this->assertSame(100000, $w['available_kobo'], '10% of ₦10,000, unlocked by the confirmation');

        // Made after the confirmation was recorded.
        $r = Ret::accrue($this->contribution(2000, 'A3', ['created_at' => Carbon::now()->addMinute()->toDateTimeString()]));
        $this->assertSame('ACCRUED', $r['code']);
        $this->assertSame(100000, $r['kobo'], '50% of ₦2,000 from the confirmation on');

        $this->assertFalse(Milestone::confirm(1, 1)['ok'], 'confirmation happens once');
    }

    public function test_a_contribution_made_before_confirmation_keeps_the_locked_rate_if_it_lands_late(): void
    {
        $this->milestoneEdition(1000);
        Ret::accrue($this->contribution(1000, 'A1', ['created_at' => Carbon::now()->subHours(2)->toDateTimeString()]));
        // Paid an hour ago, its webhook delayed past the confirmation.
        $late = $this->contribution(4000, 'A2', ['created_at' => Carbon::now()->subHour()->toDateTimeString()]);
        $this->assertTrue(Milestone::confirm(1, 1)['ok']);

        $r = Ret::accrue($late);
        $this->assertSame(40000, $r['kobo'], 'priced at its own moment: the locked rate, not the full one');
    }

    // ── Money running backwards ──────────────────────────────────────────────

    public function test_a_refunded_locked_credit_comes_off_the_locked_figure(): void
    {
        $this->milestoneEdition();
        $id = $this->contribution(5000, 'A1');
        Ret::accrue($id);
        Ret::reverse($id, 'refunded');

        $w = Ret::wallet(1);
        $this->assertSame(0, $w['locked_kobo']);
        $this->assertSame(50000, $w['reversed_kobo']);
    }

    public function test_a_fraud_flagged_contribution_earns_nothing_and_does_not_count_toward_the_milestone(): void
    {
        $this->milestoneEdition(1000);
        $id = $this->contribution(5000, 'A1');
        DB::table('gates_votes')->insert(['nominee_id' => 1, 'category_id' => 1, 'donation_id' => $id,
            'voter_email_hash' => 'paidvote:1:x', 'vote_type' => 'paid', 'fraud_flag' => 1,
            'voted_at' => Carbon::now()->toDateTimeString()]);

        $this->assertSame('NOT_ELIGIBLE', Ret::accrue($id)['code']);
        $this->assertSame(0, Milestone::measuredKobo(1));
    }

    public function test_refunded_money_does_not_count_toward_the_milestone(): void
    {
        $this->milestoneEdition();
        $this->contribution(5000, 'A1');
        $this->contribution(5000, 'A2', ['refunded_at' => Carbon::now()->toDateTimeString()]);
        $this->assertSame(500000, Milestone::measuredKobo(1));
    }

    // ── The rules lock ───────────────────────────────────────────────────────

    public function test_an_editions_rules_lock_once_it_has_ledger_entries(): void
    {
        $this->milestoneEdition();
        Ret::accrue($this->contribution(5000, 'A1'));

        $r = Milestone::saveEditionRules(1, ['model' => 'threshold']);
        $this->assertFalse($r['ok']);
        $this->assertSame(Ret::MODEL_MILESTONE, Ret::modelForCycle(1), 'nothing changed under the nominee');
    }

    public function test_the_locked_share_cannot_exceed_the_full_share_and_a_target_is_required(): void
    {
        $this->assertFalse(Milestone::saveEditionRules(1, ['model' => 'milestone', 'full_pct' => 10,
            'locked_pct' => 50, 'milestone_naira' => 1000])['ok']);
        $this->assertFalse(Milestone::saveEditionRules(1, ['model' => 'milestone', 'full_pct' => 50,
            'locked_pct' => 10, 'milestone_naira' => 0])['ok']);
    }

    // ── Withdrawals ──────────────────────────────────────────────────────────

    /** A confirmed edition with ₦1,000 available to Ada. */
    private function available(): void
    {
        $this->milestoneEdition(10000);
        $this->claimed();
        Ret::accrue($this->contribution(10000, 'A1', ['created_at' => Carbon::now()->subMinute()->toDateTimeString()]));
        $this->assertTrue(Milestone::confirm(1, 1)['ok']);
    }

    public function test_withdrawals_stay_closed_until_the_terms_are_published_with_a_tax_note(): void
    {
        $this->available();
        $this->assertFalse(Wd::request(1, 7, self::BANK, true)['ok']);

        $this->publishTerms(['community_return_tax_note' => '']);
        $this->assertFalse(Wd::terms()['published'], 'a ticked flag over an empty tax position is not published');
        $this->assertFalse(Wd::request(1, 7, self::BANK, true)['ok']);
    }

    public function test_only_the_verified_claimant_may_ask_and_they_must_accept_the_rules(): void
    {
        $this->available();
        $this->publishTerms();

        $this->assertFalse(Wd::request(1, 99, self::BANK, true)['ok'], 'not the claimant');
        $this->assertFalse(Wd::request(1, 7, self::BANK, false)['ok'], 'rules not accepted');
        $this->assertFalse(Wd::request(1, 7, ['bank' => 'X', 'account_name' => 'Ada', 'account_number' => '12'], true)['ok']);
    }

    public function test_a_locked_balance_cannot_be_withdrawn(): void
    {
        $this->milestoneEdition(10000);
        $this->claimed();
        $this->publishTerms();
        Ret::accrue($this->contribution(5000, 'A1'));

        $r = Wd::request(1, 7, self::BANK, true);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('locked', $r['message']);
    }

    public function test_request_review_and_payment_move_the_money_exactly_once(): void
    {
        $this->available();
        $this->publishTerms();

        $r = Wd::request(1, 7, self::BANK, true);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(0, Ret::wallet(1)['available_kobo'], 'under review is not available');
        $this->assertSame(100000, Ret::wallet(1)['under_review_kobo']);
        $this->assertFalse(Wd::request(1, 7, self::BANK, true)['ok'], 'one open request at a time');

        $id = (int) $r['id'];
        $this->assertFalse(Wd::markPaid($id, 'REF1', 1)['ok'], 'not before a person approves it');
        $this->assertTrue(Wd::approve($id, 1)['ok']);
        $this->assertFalse(Wd::markPaid($id, '  ', 1)['ok'], 'a payment needs its transfer reference');
        $this->assertTrue(Wd::markPaid($id, 'GTB/2026/1', 1)['ok']);
        $this->assertFalse(Wd::markPaid($id, 'GTB/2026/1', 1)['ok'], 'and is recorded once');

        $w = Ret::wallet(1);
        $this->assertSame(100000, $w['paid_kobo']);
        $this->assertSame(0, $w['available_kobo']);
        $this->assertSame(0, $w['under_review_kobo']);
        $this->assertSame(1, DB::table('gates_community_returns')->where('entry_type', 'payout')->count());
    }

    public function test_a_hold_after_the_request_stops_the_payment(): void
    {
        $this->available();
        $this->publishTerms();
        $id = (int) Wd::request(1, 7, self::BANK, true)['id'];
        Ret::hold(1, 'integrity finding');

        $this->assertFalse(Wd::approve($id, 1)['ok']);
        $this->assertSame(0, DB::table('gates_community_returns')->where('entry_type', 'payout')->count());
    }

    public function test_refusing_a_request_leaves_the_money_with_the_nominee(): void
    {
        $this->available();
        $this->publishTerms();
        $id = (int) Wd::request(1, 7, self::BANK, true)['id'];

        $this->assertFalse(Wd::reject($id, ' ', 1)['ok'], 'a refusal needs a reason');
        $this->assertTrue(Wd::reject($id, 'Account name does not match', 1)['ok']);
        $this->assertSame(100000, Ret::wallet(1)['available_kobo']);
    }

    /**
     * The account page offers the form to somebody with NO saved bank details. Asking the
     * account condition before the form is drawn kept the form from ever appearing for the
     * one person who needed it — found in the browser, not by a test, so held here now.
     */
    public function test_the_withdraw_form_is_offered_before_any_bank_details_are_saved(): void
    {
        $this->available();
        $this->publishTerms();
        $panels = Wd::panelsFor(7);
        $this->assertCount(1, $panels);
        $this->assertTrue($panels[0]['can'], $panels[0]['why']);
        $this->assertSame(100000, $panels[0]['amount']);
    }

    public function test_a_payout_reduces_the_balance_even_when_filed_under_an_unreleased_edition(): void
    {
        $this->available();
        $this->publishTerms();
        // Ada is moved to a second, unreleased edition before she is paid; the payout row
        // is filed there. It must still come off what she can withdraw.
        DB::table('gates_award_cycles')->insert(['id' => 2, 'programme_id' => 1, 'year' => (int) date('Y') + 1, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 2, 'cycle_id' => 2, 'slug' => 'c2', 'title' => 'Film']);

        $id = (int) Wd::request(1, 7, self::BANK, true)['id'];
        Wd::approve($id, 1);
        DB::table('gates_nominees')->where('id', 1)->update(['category_id' => 2]);
        $this->assertTrue(Wd::markPaid($id, 'REF', 1)['ok']);

        $this->assertSame(0, Ret::wallet(1)['available_kobo']);
    }
}
