<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AlertService;
use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\MemberWallet as W;
use AfricaGates\Services\ReferralPayout;
use AfricaGates\Services\ReferralService;
use AfricaGates\Services\RuleEngine;
use AfricaGates\Services\WalletFee;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The member wallet: one balance across the community return, referral earnings and voting
 * points, the history behind it, and the one way money leaves it.
 *
 * What these try to break: points added to the naira headline, a pocket or a receipt that
 * belongs to somebody else opening by its key, points withdrawn as cash, a withdrawal fee
 * that is not the one the schedule says, a part-withdrawal taking the whole balance, and a
 * notification naming money the wallet does not show.
 */
final class MemberWalletTest extends TestCase
{
    private const BANK = ['bank' => 'GTBank', 'account_name' => 'Ada Obi', 'account_number' => '0123456789'];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(5)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Ada', 'status' => 'approved']);
        DB::table('gates_users')->insert(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'points' => 0, 'email_verified' => 1]);
        DB::table('gates_users')->insert(['id' => 8, 'name' => 'Bola', 'email' => 'bola@example.com', 'points' => 0, 'email_verified' => 1]);
    }

    /** Ada holds nominee 1's claim, ₦1,000 of return is released, terms are published. */
    private function returnAvailable(): void
    {
        DB::table('gates_nominee_claims')->insert([
            'nominee_id' => 1, 'user_id' => 7, 'status' => 'active', 'active_nominee_id' => 1,
            'claimed_at' => Carbon::now()->subDays(20)->toDateTimeString(),
            'activated_at' => Carbon::now()->subDays(20)->toDateTimeString(),
        ]);
        $this->assertTrue(Milestone::saveEditionRules(1, ['model' => 'milestone', 'full_pct' => 10, 'locked_pct' => 10,
                                                          'milestone_naira' => 10000])['ok']);
        Ret::accrue((int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com', 'amount_naira' => 10000,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 10, 'intent_nominee_id' => 1,
            'payment_ref' => 'PV1', 'status' => 'confirmed',
            'created_at' => Carbon::now()->subMinute()->toDateTimeString(),
        ]));
        $this->assertTrue(Milestone::confirm(1, 1)['ok']);
        (new RuleEngine())->merge('global', null, [
            'community_return_terms_published' => true,
            'community_return_tax_note' => 'No tax is withheld.',
            'community_return_min_withdrawal_naira' => 100,
        ]);
    }

    private function points(int $userId, array $deltas): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'points_enabled', 'value' => '1']);
        $bal = 0;
        foreach ($deltas as $i => $d) {
            $bal += $d;
            DB::table('gates_points_ledger')->insert(['user_id' => $userId, 'delta' => $d, 'reason' => 'purchase',
                'balance_after' => $bal, 'created_at' => Carbon::now()->subMinutes(10 - $i)->toDateTimeString()]);
        }
        DB::table('gates_users')->where('id', $userId)->update(['points' => $bal]);
    }

    private function referralsUnlocked(int $userId): void
    {
        DB::table('gates_referral_codes')->insert(['user_id' => $userId, 'code' => 'AG' . $userId, 'created_at' => '2026-08-01 10:00:00']);
        for ($i = 1; $i <= ReferralService::THRESHOLD; $i++) {
            DB::table('gates_referral_credits')->insert([
                'code_id' => 1, 'user_id' => $userId, 'registration_id' => $i, 'source_type' => 'registration',
                'source_id' => $i, 'event_id' => 1, 'paid_naira' => 10000,
                'commission_naira' => 1000, 'rate_bps' => 1000, 'created_at' => '2026-08-01 10:00:00',
            ]);
        }
    }

    // ── the headline ──────────────────────────────────────────────────────────

    public function test_points_are_a_pocket_of_the_wallet_and_never_added_to_the_naira(): void
    {
        $this->returnAvailable();
        $this->points(7, [600, 520]);

        $s = W::summary(7);
        $this->assertSame(100000, $s['available_kobo'], '10% of ₦10,000 — the points are not in it');
        $this->assertSame(1120, $s['points']);
        $this->assertSame(2, $s['points_votes'], '500 points a vote');
        $this->assertSame(['return', 'points'], array_column($s['pockets'], 'kind'));

        $pts = W::pocket(7, 'points');
        $this->assertFalse($pts['can'], 'points are spent on votes, never paid out');
    }

    public function test_a_member_with_nothing_has_no_pockets_and_no_error(): void
    {
        $this->assertSame([], W::pockets(8));
        $this->assertSame(0, W::summary(8)['available_kobo']);
        $this->assertSame([], W::transactions(8));
    }

    // ── the history and its receipts ──────────────────────────────────────────

    public function test_every_movement_is_in_one_history_with_its_unit(): void
    {
        $this->returnAvailable();
        $this->points(7, [60, -40]);

        $tx = W::transactions(7);
        $byKind = [];
        foreach ($tx as $t) $byKind[$t['pocket']][] = $t;
        $this->assertCount(1, $byKind['return']);
        $this->assertSame('naira', $byKind['return'][0]['unit']);
        $this->assertSame(100000, $byKind['return'][0]['amount_kobo']);
        $this->assertSame(W::ST_RECEIVED, $byKind['return'][0]['status'], 'the milestone is confirmed, so it is received');

        $this->assertCount(2, $byKind['points']);
        foreach ($byKind['points'] as $p) $this->assertSame('pts', $p['unit']);
        $spent = array_values(array_filter($byKind['points'], fn ($t) => $t['dir'] === 'out'))[0];
        $this->assertSame(40, $spent['amount_kobo']);
        $this->assertSame('Spent', $spent['status_label']);
    }

    public function test_a_receipt_opens_only_for_the_member_it_belongs_to(): void
    {
        $this->returnAvailable();
        $key = W::transactions(7)[0]['key'];

        $this->assertNotNull(W::transaction(7, $key));
        $this->assertNull(W::transaction(8, $key), 'another member typing the key into the URL gets nothing');
        $this->assertNull(W::pocket(8, 'page-1'), 'nor the pocket');
    }

    // ── taking money out ──────────────────────────────────────────────────────

    public function test_the_review_figures_come_from_the_fee_schedule(): void
    {
        $this->returnAvailable();
        $q = W::quote(7, 'page-1', 60000, self::BANK);
        $this->assertTrue($q['ok'], $q['reason']);
        $this->assertSame(60000, $q['amount_kobo'], 'part of the balance, as asked');
        $this->assertSame(WalletFee::forKobo(60000), $q['fee_kobo']);
        $this->assertSame(60000 - 1000, $q['net_kobo'], '₦10 up to ₦5,000');

        $this->assertFalse(W::quote(7, 'page-1', 200000, self::BANK)['ok'], 'more than is available');
        $this->assertFalse(W::quote(7, 'page-1', null, ['bank' => '', 'account_name' => '', 'account_number' => ''])['ok']);
        $this->assertFalse(W::quote(8, 'page-1', null, self::BANK)['ok'], 'not their pocket');
    }

    public function test_a_part_withdrawal_freezes_its_amount_and_fee_and_leaves_the_rest(): void
    {
        $this->returnAvailable();
        $this->assertFalse(W::withdraw(7, 'page-1', 60000, self::BANK, false)['ok'], 'the rules must be accepted');

        $r = W::withdraw(7, 'page-1', 60000, self::BANK, true);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertMatchesRegularExpression('/^crw-\d+$/', $r['key']);

        $row = DB::table('gates_return_withdrawals')->first();
        $this->assertSame(60000, (int) $row->amount_kobo);
        $this->assertSame(1000, (int) $row->fee_kobo);
        $this->assertSame(40000, Ret::wallet(1)['available_kobo'], 'the rest stays available');
        $this->assertSame(60000, W::summary(7)['review_kobo']);

        $t = W::transaction(7, $r['key']);
        $this->assertSame(W::ST_PENDING, $t['status']);
        $this->assertSame(1000, $t['fee_kobo']);
        $this->assertSame('GTBank', DB::table('gates_users')->where('id', 7)->value('payout_bank'), 'saved for next time');
    }

    public function test_points_cannot_be_withdrawn_by_any_route(): void
    {
        $this->points(7, [5000]);
        $this->assertFalse(W::quote(7, 'points', null, self::BANK)['ok']);
        $this->assertFalse(W::withdraw(7, 'points', null, self::BANK, true)['ok']);
        $this->assertSame(0, (int) DB::table('gates_referral_payouts')->count());
        $this->assertSame(0, (int) DB::table('gates_return_withdrawals')->count());
    }

    public function test_a_referral_withdrawal_goes_through_its_own_path_with_the_same_fee(): void
    {
        $this->referralsUnlocked(8);
        $owed = ReferralService::THRESHOLD * 1000;
        $q = W::quote(8, 'ref', 1, self::BANK);
        $this->assertTrue($q['ok'], $q['reason']);
        $this->assertSame($owed * 100, $q['amount_kobo'], 'referral earnings are withdrawn whole, whatever is typed');
        $this->assertSame(WalletFee::forKobo($owed * 100), $q['fee_kobo']);

        $r = W::withdraw(8, 'ref', null, self::BANK, true);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertStringStartsWith('rp-', $r['key']);
        $this->assertSame(intdiv(WalletFee::forKobo($owed * 100), 100), (int) DB::table('gates_referral_payouts')->value('fee_naira'));
        $this->assertFalse(W::pocket(8, 'ref')['can'], 'one open request at a time');
    }

    public function test_typed_amounts_are_read_or_refused_never_guessed(): void
    {
        $this->assertSame(250000, W::parseNaira('2,500'));
        $this->assertSame(250050, W::parseNaira('₦2500.50'));
        $this->assertNull(W::parseNaira('two thousand'));
        $this->assertNull(W::parseNaira('2500.505'));
        $this->assertNull(W::parseNaira(''));
    }

    // ── the fee schedule ──────────────────────────────────────────────────────

    public function test_the_fee_bands_are_applied_at_their_edges(): void
    {
        $eff = ['wallet_fee_bands' => WalletFee::DEFAULT_BANDS];
        $this->assertSame(1000, WalletFee::forKobo(500000, $eff), '₦5,000 is in the first band');
        $this->assertSame(2500, WalletFee::forKobo(500100, $eff), '₦5,001 is in the second');
        $this->assertSame(2500, WalletFee::forKobo(5000000, $eff));
        $this->assertSame(5000, WalletFee::forKobo(5000100, $eff));
        $this->assertSame(500, WalletFee::forKobo(500, $eff), 'never more than the amount');
        $this->assertSame(0, WalletFee::forKobo(500000, ['wallet_fee_bands' => []]), 'an empty schedule is no fee');
        $this->assertSame('₦10 up to ₦5,000 · ₦25 up to ₦50,000 · ₦50 above ₦50,000', WalletFee::describe($eff));
        $this->assertFalse(WalletFee::parse('five: 10')['ok']);
        $this->assertSame([[0, 0], [1000, 20]], WalletFee::parse("1000: 20\n0: 0")['bands']);
    }

    // ── notifications ─────────────────────────────────────────────────────────

    public function test_activity_tells_the_member_what_moved_in_their_wallet(): void
    {
        $this->returnAvailable();
        $r = W::withdraw(7, 'page-1', 60000, self::BANK, true);
        $this->assertTrue($r['ok']);

        $texts = array_column((new AlertService())->forMember(7, 'ada@example.com'), 'text');
        $this->assertContains('₦1,000.00 came into your wallet from a supporter', $texts);
        $this->assertContains('Your ₦600 withdrawal is being reviewed', $texts);
        $this->assertContains('Your edition’s milestone was confirmed — your community return is released', $texts);

        $id = (int) substr($r['key'], 4);
        \AfricaGates\Services\CommunityReturnWithdrawals::approve($id, 1);
        \AfricaGates\Services\CommunityReturnWithdrawals::markPaid($id, 'TRF-1', 1);
        $texts = array_column((new AlertService())->forMember(7, 'ada@example.com'), 'text');
        $this->assertContains('Your ₦600 withdrawal was paid — check your bank', $texts);
        $this->assertNotContains('Your ₦600 withdrawal is being reviewed', $texts, 'one item that moves, not a trail');

        $this->assertSame([], (new AlertService())->forMember(8, 'bola@example.com'), 'nobody else is told');
    }
}
