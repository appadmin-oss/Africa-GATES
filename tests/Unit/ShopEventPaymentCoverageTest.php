<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventTicketService, GatewayEventLog, PaymentDestination,
                         PaymentLookup, PaymentReconciler, PaymentService, ShopOrderService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The shop and the event ticketing, as money paths rather than as pages.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG, AND WHY IT WAS INVISIBLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Paystack allows exactly ONE webhook URL per account, so every payment on this platform —
 * vote packs, donations, shop orders, event tickets — arrives at `/pay/webhook`. That handler
 * resolved every reference against `gates_donations`, which holds three of those four. The
 * other two matched nothing and were acknowledged with a 200.
 *
 * A 200 is indistinguishable from success at the far end. So Paystack's dashboard showed a
 * clean run of successful deliveries for payments this platform had thrown away, and the
 * failure was invisible from BOTH sides at once.
 *
 * The shop survived on the cron sweep. Event tickets had no sweep, no admin repair action and
 * no support lookup, so a buyer who paid inside a wallet app and never returned to the callback
 * lost the money AND the seat — the hold aged out of the seat arithmetic and it was resold.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. Every stream can be confirmed by something OTHER than the buyer's browser.
 *   2. An overpayment confirms; an underpayment and a foreign currency never do.
 *   3. A reversal reaches the order or the ticket, not just the donation.
 *   4. A refused subaccount cannot stop anybody from paying.
 *   5. Every delivery leaves a record of what we decided.
 */
final class ShopEventPaymentCoverageTest extends TestCase
{
    private int $eventId = 0;
    private int $tierId  = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
        DB::table('gates_event_registrations')->delete();
        try { DB::table('gates_gateway_events')->delete(); } catch (\Throwable) {}

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Africa GATES Gala', 'slug' => 'gala-cov', 'status' => 'published',
            'event_date' => Carbon::now()->addMonth()->toDateTimeString(),
        ]);
        $this->tierId = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => 'regular', 'name' => 'Regular',
            'price_naira' => 5000, 'capacity' => 50, 'min_per_order' => 1,
            'max_per_order' => 10, 'is_active' => 1, 'sort_order' => 0,
        ]);
    }

    // ── the fake gateway ─────────────────────────────────────────────────────

    /**
     * A PaymentService that answers from a script instead of the network.
     *
     * @param array<string,array<string,mixed>> $answers keyed by reference
     */
    private function gateway(array $answers): PaymentService
    {
        return new class ($answers) extends PaymentService {
            public function __construct(private array $answers) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                return $this->answers[$reference] ?? [
                    'ok' => false, 'status' => 'pending', 'amount' => 0,
                    'currency' => 'NGN', 'meta' => [], 'message' => 'unknown reference',
                ];
            }
        };
    }

    private function paid(int $naira, string $currency = 'NGN'): array
    {
        return ['ok' => true, 'status' => 'success', 'amount' => $naira,
                'currency' => $currency, 'meta' => [], 'gateway_id' => '99', 'gateway_ref' => 'ps_1'];
    }

    private function order(string $ref, int $naira, string $status = 'pending'): int
    {
        return (int) DB::table('gates_orders')->insertGetId([
            'reference' => $ref, 'email' => 'buyer@example.test', 'name' => 'Ada Obi',
            'address' => 'Region: Lagos', 'items_json' => json_encode([]),
            'subtotal_naira' => $naira, 'status' => $status, 'provider' => 'paystack',
            'created_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);
    }

    private function registration(string $ref, int $naira, string $status = 'pending'): int
    {
        return (int) DB::table('gates_event_registrations')->insertGetId([
            'event_id' => $this->eventId, 'tier_id' => $this->tierId, 'tier' => 'Regular',
            'name' => 'Kwame Mensah', 'email' => 'k@example.test', 'phone' => '08030000000',
            'quantity' => 1, 'amount_naira' => $naira, 'reference' => $ref,
            'status' => $status, 'provider' => 'paystack',
            'created_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);
    }

    // ══ 1 · the streams are routable at all ══════════════════════════════════

    /**
     * The reference prefix is what tells the webhook which ledger a payment belongs to, so
     * this mapping is load-bearing for every other test in this file. It is also the mapping
     * that already decides which Paystack subaccount the money settles into — the same
     * function, deliberately, so confirmation and settlement can never disagree about what
     * kind of payment something is.
     */
    public function test_every_reference_this_platform_mints_maps_to_a_stream(): void
    {
        $this->assertSame('shop',   PaymentDestination::streamForReference('AFG-SHP-abc123'));
        $this->assertSame('events', PaymentDestination::streamForReference('AFG-EVT-ABC123'));
        $this->assertSame('votes',  PaymentDestination::streamForReference('AFG-PVOTE-abc'));
        $this->assertSame('votes',  PaymentDestination::streamForReference('AFG-GIVE-abc'));
        $this->assertSame('votes',  PaymentDestination::streamForReference('AFG-deadbeef'));
        $this->assertSame('',       PaymentDestination::streamForReference('something-else'));
    }

    // ══ 2 · confirmation without a browser ═══════════════════════════════════

    /** A shop order confirms from any caller, not only from the callback the browser hits. */
    public function test_a_shop_order_confirms_without_the_browser(): void
    {
        $this->order('AFG-SHP-aaa111', 12000);

        $r = ShopOrderService::confirm('AFG-SHP-aaa111', 'paystack',
                                       $this->gateway(['AFG-SHP-aaa111' => $this->paid(12000)]));

        $this->assertTrue($r['ok']);
        $this->assertSame('confirmed', $r['state']);
        $this->assertSame('paid', (string) DB::table('gates_orders')
            ->where('reference', 'AFG-SHP-aaa111')->value('status'));
    }

    /**
     * And it confirms exactly once, however many callers race.
     *
     * The callback, the webhook and the sweep are all supposed to reach this — that is the
     * whole design — so "confirmed twice" has to be impossible rather than unlikely.
     */
    public function test_a_second_confirmation_of_the_same_order_grants_nothing(): void
    {
        $this->order('AFG-SHP-bbb222', 3000);
        $gw = $this->gateway(['AFG-SHP-bbb222' => $this->paid(3000)]);

        $first  = ShopOrderService::confirm('AFG-SHP-bbb222', 'paystack', $gw);
        $second = ShopOrderService::confirm('AFG-SHP-bbb222', 'paystack', $gw);

        $this->assertSame('confirmed', $first['state']);
        $this->assertSame('already', $second['state'], 'a racing caller re-fulfilled the order');
    }

    /**
     * ── THE FEE-BEARER TOGGLE, WHICH USED TO TAKE THE SHOP OFFLINE ───────────
     *
     * The shop tested the verified amount with `!==`, which refuses an overpayment exactly as
     * hard as an underpayment — and the two are nothing alike. Turning on "customer bears the
     * transaction fee" in the Paystack dashboard adds the fee to every charged amount, so
     * under strict equality EVERY order on the platform arrives a few hundred naira over and
     * is refused. One dashboard toggle, no code change, shop offline.
     *
     * The rest of the platform had already moved to `<`. This was the last copy.
     */
    public function test_paying_more_than_the_order_still_confirms_it(): void
    {
        $this->order('AFG-SHP-ccc333', 10000);

        $r = ShopOrderService::confirm('AFG-SHP-ccc333', 'paystack',
                                       $this->gateway(['AFG-SHP-ccc333' => $this->paid(10150)]));

        $this->assertSame('confirmed', $r['state'],
            'an overpayment was refused — the fee-bearer toggle would take the shop offline');
    }

    /** Short of the price is a partial payment or a tampered reference. Never confirmed. */
    public function test_paying_less_than_the_order_never_confirms_it(): void
    {
        $this->order('AFG-SHP-ddd444', 10000);

        $r = ShopOrderService::confirm('AFG-SHP-ddd444', 'paystack',
                                       $this->gateway(['AFG-SHP-ddd444' => $this->paid(9000)]));

        $this->assertSame('mismatch', $r['state']);
        $this->assertSame('pending', (string) DB::table('gates_orders')
            ->where('reference', 'AFG-SHP-ddd444')->value('status'));
    }

    /**
     * ₦5,000 and $5,000 are the same integer and three orders of magnitude apart. The shop
     * path was the only one of the three that never checked.
     */
    public function test_a_payment_in_another_currency_never_confirms_an_order(): void
    {
        $this->order('AFG-SHP-eee555', 10000);

        $r = ShopOrderService::confirm('AFG-SHP-eee555', 'paystack',
                                       $this->gateway(['AFG-SHP-eee555' => $this->paid(10000, 'USD')]));

        $this->assertSame('mismatch', $r['state']);
    }

    // ══ 3 · the sweep now covers tickets ═════════════════════════════════════

    /**
     * ── THE HEADLINE FAILURE ─────────────────────────────────────────────────
     *
     * `EventTicketService::confirm()` had exactly one caller: the browser callback. This sweep
     * did not know the table existed. So a ticket paid for by somebody whose browser never
     * came back was never issued, by anything, ever.
     */
    public function test_the_sweep_issues_a_ticket_whose_buyer_never_came_back(): void
    {
        $this->registration('AFG-EVT-AAA111', 5000);

        $out = (new PaymentReconciler($this->gateway(['AFG-EVT-AAA111' => $this->paid(5000)])))
            ->run(true, 0, 50);

        $row = DB::table('gates_event_registrations')->where('reference', 'AFG-EVT-AAA111')->first();
        $this->assertSame('confirmed', (string) $row->status);
        $this->assertNotEmpty((string) $row->ticket_code, 'a confirmed ticket with no code opens no door');
        $this->assertGreaterThan(0, $out['confirmed']);
    }

    /** A ticket short-paid is a person's problem, not a cron's. */
    public function test_the_sweep_refuses_to_issue_a_ticket_that_was_short_paid(): void
    {
        $this->registration('AFG-EVT-BBB222', 5000);

        $out = (new PaymentReconciler($this->gateway(['AFG-EVT-BBB222' => $this->paid(2000)])))
            ->run(true, 0, 50);

        $this->assertSame('pending', (string) DB::table('gates_event_registrations')
            ->where('reference', 'AFG-EVT-BBB222')->value('status'));
        $this->assertGreaterThan(0, $out['mismatch']);
    }

    /**
     * And a genuinely abandoned hold leaves the queue — but only after the gateway has been
     * asked and only past the age ceiling. Ask-first, expire-second is the whole reason a
     * transfer settling on day three is confirmed rather than written off.
     */
    public function test_an_abandoned_ticket_hold_is_released_only_after_the_gateway_is_asked(): void
    {
        $id = $this->registration('AFG-EVT-CCC333', 5000);
        DB::table('gates_event_registrations')->where('id', $id)
            ->update(['created_at' => Carbon::now()->subDays(10)->toDateTimeString()]);

        (new PaymentReconciler($this->gateway([])))->run(true, 0, 50);

        $this->assertSame('cancelled', (string) DB::table('gates_event_registrations')
            ->where('id', $id)->value('status'));
    }

    // ══ 4 · money going back reaches the right ledger ════════════════════════

    /**
     * A charged-back ticket used to stay `confirmed`, and a confirmed ticket renders a
     * scannable QR on a page reachable with the reference alone. The bank had taken the money
     * back and the ticket still opened a door.
     */
    public function test_a_reversed_ticket_stops_opening_the_door(): void
    {
        $id = $this->registration('AFG-EVT-DDD444', 5000, 'confirmed');
        DB::table('gates_event_registrations')->where('id', $id)->update(['ticket_code' => 'ABCD-2468']);

        $this->assertTrue(EventTicketService::reverse('AFG-EVT-DDD444', 'charge.dispute.create'));

        $row = DB::table('gates_event_registrations')->where('id', $id)->first();
        $this->assertSame('cancelled', (string) $row->status);
        $this->assertNull($row->ticket_code, 'a reversed ticket kept a code that scans at the door');
    }

    /** Twice is once. A duplicate `refund.processed` delivery must not double-handle. */
    public function test_reversing_a_ticket_twice_does_the_work_once(): void
    {
        $this->registration('AFG-EVT-EEE555', 5000, 'confirmed');

        $this->assertTrue(EventTicketService::reverse('AFG-EVT-EEE555', 'refund.processed'));
        $this->assertFalse(EventTicketService::reverse('AFG-EVT-EEE555', 'refund.processed'));
    }

    /**
     * A refunded shop order used to stay `paid`: stock stayed decremented, the sale kept
     * counting towards "most bought", and the buyer kept the loyalty points. The seller's own
     * records said they had sold something they had been paid nothing for.
     */
    public function test_a_reversed_order_goes_back_to_stock(): void
    {
        $pid = (int) DB::table('gates_products')->insertGetId([
            'slug' => 'tee-cov', 'name' => 'Heritage Tee', 'price_naira' => 9000,
            'stock' => 4, 'is_active' => 1,
        ]);
        $ref = 'AFG-SHP-fff666';
        DB::table('gates_orders')->insert([
            'reference' => $ref, 'email' => 'buyer@example.test', 'name' => 'Ada Obi',
            'address' => 'Lagos', 'subtotal_naira' => 9000, 'status' => 'paid',
            'fulfilment' => 'unfulfilled', 'provider' => 'paystack',
            'items_json' => json_encode([[
                'slug' => 'tee-cov', 'name' => 'Heritage Tee', 'qty' => 2,
                'variant_id' => 0, 'line_total' => 18000,
            ]]),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->assertTrue(ShopOrderService::reverse($ref, 'refund.processed'));

        $this->assertSame('refunded', (string) DB::table('gates_orders')
            ->where('reference', $ref)->value('status'));
        $this->assertSame(6, (int) DB::table('gates_products')->where('id', $pid)->value('stock'),
            'the units did not go back on an order that had not shipped');
    }

    /**
     * A FULFILLED order is different, and deliberately so: the parcel has left, so a chargeback
     * is a loss to write off rather than a stock correction. Restocking it would inflate stock
     * by goods that are not in the building.
     */
    public function test_a_reversed_order_that_already_shipped_is_not_restocked(): void
    {
        $pid = (int) DB::table('gates_products')->insertGetId([
            'slug' => 'mug-cov', 'name' => 'Mug', 'price_naira' => 3000, 'stock' => 4, 'is_active' => 1,
        ]);
        $ref = 'AFG-SHP-ggg777';
        DB::table('gates_orders')->insert([
            'reference' => $ref, 'email' => 'b@example.test', 'name' => 'Ada',
            'address' => 'Lagos', 'subtotal_naira' => 3000, 'status' => 'paid',
            'fulfilment' => 'fulfilled', 'provider' => 'paystack',
            'items_json' => json_encode([[
                'slug' => 'mug-cov', 'name' => 'Mug', 'qty' => 1, 'variant_id' => 0, 'line_total' => 3000,
            ]]),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->assertTrue(ShopOrderService::reverse($ref, 'charge.dispute.create'));
        $this->assertSame(4, (int) DB::table('gates_products')->where('id', $pid)->value('stock'));
    }

    // ══ 4b · who is actually registered, and how full the room is ════════════

    /**
     * ── PEOPLE WERE APPEARING AS REGISTERED WITHOUT PAYING ───────────────────
     *
     * The event page counted attendance with `->where('event_id', …)->count()`. That is a
     * count of ROWS in every status, so an abandoned checkout, a cancelled registration, a
     * refunded ticket and a WAITLIST entry each read as an attendee — and the number could
     * only ever go up, because nothing in the lifecycle deletes a row.
     *
     * `attendingForEvent()` answers the question the page was actually asking.
     */
    public function test_only_paid_seats_count_as_registered(): void
    {
        $this->registration('AFG-EVT-P01', 5000, 'confirmed');   // paid
        $this->registration('AFG-EVT-P02', 5000, 'pending');     // mid-checkout
        $this->registration('AFG-EVT-P03', 5000, 'cancelled');   // gave up / refunded
        $this->registration('AFG-EVT-P04', 5000, 'waitlisted');  // never had a seat

        $this->assertSame(1, EventTicketService::attendingForEvent($this->eventId),
            'somebody who has not paid is being shown as registered');
    }

    /**
     * ── AND A TABLE OF TEN COUNTED AS ONE ────────────────────────────────────
     *
     * The same `count()` counted rows rather than seats, so capacity was measured in
     * bookings. An event with 50 places sold 50 bookings — which, on a tier that allows ten
     * per order, is up to 500 people. The two faults compounded: the count was inflated by
     * people who had not paid AND deflated by everyone who booked more than one seat.
     */
    public function test_a_booking_of_several_seats_counts_as_several(): void
    {
        $id = $this->registration('AFG-EVT-Q01', 50000, 'confirmed');
        DB::table('gates_event_registrations')->where('id', $id)->update(['quantity' => 10]);

        $this->assertSame(10, EventTicketService::attendingForEvent($this->eventId),
            'a table of ten is being counted as one attendee');
        $this->assertSame(10, EventTicketService::soldForEvent($this->eventId),
            'a table of ten is taking one seat out of capacity');
    }

    /**
     * Capacity and attendance are DIFFERENT questions, and the page needs both.
     *
     * A live hold is a seat nobody else can buy, so it counts against capacity. It is not an
     * attendee, so it must not appear in a sentence shown to a human. One number cannot be
     * both, which is why there are two.
     */
    public function test_a_live_hold_takes_a_seat_but_is_not_an_attendee(): void
    {
        $id = $this->registration('AFG-EVT-R01', 5000, 'pending');
        DB::table('gates_event_registrations')->where('id', $id)
            ->update(['hold_expires_at' => Carbon::now()->addMinutes(20)->toDateTimeString()]);

        $this->assertSame(1, EventTicketService::soldForEvent($this->eventId),
            'a live hold is not holding its seat');
        $this->assertSame(0, EventTicketService::attendingForEvent($this->eventId),
            'a hold is being presented as somebody who has registered');
    }

    /** And an expired hold holds nothing — the arithmetic applies expiry without a sweeper. */
    public function test_an_expired_hold_frees_its_seat(): void
    {
        $id = $this->registration('AFG-EVT-S01', 5000, 'pending');
        DB::table('gates_event_registrations')->where('id', $id)
            ->update(['hold_expires_at' => Carbon::now()->subMinute()->toDateTimeString()]);

        $this->assertSame(0, EventTicketService::soldForEvent($this->eventId));
    }

    // ══ 5 · the ledger can see a ticket that has not settled ═════════════════

    /**
     * `GatewayLedger::findLocal()` hardcoded `status: 'registered'` and `settled: true` for
     * every event registration. `disagreements()` opens with `if (!$ours['settled'])` to
     * produce "Paystack took ₦X but our row is still pending" — the one sentence the whole
     * screen exists to write. With `settled` pinned true it could never be written for a
     * ticket, so the instrument that should have found the stranded payments in this file was
     * filing them under AGREED.
     */
    public function test_the_gateway_ledger_reports_an_unsettled_ticket_as_unsettled(): void
    {
        $this->registration('AFG-EVT-HHH888', 5000);          // still pending

        $found = (new \ReflectionClass(\AfricaGates\Services\GatewayLedger::class))
            ->getMethod('findLocal');
        $found->setAccessible(true);
        $row = $found->invoke(new \AfricaGates\Services\GatewayLedger(), 'AFG-EVT-HHH888');

        $this->assertIsArray($row);
        $this->assertSame('pending', $row['status']);
        $this->assertFalse($row['settled'],
            'a pending ticket reported as settled — the reconciliation screen is blind to it');
    }

    // ══ 6 · support can find a ticket ════════════════════════════════════════

    /**
     * A ticket buyer could paste the reference from their own confirmation email and be told
     * no payment matching it was on record, because `PaymentLookup` knew two tables and four
     * prefixes and tickets were in neither list.
     */
    public function test_a_ticket_reference_is_findable(): void
    {
        $this->registration('AFG-EVT-III999', 5000, 'confirmed');

        $hit = PaymentLookup::resolve('AFG-EVT-III999');

        $this->assertTrue($hit['found']);
        $this->assertSame('event ticket', $hit['ledger']);
        $this->assertNotNull($hit['registration']);
        // And it is NOT presented as a shop order, which is what "everything that is not a
        // donation is an order" would have done to it.
        $this->assertNull($hit['order']);
    }

    // ══ 7 · a refused subaccount cannot stop a sale ══════════════════════════

    /**
     * ── THE REPORTED OUTAGE ──────────────────────────────────────────────────
     *
     * `PaymentDestination` validates the SHAPE of a subaccount code. That catches a pasted
     * bank account number and catches nothing else — and a well-formed code belonging to a
     * different Paystack integration, or a deleted one, or one never activated, is refused by
     * Paystack at initialise. Every payment on that stream then died, per-stream, so the shop
     * and the events page could both be dead while votes — the stream somebody is most likely
     * to test — worked perfectly.
     *
     * Its own docblock states the rule: refusing to route is recoverable, refusing to sell is
     * not. This is that rule, implemented.
     */
    public function test_a_subaccount_paystack_refuses_falls_back_instead_of_killing_the_sale(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'paystack_sub_shop'], ['value' => 'ACCT_wrongintegration1']);

        $svc = new class extends PaymentService {
            /** @var list<array<string,mixed>> every payload that went out, in order */
            public array $sent = [];
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
            {
                if (!str_contains($url, '/transaction/initialize')) {
                    return ['ok' => true, 'code' => 200, 'json' => ['status' => true, 'data' => []], 'raw' => ''];
                }
                $this->sent[] = $jsonBody ?? [];
                // Paystack's actual behaviour with a subaccount it does not recognise.
                if (isset($jsonBody['subaccount'])) {
                    return ['ok' => false, 'code' => 400, 'raw' => '',
                            'json' => ['status' => false, 'message' => 'Subaccount not found']];
                }
                return ['ok' => true, 'code' => 200, 'raw' => '',
                        'json' => ['status' => true,
                                   'data' => ['authorization_url' => 'https://checkout.test/x']]];
            }
        };

        $r = $svc->initialize('paystack', 5000, 'b@example.test', 'AFG-SHP-hhh888',
                              'https://site.test/cb');

        $this->assertTrue($r['ok'], 'a bad subaccount stopped a buyer from paying');
        $this->assertSame('https://checkout.test/x', $r['checkout_url']);
        $this->assertCount(2, $svc->sent, 'expected one routed attempt and one unrouted retry');
        $this->assertArrayHasKey('subaccount', $svc->sent[0]);
        $this->assertArrayNotHasKey('subaccount', $svc->sent[1]);

        // The failure is recorded where the settings screen reads it. A fallback nobody is
        // told about is a revenue stream quietly settling into the wrong account for a month.
        $refusal = PaymentDestination::refusal('shop');
        $this->assertIsArray($refusal);
        $this->assertStringContainsString('Subaccount not found', $refusal['why']);

        // And the attribution row is gone: a missing row means "settled to the main account",
        // which is exactly what happened.
        $this->assertSame(0, (int) DB::table('gates_payment_routes')
            ->where('reference', 'AFG-SHP-hhh888')->count());
    }

    /**
     * ── NOTHING ABOUT ROUTING MAY PREVENT A SALE ─────────────────────────────
     *
     * Reading a setting, validating a code and writing an attribution row are three database
     * touches standing between a buyer and a checkout URL — and `initialize()` catches
     * `Throwable` and converts it to "could not reach the payment provider", so ANY throw in
     * that block silently becomes a checkout that will not start. On sites with subaccounts
     * configured, and only those, which is the hardest possible place to notice.
     */
    public function test_a_broken_routing_lookup_still_lets_the_buyer_pay(): void
    {
        $svc = new class extends PaymentService {
            public array $sent = [];
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
            {
                $this->sent[] = $jsonBody ?? [];
                return ['ok' => true, 'code' => 200, 'raw' => '',
                        'json' => ['status' => true,
                                   'data' => ['authorization_url' => 'https://checkout.test/ok']]];
            }
        };

        // The settings table is gone from under it, mid-request. Stands in for every way the
        // routing lookup can fail on a live host: a dropped connection, a locked table, a
        // half-run migration.
        DB::statement('DROP TABLE IF EXISTS gates_settings');

        $r = $svc->initialize('paystack', 5000, 'b@example.test', 'AFG-SHP-kkk111', 'https://s.test/cb');

        $this->assertTrue($r['ok'], 'a failure in the routing lookup stopped a buyer from paying');
        $this->assertSame('https://checkout.test/ok', $r['checkout_url']);
        $this->assertArrayNotHasKey('subaccount', $svc->sent[0]);
    }

    /**
     * And the switch that turns the whole feature off without a deploy.
     *
     * A feature sitting in the path of every payment on the platform needs a fix an operator
     * can apply in a file manager. `off` restores byte-for-byte the request that went out
     * before subaccounts existed, and leaves the configured codes where they are.
     */
    public function test_the_kill_switch_stops_routing_without_clearing_the_codes(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'paystack_sub_shop'], ['value' => 'ACCT_realcode1234']);

        $this->assertNotSame([], PaymentDestination::initFields('shop'));

        $_ENV['PAYSTACK_SUBACCOUNTS'] = 'off';
        try {
            $this->assertSame([], PaymentDestination::initFields('shop'),
                'the kill switch did not stop the subaccount field going out');
            // The code is untouched, so removing the switch brings routing straight back.
            $this->assertSame('ACCT_realcode1234', PaymentDestination::forStream('shop'));
        } finally {
            unset($_ENV['PAYSTACK_SUBACCOUNTS']);
        }

        $this->assertNotSame([], PaymentDestination::initFields('shop'));
    }

    /** When both attempts fail, the subaccount was not the problem — report the real message. */
    public function test_a_failure_that_is_not_the_subaccount_reports_its_own_reason(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'paystack_sub_shop'], ['value' => 'ACCT_wrongintegration1']);

        $svc = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
            {
                return ['ok' => false, 'code' => 401, 'raw' => '',
                        'json' => ['status' => false, 'message' => 'Invalid key']];
            }
        };

        $r = $svc->initialize('paystack', 5000, 'b@example.test', 'AFG-SHP-iii999', 'https://s.test/cb');

        $this->assertFalse($r['ok']);
        $this->assertSame('Invalid key', $r['message'],
            'the retry\'s message masked the real reason the gateway said no');
    }

    // ══ 7b · the hand-off actually hands off ═════════════════════════════════

    /**
     * ── THE BUG THAT SOLD NO TICKETS AND REPORTED NOTHING ────────────────────
     *
     * `remember()` appended `'?ref=' . $reference` unconditionally. Four of its five callers
     * pass a bare path, so it was right for them. Events passes
     * `/events/redirect?event=<slug>` — it wants to bounce a buyer back to the event they
     * were buying from rather than to the list — and appending a second `?` produced
     *
     *     /events/redirect?event=gala?ref=AFG-EVT-ABC123
     *
     * which PHP reads as ONE parameter: `event` => `gala?ref=AFG-EVT-ABC123`.
     *
     * Both halves then fail. `ref` is absent, so `take()` never returns the stored checkout
     * URL and the buyer never reaches Paystack. And the slug now contains a `?`, so it fails
     * the bounce path's own `^[a-z0-9-]+$` guard and even the fallback loses the event —
     * landing the buyer on `/events?pay=restart`, which is precisely what was reported.
     *
     * Nothing throws. Nothing is logged. No 500 is recorded and the gateway is never called,
     * so every diagnostic on the platform reports a healthy system while no ticket can be
     * sold. Asserting on the URL is the only way to see it.
     */
    public function test_a_handoff_path_that_already_has_a_query_string_keeps_its_reference(): void
    {
        $url = \AfricaGates\Services\GatewayHandoff::remember(
            'AFG-EVT-ABC123', 'https://checkout.paystack.com/x',
            'https://site.test/events/redirect?event=gala', 'paystack');

        $this->assertStringContainsString('?event=gala&ref=AFG-EVT-ABC123', $url,
            'a second "?" was appended, so the reference is unreadable and the event slug '
            . 'swallows it');

        // And the round trip: what the server will actually parse out of that URL.
        $q = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('gala', $q['event'] ?? null);
        $this->assertSame('AFG-EVT-ABC123', $q['ref'] ?? null);
    }

    /** The four bare-path callers keep the plain `?ref=` they have always had. */
    public function test_a_bare_handoff_path_still_uses_a_question_mark(): void
    {
        $url = \AfricaGates\Services\GatewayHandoff::remember(
            'AFG-SHP-abc123', 'https://checkout.paystack.com/x',
            'https://site.test/shop/redirect', 'paystack');

        $this->assertStringEndsWith('/shop/redirect?ref=AFG-SHP-abc123', $url);
    }

    /**
     * End to end through the session: remember(), then read the reference back out of the
     * URL exactly as the redirect endpoint does, and take() must return the checkout URL.
     *
     * This is the assertion that was missing. A test that only asks "did it 5xx" passes
     * happily while the buyer is bounced, because a bounce is a 302.
     */
    public function test_the_stored_checkout_url_survives_the_round_trip(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

        $url = \AfricaGates\Services\GatewayHandoff::remember(
            'AFG-EVT-ROUND1', 'https://checkout.paystack.com/live/abc',
            'https://site.test/events/redirect?event=my-gala', 'paystack');

        $q = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('https://checkout.paystack.com/live/abc',
            \AfricaGates\Services\GatewayHandoff::take((string) ($q['ref'] ?? '')),
            'the buyer would have been bounced back to the events list instead of paying');
    }

    // ══ 8 · every delivery leaves a trace ════════════════════════════════════

    /**
     * A 200 is indistinguishable from success at the far end, which is how a discarded
     * webhook looked like a delivered one on Paystack's dashboard. The decision is now ours to
     * show, and `everReceived()` answers the single most useful diagnostic on the platform —
     * "has ANY webhook ever arrived?" — without a gateway call.
     */
    public function test_a_delivery_records_what_the_handler_decided(): void
    {
        $this->assertFalse(GatewayEventLog::everReceived());

        GatewayEventLog::record('paystack', 'charge.success', 'AFG-SHP-jjj000', 'shop',
                                'confirmed', 'Payment received.', 'live');

        $rows = GatewayEventLog::forReference('AFG-SHP-jjj000');
        $this->assertCount(1, $rows);
        $this->assertSame('shop', (string) $rows[0]->stream);
        $this->assertSame('confirmed', (string) $rows[0]->outcome);
        $this->assertSame('live', (string) $rows[0]->domain);
        $this->assertTrue(GatewayEventLog::everReceived());
    }

    /**
     * ── A CHECKOUT THAT CANNOT START MUST SAY WHY, SOMEWHERE READABLE ────────
     *
     * `initialize()` catches everything and returns `ok => false`, which is right for the
     * buyer and means the cause never reaches the error handler — the only thing that writes
     * where an operator without a shell can read. The symptom was visible to everyone and the
     * cause to nobody, while the gateway had usually said something perfectly clear.
     */
    public function test_a_checkout_that_cannot_start_records_the_gateways_own_words(): void
    {
        $log = dirname(__DIR__, 2) . '/var/logs/error-detail.log';
        $kept = is_file($log) ? (string) file_get_contents($log) : null;
        @unlink($log);

        $svc = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $m, string $u, ?array $b, array $h): array
            {
                return ['ok' => false, 'code' => 401, 'raw' => '',
                        'json' => ['status' => false, 'message' => 'Invalid key']];
            }
        };

        try {
            $r = $svc->initialize('paystack', 5000, 'b@example.test', 'AFG-SHP-diag01', 'https://s.test/cb');
            $this->assertFalse($r['ok']);

            $this->assertFileExists($log, 'a failed checkout left no trace an operator can read');
            $written = (string) file_get_contents($log);
            $this->assertStringContainsString('CheckoutCouldNotStart', $written);
            $this->assertStringContainsString('Invalid key', $written,
                'the gateway said exactly what was wrong and we did not write it down');
            $this->assertStringContainsString('AFG-SHP-diag01', $written);
            // The buyer's email identifies a person and buys nothing here: the reference
            // already leads to the order, and the order has the address.
            $this->assertStringNotContainsString('b@example.test', $written);
        } finally {
            if ($kept !== null) { file_put_contents($log, $kept); } else { @unlink($log); }
        }
    }

    /** A rejected signature is not a received webhook — it is somebody knocking. */
    public function test_a_rejected_delivery_does_not_count_as_ever_received(): void
    {
        GatewayEventLog::record('paystack', '', '', '', 'rejected', 'signature did not verify');
        $this->assertFalse(GatewayEventLog::everReceived());
    }
}
