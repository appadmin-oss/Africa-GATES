<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventTicketService as T;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Selling a seat: tiers with their own limits, and money that actually arrives.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE BEFORE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A free RSVP against ONE capacity number for the whole event, and "ticket tiers" as a JSON
 * blob the public page printed as prose. `gates_event_registrations` had carried
 * `amount_naira`, `reference` and `tier` since the day it was created and nothing had ever
 * written any of them — three columns describing a feature that did not exist.
 *
 * So "we cannot set an attendee limit per pricing tier" was a fact about the schema rather
 * than a missing form field: a tier was a paragraph of display text, there was nowhere for a
 * limit to live, and nothing to count against it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FOUR PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. EACH TIER'S LIMIT IS ITS OWN, and the event's limit is checked as well. An organiser
 *      selling 40 early-bird and 40 standard into a hall of 60 needs both to be true.
 *   2. A SEAT IS HELD BY A CHECKOUT AND COMES BACK IF IT IS ABANDONED. Neither half is
 *      optional: no hold oversells the last seat, and no release shrinks the room forever.
 *   3. NO TICKET EXISTS UNTIL THE GATEWAY SAYS SO, and never for less than the price.
 *   4. A CODE-GATED TIER CANNOT BE BOUGHT BY GUESSING ITS ID. Hiding it from a list is not
 *      a control; refusing it is.
 */
final class EventTicketServiceTest extends TestCase
{
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        DB::table('gates_event_tiers')->delete();
        DB::table('gates_site_events')->delete();

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'The Gala',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'status' => 'published', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param array<string,mixed> $over */
    private function tier(array $over = []): int
    {
        return (int) DB::table('gates_event_tiers')->insertGetId(array_merge([
            'event_id' => $this->eventId, 'slug' => 'standard', 'name' => 'Standard',
            'price_naira' => 10000, 'capacity' => null, 'min_per_order' => 1,
            'max_per_order' => 10, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /** @return array{name:string,email:string,phone:string} */
    private function who(string $email = 'ada@example.test'): array
    {
        return ['name' => 'Ada Obi', 'email' => $email, 'phone' => '08031234567'];
    }

    private function gateway(array $byRef): PaymentService
    {
        return new class ($byRef) extends PaymentService {
            public function __construct(private array $byRef) {}
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $p, string $ref): array
            {
                if (!isset($this->byRef[$ref])) return ['ok' => false];
                return ['ok' => true, 'currency' => 'NGN'] + $this->byRef[$ref];
            }
        };
    }

    // ══ 1. each tier's limit is its own ══════════════════════════════════════

    /** THE test in this file. */
    public function test_a_tier_sells_out_on_its_own_limit_while_the_event_still_has_room(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['capacity' => 100]);
        $early = $this->tier(['slug' => 'early', 'name' => 'Early bird', 'price_naira' => 0, 'capacity' => 2]);
        $std   = $this->tier(['slug' => 'std', 'name' => 'Standard', 'price_naira' => 0, 'capacity' => 50]);

        $this->assertTrue(T::reserve($this->eventId, $early, $this->who('a@x.test'))['ok']);
        $this->assertTrue(T::reserve($this->eventId, $early, $this->who('b@x.test'))['ok']);

        $third = T::reserve($this->eventId, $early, $this->who('c@x.test'));
        $this->assertFalse($third['ok'], 'the early-bird limit did not hold');
        $this->assertSame('sold_out', $third['state']);

        // And the other tier is untouched — which is the entire point of a per-tier limit.
        $this->assertTrue(T::reserve($this->eventId, $std, $this->who('c@x.test'))['ok']);
    }

    public function test_the_events_own_capacity_is_still_a_ceiling_over_every_tier(): void
    {
        // 40 + 40 sold into a hall of 3. The tiers say how the room may be divided; the
        // event says how big it is, and neither is derived from the other.
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['capacity' => 3]);
        $a = $this->tier(['slug' => 'a', 'price_naira' => 0, 'capacity' => 40]);
        $b = $this->tier(['slug' => 'b', 'price_naira' => 0, 'capacity' => 40]);

        $this->assertTrue(T::reserve($this->eventId, $a, $this->who('1@x.test'))['ok']);
        $this->assertTrue(T::reserve($this->eventId, $a, $this->who('2@x.test'))['ok']);
        $this->assertTrue(T::reserve($this->eventId, $b, $this->who('3@x.test'))['ok']);

        $full = T::reserve($this->eventId, $b, $this->who('4@x.test'));
        $this->assertFalse($full['ok'], 'the room oversold itself through a second tier');
        $this->assertStringContainsString('filled up', $full['message']);
    }

    /**
     * The one that got away first time. Overselling is prevented by inserting the hold and
     * THEN counting — whichever writer's count comes back over the line withdraws its own
     * row. But a FREE tier is inserted as `confirmed`, and the withdrawal used cancel(),
     * which deliberately refuses to touch a confirmed row because that is normally somebody's
     * paid ticket. So the loser stayed confirmed and a capacity of one admitted two people.
     */
    public function test_a_free_registration_that_loses_the_race_is_withdrawn(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['capacity' => 1]);
        $t = $this->tier(['price_naira' => 0, 'capacity' => null]);

        $this->assertTrue(T::reserve($this->eventId, $t, $this->who('a@x.test'))['ok']);
        $this->assertFalse(T::reserve($this->eventId, $t, $this->who('b@x.test'))['ok']);

        $this->assertSame(1, DB::table('gates_event_registrations')->where('status', 'confirmed')->count(),
            'a capacity of one admitted two people');
        $loser = DB::table('gates_event_registrations')->where('status', 'cancelled')->first();
        $this->assertNotNull($loser);
        // And no ticket code was left behind on it: a cancelled row carrying a valid-looking
        // code is a person at a door with something that scans.
        $this->assertNull($loser->ticket_code);
        $this->assertSame(1, T::soldForEvent($this->eventId));
    }

    public function test_a_tier_with_no_limit_is_not_a_tier_with_a_limit_of_zero(): void
    {
        // NULL and 0 are different intentions, and an intval() would flatten the first into
        // the second — closing a tier that was meant to be unlimited.
        $open = $this->tier(['slug' => 'open', 'price_naira' => 0, 'capacity' => null]);
        $shut = $this->tier(['slug' => 'shut', 'price_naira' => 0, 'capacity' => 0]);

        $this->assertSame('open', T::availability((object) ['id' => $open, 'capacity' => null,
            'sale_starts_at' => null, 'sale_ends_at' => null])['state']);
        $this->assertFalse(T::reserve($this->eventId, $shut, $this->who())['ok']);
        $this->assertTrue(T::reserve($this->eventId, $open, $this->who())['ok']);
    }

    public function test_several_seats_on_one_registration_all_count(): void
    {
        // A person booking for their team should not need four email addresses they do not
        // have — but four seats must cost the tier four seats.
        $t = $this->tier(['price_naira' => 0, 'capacity' => 5, 'max_per_order' => 10]);

        $this->assertTrue(T::reserve($this->eventId, $t, $this->who(), 4)['ok']);
        $this->assertSame(4, T::sold($t));

        $over = T::reserve($this->eventId, $t, $this->who('b@x.test'), 2);
        $this->assertFalse($over['ok']);
        $this->assertStringContainsString('Only one seat is left', $over['message']);
    }

    public function test_a_sale_window_opens_and_closes_the_tier(): void
    {
        $early = $this->tier(['slug' => 'soon', 'price_naira' => 0,
            'sale_starts_at' => Carbon::now()->addDays(2)->toDateTimeString()]);
        $late  = $this->tier(['slug' => 'gone', 'price_naira' => 0,
            'sale_ends_at' => Carbon::now()->subDay()->toDateTimeString()]);

        $a = T::reserve($this->eventId, $early, $this->who());
        $b = T::reserve($this->eventId, $late, $this->who('b@x.test'));

        $this->assertFalse($a['ok']);
        $this->assertSame('early', $a['state']);
        $this->assertFalse($b['ok']);
        $this->assertSame('closed', $b['state']);
        // Each says which kind of "no" it is, because "sales open on 3 March" and "sales
        // closed" send somebody to two different places.
        $this->assertStringContainsString('open on', $a['message']);
        $this->assertStringContainsString('closed on', $b['message']);
    }

    // ══ 2. a hold takes a seat, and gives it back ════════════════════════════

    public function test_a_started_checkout_holds_its_seat(): void
    {
        $t = $this->tier(['capacity' => 1, 'price_naira' => 10000]);

        $first = T::reserve($this->eventId, $t, $this->who('a@x.test'));
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['free']);
        $this->assertSame(1, T::sold($t), 'a checkout in progress left the seat on sale');

        $second = T::reserve($this->eventId, $t, $this->who('b@x.test'));
        $this->assertFalse($second['ok'], 'the last seat was sold twice');
    }

    /**
     * And the other half, which matters just as much: one person closing a tab must not
     * shrink the room permanently.
     */
    public function test_an_abandoned_checkout_gives_the_seat_back(): void
    {
        $t = $this->tier(['capacity' => 1, 'price_naira' => 10000]);
        $r = T::reserve($this->eventId, $t, $this->who('a@x.test'));

        // Their half hour passes. Nothing runs — no cron, no sweeper.
        DB::table('gates_event_registrations')->where('id', (int) $r['id'])
            ->update(['hold_expires_at' => Carbon::now()->subMinute()->toDateTimeString()]);

        $this->assertSame(0, T::sold($t), 'the arithmetic needs a sweeper to be true');
        $this->assertTrue(T::reserve($this->eventId, $t, $this->who('b@x.test'))['ok']);
    }

    public function test_releasing_expired_holds_tidies_the_list_without_changing_the_count(): void
    {
        // Housekeeping, not arithmetic: the seats were already free. What this fixes is an
        // attendee list where a fortnight of abandoned checkouts reads as "half my room has
        // not paid".
        $t = $this->tier(['price_naira' => 10000]);
        $r = T::reserve($this->eventId, $t, $this->who());
        DB::table('gates_event_registrations')->where('id', (int) $r['id'])
            ->update(['hold_expires_at' => Carbon::now()->subHour()->toDateTimeString()]);

        $before = T::sold($t);
        $this->assertSame(1, T::releaseExpired());
        $this->assertSame($before, T::sold($t));
        $this->assertSame('cancelled', (string) DB::table('gates_event_registrations')
            ->where('id', (int) $r['id'])->value('status'));
    }

    public function test_a_confirmed_seat_is_never_released(): void
    {
        $t = $this->tier(['price_naira' => 10000]);
        $r = T::reserve($this->eventId, $t, $this->who());
        T::confirm((string) $r['reference'], $this->gateway([
            (string) $r['reference'] => ['status' => 'success', 'amount' => 10000]]));

        DB::table('gates_event_registrations')->where('id', (int) $r['id'])
            ->update(['hold_expires_at' => Carbon::now()->subHour()->toDateTimeString()]);

        $this->assertSame(0, T::releaseExpired(), 'a paid ticket was cancelled by housekeeping');
        $this->assertSame(1, T::sold($t));
    }

    /**
     * Pressing the button twice must not hold two lots of seats out of a limited tier —
     * that is how a tier of 40 sells out at 20 buyers.
     */
    public function test_pressing_the_button_twice_resumes_rather_than_double_holds(): void
    {
        $t = $this->tier(['capacity' => 4, 'price_naira' => 10000]);

        $first  = T::reserve($this->eventId, $t, $this->who(), 2);
        $second = T::reserve($this->eventId, $t, $this->who(), 2);

        $this->assertTrue($second['ok']);
        $this->assertSame($first['reference'], $second['reference'], 'a second hold was created');
        $this->assertTrue($second['resumed'] ?? false);
        $this->assertSame(2, T::sold($t));
    }

    /** But a genuinely different purchase is a different hold. */
    public function test_a_different_quantity_is_a_different_purchase(): void
    {
        $t = $this->tier(['capacity' => 10, 'price_naira' => 10000]);
        $a = T::reserve($this->eventId, $t, $this->who(), 1);
        $b = T::reserve($this->eventId, $t, $this->who(), 3);

        $this->assertNotSame($a['reference'], $b['reference']);
        $this->assertSame(4, T::sold($t));
    }

    /**
     * The one that used to be impossible. `UNIQUE(event_id, email)` meant an abandoned
     * checkout locked somebody out of the event permanently — a silent failure that hit
     * exactly the people trying to pay.
     */
    public function test_somebody_can_book_again_after_abandoning_a_checkout(): void
    {
        $t = $this->tier(['price_naira' => 10000]);
        $first = T::reserve($this->eventId, $t, $this->who());
        T::cancel((int) $first['id'], 'changed their mind');

        $again = T::reserve($this->eventId, $t, $this->who());

        $this->assertTrue($again['ok'], 'an abandoned checkout locked them out of the event');
        $this->assertNotSame($first['reference'], $again['reference']);
    }

    // ══ 3. no ticket until the gateway says so ══════════════════════════════

    public function test_a_free_tier_is_confirmed_on_the_spot_with_a_ticket(): void
    {
        $t = $this->tier(['price_naira' => 0]);
        $r = T::reserve($this->eventId, $t, $this->who());

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['free']);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $r['ticket_code']);
        $this->assertSame('confirmed', (string) DB::table('gates_event_registrations')
            ->where('id', (int) $r['id'])->value('status'));
    }

    public function test_a_paid_tier_has_no_ticket_until_the_gateway_confirms_it(): void
    {
        $t = $this->tier(['price_naira' => 25000]);
        $r = T::reserve($this->eventId, $t, $this->who());

        $this->assertNull(DB::table('gates_event_registrations')
            ->where('id', (int) $r['id'])->value('ticket_code'),
            'a ticket existed before anybody had paid for it');
        $this->assertSame(25000, (int) $r['amount']);

        $c = T::confirm((string) $r['reference'], $this->gateway([
            (string) $r['reference'] => ['status' => 'success', 'amount' => 25000]]));

        $this->assertTrue($c['ok']);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $c['ticket_code']);
        $row = DB::table('gates_event_registrations')->where('id', (int) $r['id'])->first();
        $this->assertSame('confirmed', (string) $row->status);
        $this->assertSame('paystack', (string) $row->provider);
        $this->assertNull($row->hold_expires_at, 'a paid ticket still looks like it expires');
    }

    public function test_the_price_is_the_tiers_and_not_the_forms(): void
    {
        // Nothing about the amount comes from the browser. A posted price would be the
        // oldest bug in online payments.
        $t = $this->tier(['price_naira' => 25000, 'max_per_order' => 5]);
        $r = T::reserve($this->eventId, $t, $this->who(), 2);

        $this->assertSame(50000, (int) $r['amount']);
        $this->assertSame(50000, (int) DB::table('gates_event_registrations')
            ->where('id', (int) $r['id'])->value('amount_naira'));
    }

    public function test_an_underpayment_issues_no_ticket(): void
    {
        $t = $this->tier(['price_naira' => 25000]);
        $r = T::reserve($this->eventId, $t, $this->who());

        $c = T::confirm((string) $r['reference'], $this->gateway([
            (string) $r['reference'] => ['status' => 'success', 'amount' => 5000]]));

        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('₦5,000', $c['message']);
        $row = DB::table('gates_event_registrations')->where('id', (int) $r['id'])->first();
        $this->assertSame('pending', (string) $row->status);
        $this->assertNull($row->ticket_code);
    }

    public function test_a_payment_in_another_currency_issues_no_ticket(): void
    {
        // ₦25,000 and $25,000 are the same integer and three orders of magnitude apart.
        $t = $this->tier(['price_naira' => 25000]);
        $r = T::reserve($this->eventId, $t, $this->who());

        $gw = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $p, string $ref): array
            {
                return ['ok' => true, 'status' => 'success', 'amount' => 25000, 'currency' => 'USD'];
            }
        };

        $this->assertFalse(T::confirm((string) $r['reference'], $gw)['ok']);
        $this->assertSame('pending', (string) DB::table('gates_event_registrations')
            ->where('id', (int) $r['id'])->value('status'));
    }

    public function test_confirming_twice_issues_one_ticket(): void
    {
        // A webhook and a browser callback arriving together is the ordinary case, not the
        // edge case.
        $t = $this->tier(['price_naira' => 10000]);
        $r = T::reserve($this->eventId, $t, $this->who());
        $gw = $this->gateway([(string) $r['reference'] => ['status' => 'success', 'amount' => 10000]]);

        $a = T::confirm((string) $r['reference'], $gw);
        $b = T::confirm((string) $r['reference'], $gw);

        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertTrue($b['already']);
        $this->assertSame($a['ticket_code'], $b['ticket_code'], 'the ticket code changed under them');
        $this->assertSame(1, T::sold($t));
    }

    public function test_an_unpaid_reference_is_not_confirmed(): void
    {
        $t = $this->tier(['price_naira' => 10000]);
        $r = T::reserve($this->eventId, $t, $this->who());

        $c = T::confirm((string) $r['reference'], $this->gateway([]));

        $this->assertFalse($c['ok']);
        $this->assertSame('pending', (string) DB::table('gates_event_registrations')
            ->where('id', (int) $r['id'])->value('status'));
    }

    /** The reference is what the reconciler and the gateway ledger key off. */
    public function test_every_paid_registration_stores_a_reference_the_ledger_can_find(): void
    {
        $t = $this->tier(['price_naira' => 10000]);
        $r = T::reserve($this->eventId, $t, $this->who());

        $ref = (string) DB::table('gates_event_registrations')->where('id', (int) $r['id'])->value('reference');
        $this->assertStringStartsWith(T::REF_PREFIX, $ref);
        $this->assertNotNull(T::byReference($ref));
    }

    // ══ 4. a code-gated tier ════════════════════════════════════════════════

    public function test_a_code_gated_tier_is_hidden_until_the_code_is_given(): void
    {
        $this->tier(['slug' => 'public', 'name' => 'Standard', 'price_naira' => 0]);
        $this->tier(['slug' => 'press', 'name' => 'Press', 'price_naira' => 0, 'access_code' => 'PRESS26']);

        $open = array_column(T::tiers($this->eventId), 'slug');
        $with = array_column(T::tiers($this->eventId, 'press26'), 'slug');

        $this->assertSame(['public'], $open);
        $this->assertContains('press', $with, 'the code is case-sensitive, which it should not be');
    }

    /** Hiding it from a list is presentation. Refusing it is the control. */
    public function test_a_code_gated_tier_cannot_be_bought_by_guessing_its_id(): void
    {
        $press = $this->tier(['slug' => 'press', 'price_naira' => 0, 'access_code' => 'PRESS26']);

        $sneak = T::reserve($this->eventId, $press, $this->who());
        $this->assertFalse($sneak['ok']);
        $this->assertStringContainsString('access code', $sneak['message']);

        $this->assertTrue(T::reserve($this->eventId, $press, $this->who(), 1, 'PRESS26')['ok']);
    }

    // ══ 5. the ordinary refusals ════════════════════════════════════════════

    public function test_a_past_event_takes_no_registrations(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['event_date' => Carbon::now()->subDay()->toDateTimeString()]);
        $t = $this->tier(['price_naira' => 0]);

        $this->assertFalse(T::reserve($this->eventId, $t, $this->who())['ok']);
    }

    public function test_a_draft_event_takes_no_registrations(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['status' => 'draft']);
        $t = $this->tier(['price_naira' => 0]);

        $this->assertFalse(T::reserve($this->eventId, $t, $this->who())['ok']);
    }

    public function test_a_tier_belonging_to_another_event_is_refused(): void
    {
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'other', 'title' => 'Other',
            'event_date' => Carbon::now()->addDays(9)->toDateTimeString(), 'status' => 'published',
        ]);
        $theirs = $this->tier(['event_id' => $other, 'slug' => 'theirs', 'price_naira' => 0]);

        $this->assertFalse(T::reserve($this->eventId, $theirs, $this->who())['ok']);
    }

    public function test_a_phone_number_is_required_because_an_organiser_has_to_reach_them(): void
    {
        $t = $this->tier(['price_naira' => 0]);

        $r = T::reserve($this->eventId, $t, ['name' => 'Ada', 'email' => 'a@x.test', 'phone' => '']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('phone', strtolower($r['message']));
    }

    public function test_the_per_order_maximum_holds(): void
    {
        $t = $this->tier(['price_naira' => 0, 'max_per_order' => 2]);

        $this->assertFalse(T::reserve($this->eventId, $t, $this->who(), 3)['ok']);
        $this->assertTrue(T::reserve($this->eventId, $t, $this->who(), 2)['ok']);
    }

    public function test_ticket_codes_avoid_the_characters_people_misread(): void
    {
        // The alphabet is the part of a ticket code that decides whether a door queue moves:
        // 0/O, 1/I and 5/S read the same over a bad phone line.
        for ($i = 0; $i < 40; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[01OIS5Z]/', T::freshCode());
        }
    }

    // ══ 6. what the organiser sees ══════════════════════════════════════════

    public function test_the_summary_counts_money_only_once_it_is_confirmed(): void
    {
        $t = $this->tier(['price_naira' => 10000, 'capacity' => 10]);
        $paid = T::reserve($this->eventId, $t, $this->who('a@x.test'));
        T::confirm((string) $paid['reference'], $this->gateway([
            (string) $paid['reference'] => ['status' => 'success', 'amount' => 10000]]));
        T::reserve($this->eventId, $t, $this->who('b@x.test'));   // still mid-checkout

        $s = T::summary($this->eventId);

        $this->assertSame(10000, $s['revenue'], 'a checkout in progress was counted as money');
        $this->assertSame(1, $s['pending']);
        $this->assertSame(2, $s['sold'], 'the held seat is not counted as taken');
        $this->assertCount(1, $s['tiers']);
        $this->assertSame(8, $s['tiers'][0]['left']);
    }

    public function test_the_attendee_list_can_be_filtered_by_status(): void
    {
        $t = $this->tier(['price_naira' => 0]);
        T::reserve($this->eventId, $t, $this->who('a@x.test'));
        $held = $this->tier(['slug' => 'paid', 'price_naira' => 5000]);
        T::reserve($this->eventId, $held, $this->who('b@x.test'));

        $this->assertCount(2, T::attendees($this->eventId));
        $this->assertCount(1, T::attendees($this->eventId, 'confirmed'));
        $this->assertCount(1, T::attendees($this->eventId, 'pending'));
    }
}
