<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventTicketService as T;
use AfricaGates\Services\EventWaitlist as W;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A queue for the seats that come back.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A WAITLIST NEEDED TESTS OF ITS OWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A waiting list is the easiest feature on this platform to build WRONG in a way nobody
 * notices until real people are involved, because every failure mode is invisible until the
 * list is worked:
 *
 *   1. IT IS OFF BY DEFAULT. A queue an organiser has not thought about is a promise they did
 *      not make, and a queue nobody works costs somebody hope as well as a seat.
 *   2. AN OFFER IS NOT A TICKET. Promotion gives somebody a WINDOW to take a seat, and it
 *      holds that seat while they decide — so promoting must re-count availability on every
 *      single pass, or the same seat is promised to several people.
 *   3. AN OFFER EXPIRES, AND EXPIRING PUTS THEM BACK IN THE QUEUE. Cancelling instead would
 *      punish somebody for missing one email in a bad week.
 *   4. NOBODY IS IN THE QUEUE TWICE. A queue with the same person in it twice is wrong about
 *      its own length, which is the only number it exists to report.
 *   5. EVEN A FREE TIER IS OFFERED RATHER THAN CONFIRMED. Silently registering somebody days
 *      after they asked produces an attendee who does not know they are coming.
 */
final class EventWaitlistTest extends TestCase
{
    private int $eventId = 0;
    private int $tierId  = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        DB::table('gates_event_tiers')->delete();
        DB::table('gates_site_events')->delete();

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'The Gala',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'status' => 'published', 'waitlist_open' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->tierId = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => 'standard', 'name' => 'Standard',
            'price_naira' => 10000, 'capacity' => 1, 'min_per_order' => 1,
            'max_per_order' => 10, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @return array{name:string,email:string,phone:string} */
    private function who(string $email = 'ada@example.test', string $name = 'Ada Obi'): array
    {
        return ['name' => $name, 'email' => $email, 'phone' => '08031234567'];
    }

    private function fillTheTier(): int
    {
        $r = T::reserve($this->eventId, $this->tierId, $this->who('buyer@x.test'));
        $this->assertTrue($r['ok'], 'the fixture could not take the only seat');
        return (int) $r['id'];
    }

    // ══ 1. off by default ════════════════════════════════════════════════════

    public function test_a_list_that_has_not_been_opened_refuses_to_take_names(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['waitlist_open' => 0]);

        $r = W::join($this->eventId, $this->tierId, $this->who());

        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('no waiting list', $r['message']);
        $this->assertSame(0, DB::table('gates_event_registrations')->where('status', 'waitlisted')->count());
    }

    public function test_open_is_read_off_the_event_and_defaults_to_closed(): void
    {
        $this->assertTrue(W::open((object) ['waitlist_open' => 1]));
        $this->assertFalse(W::open((object) ['waitlist_open' => 0]));
        $this->assertFalse(W::open((object) []), 'an event with no setting must not be taking names');
        $this->assertFalse(W::open(null));
    }

    // ══ 2. joining ═══════════════════════════════════════════════════════════

    public function test_joining_stores_a_waitlisted_row_owing_nothing(): void
    {
        $r = W::join($this->eventId, $this->tierId, $this->who());

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['place']);

        $row = DB::table('gates_event_registrations')->where('id', (int) $r['id'])->first();
        $this->assertSame('waitlisted', (string) $row->status);
        // Zero, not the tier price: nothing is owed for standing in a queue, and an amount on
        // a waitlist row would appear in the revenue figure as money nobody has been paid.
        $this->assertSame(0, (int) $row->amount_naira);
        $this->assertNotNull($row->waitlist_at);
        $this->assertNull($row->ticket_code);
    }

    public function test_a_waitlisted_row_does_not_count_as_a_seat(): void
    {
        // The tier has one seat and nobody has bought it. Three people join the queue.
        foreach (['a@x.test', 'b@x.test', 'c@x.test'] as $e) {
            $this->assertTrue(W::join($this->eventId, $this->tierId, $this->who($e))['ok']);
        }
        $this->assertSame(0, T::sold($this->tierId), 'a queue consumed the seats it was waiting for');
        $this->assertSame('open', T::availability(T::tier($this->tierId))['state']);
    }

    public function test_nobody_is_added_to_the_queue_twice(): void
    {
        $first  = W::join($this->eventId, $this->tierId, $this->who());
        $second = W::join($this->eventId, $this->tierId, $this->who());

        // Answered honestly rather than by adding a second row — a queue that is wrong about
        // its own length is wrong about the only number it reports.
        $this->assertTrue($second['ok']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertStringContainsStringIgnoringCase('already on the list', $second['message']);
        $this->assertSame(1, W::length($this->tierId));
    }

    public function test_somebody_who_already_has_a_booking_is_told_so(): void
    {
        T::reserve($this->eventId, $this->tierId, $this->who());

        $r = W::join($this->eventId, $this->tierId, $this->who());

        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('already have a booking', $r['message']);
    }

    public function test_a_phone_number_is_required_because_it_is_how_a_seat_is_offered(): void
    {
        $r = W::join($this->eventId, $this->tierId, ['name' => 'Ada Obi', 'email' => 'ada@x.test', 'phone' => '']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('phone', $r['message']);
    }

    public function test_place_is_the_order_people_joined(): void
    {
        $a = W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        $b = W::join($this->eventId, $this->tierId, $this->who('b@x.test'));
        $c = W::join($this->eventId, $this->tierId, $this->who('c@x.test'));

        $this->assertSame(1, $a['place']);
        $this->assertSame(2, $b['place']);
        $this->assertSame(3, $c['place']);
        $this->assertSame(3, W::length($this->tierId));
    }

    // ══ 3. promoting ═════════════════════════════════════════════════════════

    public function test_nothing_is_promoted_while_the_tier_is_still_full(): void
    {
        $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('waiting@x.test'));

        $r = W::promote($this->tierId);

        $this->assertSame(0, $r['offered']);
        $this->assertSame(1, W::length($this->tierId), 'somebody was promoted into a seat that does not exist');
    }

    public function test_one_returned_seat_produces_exactly_one_offer(): void
    {
        $held = $this->fillTheTier();
        foreach (['a@x.test', 'b@x.test', 'c@x.test'] as $e) {
            W::join($this->eventId, $this->tierId, $this->who($e));
        }

        // The buyer's card was declined and the hold was released. ONE seat came back.
        T::cancel($held, 'the checkout was never completed');

        $r = W::promote($this->tierId);

        // Not three. The offer itself holds the seat, so availability has to be re-counted on
        // every pass — counting once at the top and offering that many would promise one seat
        // to three people, which is the exact failure a waitlist exists to prevent.
        $this->assertSame(1, $r['offered']);
        $this->assertSame(['a@x.test'], $r['notified'], 'the longest-waiting person was not first');
        $this->assertSame(2, W::length($this->tierId));
    }

    public function test_an_offer_is_pending_with_a_deadline_and_holds_the_seat(): void
    {
        $held = $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        T::cancel($held, 'released');

        W::promote($this->tierId);

        $row = DB::table('gates_event_registrations')
            ->where('email', 'a@x.test')->first();
        // `pending`, never `confirmed`. An offer is a window in which somebody MAY take a
        // seat, not a seat handed over while they were not looking.
        $this->assertSame('pending', (string) $row->status);
        $this->assertNotNull($row->offered_at);
        $this->assertNotNull($row->offer_expires_at);
        $this->assertNotNull($row->reference);
        // The seat is theirs while the offer stands, so the tier is full again.
        $this->assertSame(1, T::sold($this->tierId));
        $this->assertSame('sold_out', T::availability(T::tier($this->tierId))['state']);
    }

    public function test_even_a_free_tier_is_offered_rather_than_confirmed(): void
    {
        DB::table('gates_event_tiers')->where('id', $this->tierId)->update(['price_naira' => 0]);
        $held = $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        // release(), not cancel(). A free seat is CONFIRMED on the spot, and cancel() refuses
        // a confirmed row on purpose — every caller of it is a machine. An organiser saying
        // "they emailed to say they cannot come" is the one thing that may withdraw one.
        $this->assertTrue(T::release($held, 'they emailed to say they cannot come')['ok']);

        W::promote($this->tierId);

        // Silently registering somebody days after they asked, without telling them, produces
        // an attendee who does not know they are coming and a seat nobody uses.
        $row = DB::table('gates_event_registrations')->where('email', 'a@x.test')->first();
        $this->assertSame('pending', (string) $row->status);
        $this->assertNull($row->ticket_code);
    }

    public function test_the_events_own_ceiling_stops_a_promotion_even_when_the_tier_has_room(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['capacity' => 1]);
        DB::table('gates_event_tiers')->where('id', $this->tierId)->update(['capacity' => null]);

        // One seat sold on a DIFFERENT tier fills the room.
        $other = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => 'other', 'name' => 'Other',
            'price_naira' => 0, 'min_per_order' => 1, 'max_per_order' => 10,
            'is_active' => 1, 'sort_order' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        T::reserve($this->eventId, $other, $this->who('room@x.test'));

        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        $r = W::promote($this->tierId);

        $this->assertSame(0, $r['offered'], 'a promotion overfilled the room');
    }

    public function test_a_tier_whose_sales_have_closed_promotes_nobody(): void
    {
        DB::table('gates_event_tiers')->where('id', $this->tierId)->update([
            'capacity' => null, 'sale_ends_at' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));

        $this->assertSame(0, W::promote($this->tierId)['offered']);
    }

    // ══ 4. offers nobody took ════════════════════════════════════════════════

    public function test_an_expired_offer_goes_back_to_the_queue_not_to_cancelled(): void
    {
        $held = $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        W::join($this->eventId, $this->tierId, $this->who('b@x.test'));
        T::cancel($held, 'released');
        W::promote($this->tierId);

        // Time passes and they never answered.
        DB::table('gates_event_registrations')->where('email', 'a@x.test')->update([
            'offer_expires_at' => Carbon::now()->subHour()->toDateTimeString(),
            'hold_expires_at'  => Carbon::now()->subHour()->toDateTimeString(),
        ]);

        $this->assertSame(1, W::expireOffers());

        $row = DB::table('gates_event_registrations')->where('email', 'a@x.test')->first();
        // Waitlisted, not cancelled: somebody who missed an email in a bad week has missed one
        // offer, not forfeited their place.
        $this->assertSame('waitlisted', (string) $row->status);
        $this->assertNull($row->offered_at);
        $this->assertNull($row->offer_expires_at);
        $this->assertSame(0, (int) $row->amount_naira);
        $this->assertSame(2, W::length($this->tierId));
    }

    public function test_the_seat_from_an_expired_offer_goes_to_the_next_person(): void
    {
        $held = $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        W::join($this->eventId, $this->tierId, $this->who('b@x.test'));
        T::cancel($held, 'released');
        W::promote($this->tierId);

        DB::table('gates_event_registrations')->where('email', 'a@x.test')->update([
            'offer_expires_at' => Carbon::now()->subHour()->toDateTimeString(),
            'hold_expires_at'  => Carbon::now()->subHour()->toDateTimeString(),
        ]);
        W::expireOffers();

        // a@ went back to the FRONT of the queue they were already at, so they get the next
        // offer as well — they have missed one email, not lost their place.
        $r = W::promote($this->tierId);
        $this->assertSame(1, $r['offered']);
        $this->assertSame(['a@x.test'], $r['notified']);
    }

    public function test_a_live_offer_is_left_alone(): void
    {
        $held = $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        T::cancel($held, 'released');
        W::promote($this->tierId);

        $this->assertSame(0, W::expireOffers(), 'an offer still inside its window was withdrawn');
        $this->assertSame('pending',
            (string) DB::table('gates_event_registrations')->where('email', 'a@x.test')->value('status'));
    }

    public function test_an_ordinary_abandoned_checkout_is_not_treated_as_an_expired_offer(): void
    {
        // A plain pending hold with no `offered_at`. Sweeping it here would move a stranger
        // who never asked for anything into the organiser's waiting list.
        $r = T::reserve($this->eventId, $this->tierId, $this->who('shopper@x.test'));
        DB::table('gates_event_registrations')->where('id', (int) $r['id'])
            ->update(['hold_expires_at' => Carbon::now()->subHour()->toDateTimeString()]);

        $this->assertSame(0, W::expireOffers());
        $this->assertSame('pending',
            (string) DB::table('gates_event_registrations')->where('id', (int) $r['id'])->value('status'));
    }

    // ══ 5. giving a seat back ════════════════════════════════════════════════

    public function test_an_organiser_can_withdraw_a_confirmed_seat_and_the_queue_moves(): void
    {
        DB::table('gates_event_tiers')->where('id', $this->tierId)->update(['price_naira' => 0]);
        $held = $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));

        // Before: the room is full and the queue cannot move, which is the state that made a
        // waiting list useless — seats came back and nothing could give them back.
        $this->assertSame(0, W::promote($this->tierId)['offered']);

        $r = T::release($held, 'they emailed to say they cannot come');
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['refund_due'], 'a free seat should not suggest a refund');

        $this->assertSame(1, W::promote($this->tierId)['offered']);
    }

    public function test_a_released_ticket_code_stops_opening_a_door(): void
    {
        DB::table('gates_event_tiers')->where('id', $this->tierId)->update(['price_naira' => 0]);
        $r = T::reserve($this->eventId, $this->tierId, $this->who('gone@x.test'));
        $code = (string) $r['ticket_code'];
        $this->assertNotSame('', $code);

        T::release((int) $r['id'], 'duplicate booking');

        // A screenshot of the old ticket must not pass at the door.
        $this->assertNull(T::byTicketCode($code));
    }

    public function test_releasing_a_paid_seat_says_out_loud_that_no_refund_was_issued(): void
    {
        $r = T::reserve($this->eventId, $this->tierId, $this->who('payer@x.test'));
        DB::table('gates_event_registrations')->where('id', (int) $r['id'])
            ->update(['status' => 'confirmed', 'ticket_code' => 'AAAA-2222']);

        $out = T::release((int) $r['id'], 'they cannot travel');

        $this->assertTrue($out['ok']);
        // A withdrawal and a refund are different decisions, and one organiser in three keeps
        // a deposit. Saying nothing about the money would be the dangerous half.
        $this->assertTrue($out['refund_due']);
        $this->assertSame(10000, $out['amount']);
        $this->assertStringContainsStringIgnoringCase('has NOT been issued', $out['message']);
    }

    public function test_releasing_the_same_seat_twice_is_refused_rather_than_repeated(): void
    {
        $r = T::reserve($this->eventId, $this->tierId, $this->who('twice@x.test'));
        $this->assertTrue(T::release((int) $r['id'], 'first')['ok']);

        $again = T::release((int) $r['id'], 'second');
        $this->assertFalse($again['ok']);
        $this->assertStringContainsStringIgnoringCase('already cancelled', $again['message']);
    }

    // ══ 6. the organiser's view ══════════════════════════════════════════════

    public function test_the_queue_is_listed_in_the_order_people_joined(): void
    {
        foreach (['a@x.test', 'b@x.test', 'c@x.test'] as $e) {
            W::join($this->eventId, $this->tierId, $this->who($e));
        }

        $queue = W::forEvent($this->eventId);

        $this->assertCount(3, $queue);
        $this->assertSame(['a@x.test', 'b@x.test', 'c@x.test'], array_column($queue, 'email'));
    }

    public function test_offered_rows_leave_the_queue_listing(): void
    {
        $held = $this->fillTheTier();
        W::join($this->eventId, $this->tierId, $this->who('a@x.test'));
        W::join($this->eventId, $this->tierId, $this->who('b@x.test'));
        T::cancel($held, 'released');
        W::promote($this->tierId);

        // a@ is no longer waiting — they are holding an offer, which is a different state with
        // a different action attached, and mixing them would hide the clock on the offer.
        $this->assertSame(['b@x.test'], array_column(W::forEvent($this->eventId), 'email'));
    }
}
