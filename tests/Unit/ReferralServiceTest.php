<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The referral rules, which are money rules: ten PAID referrals to unlock, then 10% of
 * what was paid, credited exactly once per ticket.
 */
class ReferralServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['gates_referral_codes', 'gates_referral_credits'] as $t) {
            if (!DB::schema()->hasTable($t)) $this->markTestSkipped("$t not in the test schema");
        }
        DB::table('gates_users')->insert([
            ['id' => 1, 'name' => 'Amara Okonkwo', 'email' => 'amara@example.com'],
            ['id' => 2, 'name' => 'Chidi Okeke',   'email' => 'chidi@example.com'],
        ]);
    }

    // ── The code ─────────────────────────────────────────────────────────────

    public function test_a_code_requires_an_account(): void
    {
        // No anonymous referral: a code with no owner is a code with nobody to pay.
        $this->assertNull(ReferralService::codeFor(0));
        $this->assertNull(ReferralService::codeFor(-1));
    }

    public function test_a_member_gets_one_stable_code(): void
    {
        $first = ReferralService::codeFor(1);
        $this->assertNotNull($first);
        // Called again — including the double-click on "get my link" — must not mint a
        // second code, or a member's referrals split across two identities.
        $this->assertSame($first, ReferralService::codeFor(1));
        $this->assertSame(1, DB::table('gates_referral_codes')->where('user_id', 1)->count());
    }

    public function test_codes_avoid_glyphs_that_are_misread_aloud(): void
    {
        // This gets read down a phone and printed on flyers.
        for ($i = 0; $i < 40; $i++) {
            DB::table('gates_referral_codes')->truncate();
            $code = (string) ReferralService::codeFor(1);
            $this->assertStringStartsWith('AG', $code);
            $this->assertDoesNotMatchRegularExpression('/[OI10LAEU]/', substr($code, 2),
                "ambiguous or vowel glyph in $code");
        }
    }

    public function test_normalise_accepts_what_people_actually_type(): void
    {
        $this->assertSame('AGBCD234', ReferralService::normalise(' ag-bcd 234 '));
        $this->assertSame('AGBCD234', ReferralService::normalise('agbcd234'));
        $this->assertSame('', ReferralService::normalise('   '));
    }

    // ── Self-referral ────────────────────────────────────────────────────────

    public function test_a_member_cannot_use_their_own_code(): void
    {
        // Otherwise the cheapest route to ten is to buy ten tickets yourself and take 10%
        // back, which is a discount with extra steps rather than a referral scheme.
        $code = (string) ReferralService::codeFor(1);
        $this->assertFalse(ReferralService::usable($code, 1)['ok']);
        $this->assertTrue(ReferralService::usable($code, 2)['ok']);
    }

    public function test_self_referral_is_refused_by_email_when_not_signed_in(): void
    {
        // The same attack one step along: sign out, use your own link, put your own
        // address on the ticket.
        $code = (string) ReferralService::codeFor(1);
        $this->assertFalse(ReferralService::usable($code, null, 'amara@example.com')['ok']);
        $this->assertFalse(ReferralService::usable($code, null, '  AMARA@example.com ')['ok']);
        $this->assertTrue(ReferralService::usable($code, null, 'someone@else.com')['ok']);
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->assertFalse(ReferralService::usable('AGNOPE99', 2)['ok']);
    }

    // ── Credit ───────────────────────────────────────────────────────────────

    public function test_credit_is_ten_percent_of_what_was_paid(): void
    {
        $code = (string) ReferralService::codeFor(1);
        ReferralService::credit($this->reg(501, $code, 20000));

        $row = DB::table('gates_referral_credits')->where('registration_id', 501)->first();
        $this->assertNotNull($row);
        $this->assertSame(20000, (int) $row->paid_naira);
        $this->assertSame(2000, (int) $row->commission_naira);
        // Rate stamped per row, so changing it later cannot restate what somebody was told.
        $this->assertSame(1000, (int) $row->rate_bps);
    }

    public function test_commission_never_rounds_up(): void
    {
        // intdiv: ₦1,999 earns ₦199, not ₦200. Rounding up on every ticket is a slow
        // overpayment nobody notices until the books do not balance.
        $code = (string) ReferralService::codeFor(1);
        ReferralService::credit($this->reg(502, $code, 1999));
        $this->assertSame(199, (int) DB::table('gates_referral_credits')
            ->where('registration_id', 502)->value('commission_naira'));
    }

    public function test_a_free_ticket_earns_nothing(): void
    {
        $code = (string) ReferralService::codeFor(1);
        ReferralService::credit($this->reg(503, $code, 0));
        $this->assertSame(0, DB::table('gates_referral_credits')->where('registration_id', 503)->count(),
            'a free seat has no payment to take a share of');
    }

    public function test_credit_is_exactly_once_per_registration(): void
    {
        // Confirmation is reachable three ways — browser callback, gateway webhook,
        // reconciliation sweep — and they race. The unique key is what stops one ticket
        // paying commission three times.
        $code = (string) ReferralService::codeFor(1);
        $reg  = $this->reg(504, $code, 10000);
        ReferralService::credit($reg);
        ReferralService::credit($reg);
        ReferralService::credit($reg);

        $this->assertSame(1, DB::table('gates_referral_credits')->where('registration_id', 504)->count());
        $this->assertSame(1000, ReferralService::stats(1)['accrued_naira']);
    }

    public function test_an_unrecognised_or_absent_code_credits_nothing(): void
    {
        ReferralService::credit($this->reg(505, '', 10000));
        ReferralService::credit($this->reg(506, 'AGNOPE99', 10000));
        $this->assertSame(0, DB::table('gates_referral_credits')->count());
    }

    // ── The threshold ────────────────────────────────────────────────────────

    public function test_nothing_is_payable_below_the_threshold(): void
    {
        $code = (string) ReferralService::codeFor(1);
        for ($i = 1; $i < ReferralService::THRESHOLD; $i++) {
            ReferralService::credit($this->reg(600 + $i, $code, 10000));
        }

        $s = ReferralService::stats(1);
        $this->assertSame(9, $s['referrals']);
        $this->assertFalse($s['unlocked']);
        $this->assertSame(1, $s['remaining']);
        // Accrued is shown from the first referral so the number is never a surprise…
        $this->assertSame(9000, $s['accrued_naira']);
        // …but none of it is payable yet.
        $this->assertSame(0, $s['payable_naira']);
    }

    public function test_the_tenth_referral_unlocks_all_ten_retroactively(): void
    {
        $code = (string) ReferralService::codeFor(1);
        for ($i = 1; $i <= ReferralService::THRESHOLD; $i++) {
            ReferralService::credit($this->reg(700 + $i, $code, 10000));
        }

        $s = ReferralService::stats(1);
        $this->assertSame(10, $s['referrals']);
        $this->assertTrue($s['unlocked']);
        $this->assertSame(0, $s['remaining']);
        // Retroactive: the tenth makes all ten payable, not just the eleventh onward.
        // "You needed ten to start" is a rule people accept; "your first ten earned
        // nothing" is one they feel cheated by.
        $this->assertSame(10000, $s['payable_naira']);
    }

    public function test_paid_out_credits_stop_being_payable(): void
    {
        $code = (string) ReferralService::codeFor(1);
        for ($i = 1; $i <= 10; $i++) ReferralService::credit($this->reg(800 + $i, $code, 10000));

        DB::table('gates_referral_credits')->where('registration_id', 801)
            ->update(['paid_out_at' => '2026-08-21 10:00:00']);

        $s = ReferralService::stats(1);
        $this->assertSame(10000, $s['accrued_naira'], 'accrued is the lifetime figure');
        $this->assertSame(1000,  $s['paid_out_naira']);
        $this->assertSame(9000,  $s['payable_naira'], 'payable is what is still owed');
    }

    public function test_stats_for_a_member_with_no_referrals(): void
    {
        $s = ReferralService::stats(2);
        $this->assertNull($s['code'], 'a code is minted on request, not on signup');
        $this->assertSame(0, $s['referrals']);
        $this->assertSame(ReferralService::THRESHOLD, $s['remaining']);
        $this->assertFalse($s['unlocked']);
        $this->assertSame(0, $s['payable_naira']);
        $this->assertSame(10.0, $s['rate_pct']);
    }

    public function test_the_link_points_at_events(): void
    {
        $this->assertSame('https://africagates.org/events?ref=AGBCD234',
            ReferralService::link('https://africagates.org/', 'AGBCD234'));
        $this->assertSame('https://africagates.org/events/gala-2026?ref=AGBCD234',
            ReferralService::link('https://africagates.org', 'AGBCD234', 'gala-2026'));
    }

    /** A confirmed registration, as confirm() would hand it over. */
    private function reg(int $id, string $refCode, int $amount): object
    {
        return (object) ['id' => $id, 'referral_code' => $refCode,
                         'amount_naira' => $amount, 'event_id' => 9];
    }
}
