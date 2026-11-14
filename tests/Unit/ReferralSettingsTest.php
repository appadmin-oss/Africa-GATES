<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Referral terms an administrator can change, and the per-event opt-out.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO NUMBERS ARE NOT EQUIVALENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The RATE is stamped onto every credit row at the moment it is earned, so changing it
 * governs future referrals and leaves settled balances alone. That is the only version of
 * an editable rate that is safe — silently restating what people were told they had earned
 * is not a settings change, it is a repudiation.
 *
 * The THRESHOLD is evaluated live against everybody's current count, so changing it moves
 * money that is already owed in both directions. Both properties are held below, because
 * the difference is the whole reason the admin copy is worded the way it is.
 */
final class ReferralSettingsTest extends TestCase
{
    private function set(string $key, string $value): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $key], ['value' => $value]);
    }

    /**
     * A monotonic id counter, replacing `random_int(1, 1_000_000)`.
     *
     * ── WHY THIS WAS A FLAKE AND NOT A COIN-FLIP WORTH LIVING WITH ───────────
     *
     * `gates_referral_credits` has `UNIQUE(source_type, source_id)`, and that uniqueness IS
     * the idempotency guarantee — confirmation is reachable three ways and they race, so
     * `creditSale()` swallows the duplicate-key throw and returns false. Correct in
     * production, invisible in a fixture: two identical draws mean one credit silently does
     * not exist.
     *
     * `test_switching_off_does_not_erase_what_is_already_owed` then makes EXACTLY
     * `THRESHOLD` (10) credits and asserts something is owed. One dropped insert puts it at
     * nine, below the threshold, so `payable_naira` is legitimately 0 and the assertion
     * fails — having tested nothing about the switch it names. Observed once in five full
     * suite runs.
     *
     * The ids are arbitrary to every assertion in this file, so the randomness bought
     * nothing and cost a test that fails for a reason unrelated to its subject.
     */
    private int $nextRegId = 1;

    private function reg(int $paid, ?int $eventId = 1, string $code = 'AGTEST1'): object
    {
        return (object) ['id' => $this->nextRegId++, 'referral_code' => $code,
                         'amount_naira' => $paid, 'event_id' => $eventId];
    }

    private function codeRow(int $userId = 5, string $code = 'AGTEST1'): void
    {
        DB::table('gates_referral_codes')->insert([
            'user_id' => $userId, 'code' => $code, 'created_at' => '2026-08-01 10:00:00',
        ]);
    }

    // ══ defaults ═════════════════════════════════════════════════════════════

    /** Unset settings must behave exactly as the constants did, or a migration changes pay. */
    public function test_with_nothing_set_the_shipped_defaults_apply(): void
    {
        $this->assertSame(ReferralService::RATE_BPS, ReferralService::rateBps());
        $this->assertSame(ReferralService::THRESHOLD, ReferralService::threshold());
        $this->assertTrue(ReferralService::enabled());
        $this->assertSame(10.0, ReferralService::ratePct());
    }

    /** A rate read from a database needs an answer when the database is the broken thing. */
    public function test_a_junk_setting_falls_back_rather_than_paying_zero(): void
    {
        $this->set('referral_rate_bps', 'not a number');
        $this->set('referral_threshold', '');

        $this->assertSame(ReferralService::RATE_BPS, ReferralService::rateBps());
        $this->assertSame(ReferralService::THRESHOLD, ReferralService::threshold());
    }

    /** A typo in a basis-point field is easy and expensive: 10000 gives away the whole gate. */
    public function test_the_rate_is_clamped_to_a_sane_ceiling(): void
    {
        $this->set('referral_rate_bps', '10000');
        $this->assertSame(5000, ReferralService::rateBps());
    }

    public function test_a_zero_rate_is_allowed_because_it_is_a_real_choice(): void
    {
        $this->set('referral_rate_bps', '0');
        $this->assertSame(0, ReferralService::rateBps());
    }

    /** Zero is not a threshold, and the retroactive rule already covers the first referral. */
    public function test_the_threshold_cannot_be_zero(): void
    {
        $this->set('referral_threshold', '0');
        $this->assertSame(1, ReferralService::threshold());
    }

    // ══ the rate governs the future only ═════════════════════════════════════

    public function test_a_changed_rate_applies_to_new_credits(): void
    {
        $this->codeRow();
        $this->set('referral_rate_bps', '2500');            // 25%

        ReferralService::credit($this->reg(20000));

        $row = DB::table('gates_referral_credits')->first();
        $this->assertSame(5000, (int) $row->commission_naira);
        $this->assertSame(2500, (int) $row->rate_bps, 'the rate must be stamped on the row');
    }

    /**
     * The property that makes an editable rate safe at all: yesterday's credits keep
     * yesterday's rate.
     */
    public function test_changing_the_rate_does_not_restate_what_was_already_earned(): void
    {
        $this->codeRow();
        ReferralService::credit($this->reg(10000));          // at the default 10%
        $before = (int) DB::table('gates_referral_credits')->first()->commission_naira;

        $this->set('referral_rate_bps', '100');              // drop to 1%

        $after = (int) DB::table('gates_referral_credits')->first()->commission_naira;
        $this->assertSame($before, $after, 'a settled credit was rewritten by a settings change');
        $this->assertSame(1000, $after);
    }

    public function test_commission_never_rounds_up(): void
    {
        $this->codeRow();
        $this->set('referral_rate_bps', '1000');

        ReferralService::credit($this->reg(999));            // 10% of 999 = 99.9

        $this->assertSame(99, (int) DB::table('gates_referral_credits')->first()->commission_naira);
    }

    // ══ the threshold moves money, live ══════════════════════════════════════

    public function test_lowering_the_threshold_unlocks_a_locked_balance(): void
    {
        $this->codeRow();
        for ($i = 0; $i < 3; $i++) ReferralService::credit($this->reg(10000));

        $this->set('referral_threshold', '10');
        $this->assertSame(0, ReferralService::stats(5)['payable_naira'], 'three of ten should be locked');

        $this->set('referral_threshold', '3');
        $this->assertSame(3000, ReferralService::stats(5)['payable_naira'],
            'lowering the threshold must unlock the balance retroactively');
    }

    public function test_the_liability_page_follows_the_same_threshold(): void
    {
        $this->codeRow();
        for ($i = 0; $i < 3; $i++) ReferralService::credit($this->reg(10000));

        $this->set('referral_threshold', '3');
        $l = ReferralService::liability();

        $this->assertSame(3000, $l['payable_naira']);
        $this->assertSame(0, $l['locked_naira']);
        $this->assertSame(3, $l['threshold'], 'the panel must state the threshold in force');
    }

    // ══ the switches ═════════════════════════════════════════════════════════

    public function test_the_master_switch_stops_new_commission(): void
    {
        $this->codeRow();
        $this->set('referrals_enabled', '0');

        ReferralService::credit($this->reg(10000));

        $this->assertSame(0, DB::table('gates_referral_credits')->count());
    }

    /** Switching a feature off is not a reason to stop owing somebody money. */
    public function test_switching_off_does_not_erase_what_is_already_owed(): void
    {
        $this->codeRow();
        for ($i = 0; $i < 10; $i++) ReferralService::credit($this->reg(10000));
        $owed = ReferralService::stats(5)['payable_naira'];
        $this->assertGreaterThan(0, $owed);

        $this->set('referrals_enabled', '0');

        $this->assertSame($owed, ReferralService::stats(5)['payable_naira']);
        $this->assertSame($owed, ReferralService::liability()['payable_naira']);
    }

    /** A link already in somebody's hands keeps working; only new ones stop being issued. */
    public function test_switching_off_keeps_an_existing_code_but_mints_no_new_one(): void
    {
        $this->codeRow(5, 'AGKEEP1');
        $this->set('referrals_enabled', '0');

        $this->assertSame('AGKEEP1', ReferralService::codeFor(5));
        $this->assertNull(ReferralService::codeFor(99), 'a new code was minted while switched off');
    }

    // ══ per event ════════════════════════════════════════════════════════════

    private function event(int $id, int $enabled): void
    {
        DB::table('gates_site_events')->insert([
            'id' => $id, 'slug' => 'e' . $id, 'title' => 'Event ' . $id,
            'event_date' => '2026-09-01 18:00:00', 'status' => 'published',
            'referrals_enabled' => $enabled,
        ]);
    }

    public function test_an_event_can_opt_out_of_sharing_its_gate(): void
    {
        $this->codeRow();
        $this->event(7, 0);

        ReferralService::credit($this->reg(50000, 7));

        $this->assertSame(0, DB::table('gates_referral_credits')->count());
        $this->assertFalse(ReferralService::enabledForEvent(7));
    }

    public function test_another_event_still_pays(): void
    {
        $this->codeRow();
        $this->event(7, 0);
        $this->event(8, 1);

        ReferralService::credit($this->reg(50000, 8));

        $this->assertSame(1, DB::table('gates_referral_credits')->count());
        $this->assertTrue(ReferralService::enabledForEvent(8));
    }

    /** An event predating the column must keep paying — a migration must not switch it off. */
    public function test_an_event_with_no_setting_defaults_to_paying(): void
    {
        $this->assertTrue(ReferralService::enabledForEvent(4242));
        $this->assertTrue(ReferralService::enabledForEvent(null));
    }

    /**
     * The buyer is told at checkout, not thanked and then paid nothing. usable() refuses
     * for the event before it even looks the code up.
     */
    public function test_checkout_says_so_rather_than_thanking_the_buyer(): void
    {
        $this->codeRow();
        $this->event(7, 0);

        $u = ReferralService::usable('AGTEST1', null, 'buyer@example.test', 7);

        $this->assertFalse($u['ok']);
        $this->assertStringContainsString('not being used for this event', $u['message']);
    }

    public function test_a_paying_event_still_accepts_the_code(): void
    {
        $this->codeRow();
        $this->event(8, 1);

        $this->assertTrue(ReferralService::usable('AGTEST1', null, 'buyer@example.test', 8)['ok']);
    }
}
