<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ReferralPayout;
use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Paying a referral balance out, and the integrity rule the whole thing exists to protect.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * paid_out_at IS A CLAIM THAT MONEY MOVED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_referral_credits.paid_out_at IS NULL` is the definition of "owed" that every
 * screen reporting the platform's liability reads. So writing it says a transfer happened,
 * and once written the evidence that it had NOT happened is gone.
 *
 * That is why the Finance panel shipped with no bare "mark as paid" button, and why the
 * only route to stamping it is a request that names its own credits being settled against a
 * transfer REFERENCE by a named admin. The reference being mandatory is the mechanism, not
 * a nicety — it is what a bank statement can be reconciled against.
 */
final class ReferralPayoutTest extends TestCase
{
    private const USER = 5;

    private function credits(int $count, int $paid = 10000, int $userId = self::USER): void
    {
        DB::table('gates_referral_codes')->insertOrIgnore([
            'user_id' => $userId, 'code' => 'AGU' . $userId, 'created_at' => '2026-08-01 10:00:00',
        ]);
        for ($i = 0; $i < $count; $i++) {
            // The unique index is on (source_type, source_id) since credits stopped being
            // event-only; a fixture that omits them collides on the default 0.
            $reg = random_int(1, 1_000_000);
            DB::table('gates_referral_credits')->insert([
                'code_id' => 1, 'user_id' => $userId, 'registration_id' => $reg,
                'source_type' => 'registration', 'source_id' => $reg,
                'event_id' => 1, 'paid_naira' => $paid,
                'commission_naira' => intdiv($paid * ReferralService::RATE_BPS, 10000),
                'rate_bps' => ReferralService::RATE_BPS, 'created_at' => '2026-08-01 10:00:00',
            ]);
        }
    }

    private function unlocked(): void
    {
        $this->credits(ReferralService::THRESHOLD);
    }

    // ══ what can be asked for ════════════════════════════════════════════════

    public function test_a_locked_balance_cannot_be_withdrawn_and_says_why(): void
    {
        $this->credits(3);

        $a = ReferralPayout::available(self::USER);
        $this->assertFalse($a['ok']);
        $this->assertStringContainsString('more paid referral', $a['reason']);
    }

    public function test_an_unlocked_balance_can_be_withdrawn(): void
    {
        $this->unlocked();

        $a = ReferralPayout::available(self::USER);
        $this->assertTrue($a['ok'], $a['reason']);
        $this->assertSame(ReferralService::THRESHOLD * 1000, $a['amount']);
        $this->assertCount(ReferralService::THRESHOLD, $a['credits']);
    }

    /** Nothing below the floor is worth a transfer fee and somebody's afternoon. */
    public function test_a_balance_under_the_floor_is_refused_with_the_figure(): void
    {
        // Ten referrals of ₦500 → ₦50 commission each → ₦500 total, under the ₦1,000 floor.
        $this->credits(ReferralService::THRESHOLD, 500);

        $a = ReferralPayout::available(self::USER);
        $this->assertFalse($a['ok']);
        $this->assertStringContainsString('smallest withdrawal', $a['reason']);
    }

    public function test_nothing_owed_is_not_an_error(): void
    {
        $a = ReferralPayout::available(self::USER);
        $this->assertFalse($a['ok']);
        $this->assertSame(0, $a['amount']);
    }

    // ══ requesting ═══════════════════════════════════════════════════════════

    public function test_a_request_records_the_amount_and_the_account(): void
    {
        $this->unlocked();

        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        $this->assertTrue($r['ok'], $r['message']);

        $p = ReferralPayout::find($r['id']);
        $this->assertSame('requested', $p->status);
        $this->assertSame(ReferralService::THRESHOLD * 1000, (int) $p->amount_naira);
        $this->assertSame('GTBank', $p->bank_name);
        $this->assertSame('0123456789', $p->account_number);
    }

    /** A pasted account number arrives with spaces and dashes as often as not. */
    public function test_an_account_number_is_normalised(): void
    {
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123 4567-89');

        $this->assertSame('0123456789', ReferralPayout::find($r['id'])->account_number);
    }

    public function test_bad_details_are_refused(): void
    {
        $this->unlocked();

        $this->assertFalse(ReferralPayout::request(self::USER, '', 'Ada', '0123456789')['ok']);
        $this->assertFalse(ReferralPayout::request(self::USER, 'GTBank', '', '0123456789')['ok']);
        $this->assertFalse(ReferralPayout::request(self::USER, 'GTBank', 'Ada', '123')['ok']);
    }

    public function test_only_one_request_can_be_open_at_a_time(): void
    {
        $this->unlocked();
        ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');

        $again = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already have a withdrawal waiting', $again['message']);
    }

    /**
     * The set of credits is frozen at request time. A credit earned afterwards is next
     * week's business, and the amount cannot drift between request and payment.
     */
    public function test_a_credit_earned_after_the_request_is_not_swept_into_it(): void
    {
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        $locked = (int) ReferralPayout::find($r['id'])->amount_naira;

        $this->credits(2);   // two more arrive

        $this->assertSame($locked, (int) ReferralPayout::find($r['id'])->amount_naira);
    }

    // ══ paying ═══════════════════════════════════════════════════════════════

    /** THE ONE THAT MATTERS: no reference, no stamp. */
    public function test_it_cannot_be_marked_paid_without_a_transfer_reference(): void
    {
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');

        $paid = ReferralPayout::markPaid($r['id'], '   ', 1);

        $this->assertFalse($paid['ok']);
        $this->assertStringContainsString('reference', $paid['message']);
        $this->assertSame('requested', ReferralPayout::find($r['id'])->status);
        $this->assertSame(
            0,
            DB::table('gates_referral_credits')->whereNotNull('paid_out_at')->count(),
            'credits were stamped without a payment behind them'
        );
    }

    public function test_recording_a_transfer_settles_exactly_the_credits_it_covers(): void
    {
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        $this->credits(2);   // not part of the request

        $paid = ReferralPayout::markPaid($r['id'], 'GTB/2026/08/44192', 7);
        $this->assertTrue($paid['ok'], $paid['message']);

        $p = ReferralPayout::find($r['id']);
        $this->assertSame('paid', $p->status);
        $this->assertSame('GTB/2026/08/44192', $p->payment_ref);
        $this->assertSame(7, (int) $p->settled_by);

        $this->assertSame(ReferralService::THRESHOLD,
            DB::table('gates_referral_credits')->whereNotNull('paid_out_at')->count(),
            'the wrong number of credits was settled');
        $this->assertSame(2,
            DB::table('gates_referral_credits')->whereNull('paid_out_at')->count(),
            'credits outside the request were swept in');
    }

    /** After paying, what is owed drops by exactly what was paid. */
    public function test_the_liability_falls_by_the_amount_paid(): void
    {
        $this->unlocked();
        $before = ReferralService::liability()['payable_naira'];

        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        ReferralPayout::markPaid($r['id'], 'REF-1', 1);

        $this->assertSame($before - (ReferralService::THRESHOLD * 1000),
            ReferralService::liability()['payable_naira']);
        $this->assertSame(ReferralService::THRESHOLD * 1000,
            ReferralService::liability()['paid_out_naira']);
    }

    public function test_a_paid_request_cannot_be_paid_twice(): void
    {
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        ReferralPayout::markPaid($r['id'], 'REF-1', 1);

        $again = ReferralPayout::markPaid($r['id'], 'REF-2', 1);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already paid', $again['message']);
    }

    // ══ refusing ═════════════════════════════════════════════════════════════

    public function test_refusing_needs_a_reason(): void
    {
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');

        $this->assertFalse(ReferralPayout::reject($r['id'], '  ', 1)['ok']);
        $this->assertSame('requested', ReferralPayout::find($r['id'])->status);
    }

    /** A refused request is not a refused debt — the commonest cause is wrong details. */
    public function test_refusing_leaves_the_balance_owed(): void
    {
        $this->unlocked();
        $owed = ReferralService::liability()['payable_naira'];
        $r    = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');

        $out = ReferralPayout::reject($r['id'], 'Account name does not match.', 1);
        $this->assertTrue($out['ok']);

        $this->assertSame($owed, ReferralService::liability()['payable_naira']);
        $this->assertSame(0, DB::table('gates_referral_credits')->whereNotNull('paid_out_at')->count());
    }

    public function test_after_a_refusal_they_can_ask_again(): void
    {
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Wrong Name', '0123456789');
        ReferralPayout::reject($r['id'], 'Name mismatch.', 1);

        $again = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        $this->assertTrue($again['ok'], $again['message']);
    }

    // ══ saved bank details ═══════════════════════════════════════════════════

    /**
     * A default, not the record. The point is that a ten-digit account number is typed
     * once — including before there is anything to withdraw.
     */
    public function test_bank_details_can_be_saved_and_read_back(): void
    {
        DB::table('gates_users')->insert([
            'id' => self::USER, 'name' => 'Ada', 'email' => 'ada@x.test', 'points' => 0, 'email_verified' => 1,
        ]);

        $r = ReferralPayout::saveBank(self::USER, 'GTBank', 'Ada Nwosu', '0123 4567-89');
        $this->assertTrue($r['ok'], $r['message']);

        $b = ReferralPayout::bankFor(self::USER);
        $this->assertSame('GTBank', $b['bank']);
        $this->assertSame('Ada Nwosu', $b['account_name']);
        $this->assertSame('0123456789', $b['account_number'], 'the number was not normalised');
    }

    /** Saving must apply the same rules as withdrawing, or a saved value fails at use. */
    public function test_saving_rejects_what_a_withdrawal_would_reject(): void
    {
        $this->assertFalse(ReferralPayout::saveBank(self::USER, '', 'Ada', '0123456789')['ok']);
        $this->assertFalse(ReferralPayout::saveBank(self::USER, 'GTBank', '', '0123456789')['ok']);
        $this->assertFalse(ReferralPayout::saveBank(self::USER, 'GTBank', 'Ada', '12')['ok']);
    }

    public function test_no_saved_details_reads_as_blank_not_an_error(): void
    {
        $b = ReferralPayout::bankFor(4242);

        $this->assertSame(['bank' => '', 'account_name' => '', 'account_number' => ''], $b);
    }

    /**
     * Changing the saved details must not restate where an earlier transfer went — the
     * payout row's own snapshot is the record.
     */
    public function test_changing_saved_details_does_not_rewrite_a_past_payout(): void
    {
        DB::table('gates_users')->insert([
            'id' => self::USER, 'name' => 'Ada', 'email' => 'ada@x.test', 'points' => 0, 'email_verified' => 1,
        ]);
        $this->unlocked();
        $r = ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');
        ReferralPayout::markPaid($r['id'], 'REF-1', 1);

        ReferralPayout::saveBank(self::USER, 'Zenith', 'Ada N Nwosu', '9876543210');

        $p = ReferralPayout::find($r['id']);
        $this->assertSame('GTBank', $p->bank_name, 'a settled payout was rewritten');
        $this->assertSame('0123456789', $p->account_number);
    }

    // ══ the admin queue ══════════════════════════════════════════════════════

    public function test_the_queue_carries_the_member_and_the_totals(): void
    {
        $this->unlocked();
        DB::table('gates_users')->insert([
            'id' => self::USER, 'name' => 'Ada Nwosu', 'email' => 'ada@example.test',
            'points' => 0, 'email_verified' => 1,
        ]);
        ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');

        $q = ReferralPayout::queue('requested');
        $this->assertCount(1, $q);
        $this->assertSame('Ada Nwosu', $q[0]['name']);
        $this->assertSame('ada@example.test', $q[0]['email']);

        $t = ReferralPayout::totals();
        $this->assertSame(1, $t['pending']);
        $this->assertSame(ReferralService::THRESHOLD * 1000, $t['pending_naira']);
        $this->assertSame(0, $t['paid_naira']);
    }

    /** A member whose account row is gone still has to be payable. */
    public function test_the_queue_survives_a_missing_member_row(): void
    {
        $this->unlocked();
        ReferralPayout::request(self::USER, 'GTBank', 'Ada Nwosu', '0123456789');

        $q = ReferralPayout::queue('requested');
        $this->assertSame('Member #5', $q[0]['name']);
    }
}
