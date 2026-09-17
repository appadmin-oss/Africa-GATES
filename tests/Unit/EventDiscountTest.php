<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventDiscount as D;
use AfricaGates\Services\EventTicketService as T;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Discount codes, and every limit that stops one becoming a story.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE PARTICULAR PROPERTIES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A discount code is the one control on this platform where a single missing check costs real
 * money in public, at speed, and irreversibly — the seats are gone and the tickets are valid.
 * Each test here corresponds to one of the ways that happens:
 *
 *   1. THE PRICE IS DECIDED SERVER-SIDE. A total worked out in the browser is a total
 *      anybody can edit, and `confirm()` refuses a payment smaller than the amount on the
 *      row — so a forged discount would not merely undercharge, it would make the ticket
 *      unissuable and the money unmatchable.
 *   2. `max_uses` HOLDS. A code shared in a WhatsApp group is a code used four hundred times.
 *   3. `max_per_email` HOLDS. Without it, one person books ten discounted tables.
 *   4. `tier_ids` HOLDS. A student discount that also applied to the top table is an
 *      expensive kind of generous.
 *   5. THE WINDOW HOLDS. An early-bird code that still works in December.
 *   6. NOTHING GOES NEGATIVE. A ₦10,000 code against a ₦4,000 ticket is a free ticket, not
 *      a refund.
 *   7. AN ABANDONED CHECKOUT GIVES ITS USE BACK. Otherwise a code limited to fifty is
 *      exhausted by fifty people who never came, and the promotion ends before the event.
 */
final class EventDiscountTest extends TestCase
{
    private int $eventId = 0;
    private int $tierId  = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        DB::table('gates_event_codes')->delete();
        DB::table('gates_event_tiers')->delete();
        DB::table('gates_site_events')->delete();

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'The Gala',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'status' => 'published', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->tierId = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => 'standard', 'name' => 'Standard',
            'price_naira' => 10000, 'capacity' => null, 'min_per_order' => 1,
            'max_per_order' => 10, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param array<string,mixed> $over */
    private function code(array $over = []): int
    {
        return (int) DB::table('gates_event_codes')->insertGetId(array_merge([
            'event_id' => $this->eventId, 'code' => 'ALUMNI20', 'label' => 'Alumni rate',
            'kind' => 'percent', 'amount' => 20, 'max_per_email' => 1, 'used_count' => 0,
            'is_active' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /** @return array{name:string,email:string,phone:string} */
    private function who(string $email = 'ada@example.test'): array
    {
        return ['name' => 'Ada Obi', 'email' => $email, 'phone' => '08031234567'];
    }

    // ══ 1. the arithmetic ════════════════════════════════════════════════════

    public function test_a_percentage_comes_off_the_line_total_not_each_seat(): void
    {
        $this->code(['amount' => 15]);
        // Three seats at ₦333 is ₦999; 15% of that is ₦149 (floored). Rounding per seat would
        // give 3 × 49 = ₦147, and a total that is not three times anything.
        DB::table('gates_event_tiers')->where('id', $this->tierId)->update(['price_naira' => 333]);

        $r = D::apply('ALUMNI20', $this->eventId, $this->tierId, 999, 'ada@example.test', 3);

        $this->assertTrue($r['ok']);
        $this->assertSame(149, $r['off']);
        $this->assertSame(850, $r['total']);
    }

    public function test_a_fixed_discount_larger_than_the_ticket_clamps_to_the_ticket(): void
    {
        $this->code(['kind' => 'fixed', 'amount' => 10000]);

        $r = D::apply('ALUMNI20', $this->eventId, $this->tierId, 4000, 'ada@example.test');

        $this->assertTrue($r['ok']);
        // A free ticket, not a refund.
        $this->assertSame(4000, $r['off']);
        $this->assertSame(0, $r['total']);
    }

    public function test_a_code_that_takes_nothing_off_is_refused_rather_than_applied(): void
    {
        $this->code(['kind' => 'fixed', 'amount' => 5000]);

        // A free tier: 5,000 off nothing is nothing, and reporting success would tell somebody
        // their code worked when it had no effect at all.
        $r = D::apply('ALUMNI20', $this->eventId, $this->tierId, 0, 'ada@example.test');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('nothing off', $r['message']);
    }

    public function test_the_letters_are_matched_case_insensitively(): void
    {
        $this->code();
        $this->assertTrue(D::apply('alumni20', $this->eventId, $this->tierId, 10000, 'a@x.test')['ok']);
        $this->assertTrue(D::apply('  Alumni20 ', $this->eventId, $this->tierId, 10000, 'b@x.test')['ok']);
    }

    // ══ 2. the limits ════════════════════════════════════════════════════════

    public function test_max_uses_holds(): void
    {
        $this->code(['max_uses' => 2, 'used_count' => 2]);

        $r = D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'ada@example.test');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('as many times as it allows', $r['message']);
    }

    public function test_max_per_email_is_counted_from_the_registrations_themselves(): void
    {
        $this->code(['max_per_email' => 1]);

        // The FIRST booking is what consumes this person's single use, and it is counted from
        // the row rather than from a per-person counter — the rows ARE the record of who used
        // a code, so the count cannot drift out of step with them.
        $first = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'ALUMNI20');
        $this->assertTrue($first['ok']);
        $this->assertSame(2000, $first['discount']);

        $again = D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'ada@example.test');
        $this->assertFalse($again['ok']);
        $this->assertStringContainsStringIgnoringCase('already been used with this email', $again['message']);

        // Somebody else is unaffected.
        $this->assertTrue(D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'other@x.test')['ok']);
    }

    public function test_a_cancelled_booking_does_not_lock_somebody_out_of_their_own_code(): void
    {
        $this->code(['max_per_email' => 1]);
        $r = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'ALUMNI20');

        // Their card was declined and the hold was released. Counting that against them would
        // mean a failed payment permanently spent a discount they never received.
        T::cancel((int) $r['id'], 'the checkout was never completed');

        $this->assertTrue(D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'ada@example.test')['ok']);
    }

    public function test_a_code_limited_to_named_tiers_refuses_the_others(): void
    {
        $vip = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => 'vip', 'name' => 'Top table',
            'price_naira' => 380000, 'min_per_order' => 1, 'max_per_order' => 10,
            'is_active' => 1, 'sort_order' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->code(['tier_ids' => json_encode([$this->tierId])]);

        $this->assertTrue(D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'a@x.test')['ok']);

        $onVip = D::apply('ALUMNI20', $this->eventId, $vip, 380000, 'a@x.test');
        $this->assertFalse($onVip['ok'], 'a student code applied to the top table');
        $this->assertStringContainsStringIgnoringCase('does not apply to this ticket type', $onVip['message']);
    }

    public function test_an_expired_or_not_yet_started_window_refuses_the_code(): void
    {
        $this->code(['code' => 'EARLYBIRD', 'ends_at' => Carbon::now()->subDay()->toDateTimeString()]);
        $this->code(['code' => 'LATER', 'starts_at' => Carbon::now()->addDay()->toDateTimeString()]);

        $over = D::apply('EARLYBIRD', $this->eventId, $this->tierId, 10000, 'a@x.test');
        $this->assertFalse($over['ok']);
        $this->assertStringContainsStringIgnoringCase('expired', $over['message']);

        $soon = D::apply('LATER', $this->eventId, $this->tierId, 10000, 'a@x.test');
        $this->assertFalse($soon['ok']);
        $this->assertStringContainsStringIgnoringCase('not usable yet', $soon['message']);
    }

    public function test_an_inactive_code_is_refused_and_says_so(): void
    {
        $this->code(['is_active' => 0]);
        $r = D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'a@x.test');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('no longer active', $r['message']);
    }

    public function test_an_event_code_beats_a_global_one_with_the_same_letters(): void
    {
        // The global one first, so "the last row wins" would pick the wrong one.
        $this->code(['event_id' => null, 'code' => 'STAFF', 'amount' => 5]);
        $this->code(['code' => 'STAFF', 'amount' => 50]);

        $r = D::apply('STAFF', $this->eventId, $this->tierId, 10000, 'a@x.test');
        $this->assertTrue($r['ok']);
        // The event's own, because the more specific configuration is the one somebody set up
        // deliberately for this event.
        $this->assertSame(5000, $r['off']);
    }

    public function test_a_global_code_still_works_on_an_event_that_has_none_of_its_own(): void
    {
        $this->code(['event_id' => null, 'code' => 'STAFF', 'kind' => 'fixed', 'amount' => 1500]);
        $r = D::apply('STAFF', $this->eventId, $this->tierId, 10000, 'a@x.test');
        $this->assertTrue($r['ok']);
        $this->assertSame(1500, $r['off']);
    }

    public function test_an_unknown_code_is_refused_without_pretending_it_worked(): void
    {
        $r = D::apply('NOPE', $this->eventId, $this->tierId, 10000, 'a@x.test');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('not recognised', $r['message']);
    }

    // ══ 3. what reserve() does with it ═══════════════════════════════════════

    public function test_the_amount_on_the_row_is_the_discounted_one_and_the_code_is_recorded(): void
    {
        $this->code(['max_per_email' => 5]);

        $r = T::reserve($this->eventId, $this->tierId, $this->who(), 2, null, null, 'alumni20');

        $this->assertTrue($r['ok']);
        $this->assertSame(20000, $r['gross']);
        $this->assertSame(4000, $r['discount']);
        $this->assertSame(16000, $r['amount']);

        $row = DB::table('gates_event_registrations')->where('id', (int) $r['id'])->first();
        // Written on the ROW, not recomputed later: a code can be edited or deleted after
        // somebody has bought against it, and a receipt that silently restated history would
        // make the money stop adding up.
        $this->assertSame(16000, (int) $row->amount_naira);
        $this->assertSame('ALUMNI20', (string) $row->discount_code);
        $this->assertSame(4000, (int) $row->discount_naira);
    }

    public function test_a_code_that_covers_the_whole_price_confirms_on_the_spot(): void
    {
        $this->code(['kind' => 'fixed', 'amount' => 10000]);

        $r = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'ALUMNI20');

        $this->assertTrue($r['ok']);
        // Nothing to wait for, so nothing is held behind a payment nobody owes.
        $this->assertTrue($r['free']);
        $this->assertNotEmpty($r['ticket_code']);
        $this->assertSame('confirmed',
            (string) DB::table('gates_event_registrations')->where('id', (int) $r['id'])->value('status'));
    }

    public function test_a_bad_code_does_not_refuse_the_booking_it_just_does_not_apply(): void
    {
        // Somebody typing an expired code still wants the ticket at full price far more often
        // than they want their booking refused outright.
        $r = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'GARBAGE');

        $this->assertTrue($r['ok'], 'an unrecognised code refused the whole booking');
        $this->assertSame(10000, $r['amount']);
        $this->assertSame(0, $r['discount']);
        $this->assertStringContainsStringIgnoringCase('not recognised', $r['discount_note']);
    }

    public function test_a_use_is_counted_when_the_seat_is_taken_and_returned_when_it_is_not(): void
    {
        $id = $this->code(['max_uses' => 1, 'max_per_email' => 5]);

        $r = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'ALUMNI20');
        $this->assertTrue($r['ok']);
        $this->assertSame(1, (int) DB::table('gates_event_codes')->where('id', $id)->value('used_count'));

        // Its one use is spent, so nobody else can have it while that hold is live.
        $this->assertFalse(D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'other@x.test')['ok']);

        // The checkout was abandoned. The use goes back with the seat — otherwise a code
        // limited to fifty is exhausted by fifty people who never came.
        T::cancel((int) $r['id'], 'the checkout was never completed');
        $this->assertSame(0, (int) DB::table('gates_event_codes')->where('id', $id)->value('used_count'));
        $this->assertTrue(D::apply('ALUMNI20', $this->eventId, $this->tierId, 10000, 'other@x.test')['ok']);
    }

    public function test_a_second_press_with_a_different_code_is_a_different_purchase(): void
    {
        $this->code(['max_per_email' => 5]);

        $plain = T::reserve($this->eventId, $this->tierId, $this->who(), 1);
        $this->assertTrue($plain['ok']);

        // Same tier, same quantity, same person — but they went away, found a code and came
        // back. Handing them the reference they already have would charge them the old price.
        $withCode = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'ALUMNI20');
        $this->assertTrue($withCode['ok']);
        $this->assertNotSame($plain['reference'], $withCode['reference']);
        $this->assertSame(8000, $withCode['amount']);
    }

    public function test_the_same_press_twice_still_resumes_the_one_hold(): void
    {
        $this->code(['max_per_email' => 5]);

        $a = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'ALUMNI20');
        $b = T::reserve($this->eventId, $this->tierId, $this->who(), 1, null, null, 'ALUMNI20');

        $this->assertSame($a['reference'], $b['reference'], 'a double-press held two lots of seats');
        $this->assertSame(1, DB::table('gates_event_registrations')
            ->where('status', 'pending')->count());
    }

    // ══ 4. the cutoff ════════════════════════════════════════════════════════

    public function test_sales_close_at_stops_registration_before_the_event_date(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['sales_close_at' => Carbon::now()->subHour()->toDateTimeString()]);

        $r = T::reserve($this->eventId, $this->tierId, $this->who());

        $this->assertFalse($r['ok'], 'the catering cutoff did not hold');
        $this->assertSame('closed', $r['state']);
        $this->assertStringContainsStringIgnoringCase('closed on', $r['message']);
    }

    public function test_a_cutoff_still_ahead_lets_a_booking_through(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['sales_close_at' => Carbon::now()->addDays(5)->toDateTimeString()]);

        $this->assertTrue(T::reserve($this->eventId, $this->tierId, $this->who())['ok']);
    }
}
