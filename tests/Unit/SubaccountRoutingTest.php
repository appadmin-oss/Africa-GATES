<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{PaymentDestination, PaymentService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Does the money actually go where the settings screen says it goes?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS ASSERTED ON THE WIRE AND NOWHERE ELSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every other test of this feature checks the pieces: does `code()` accept this string, does
 * `streamForReference()` map that prefix. None of them checks the only thing that decides
 * where a naira lands, which is the JSON body of `POST /transaction/initialize`.
 *
 * That gap is not theoretical. A mis-set `subaccount` does not fail loudly — Paystack accepts
 * the payment and settles it somewhere else, and the platform's records go on describing the
 * settlement it intended rather than the one that happened. The bank is then the only place
 * the truth exists, which is precisely the situation subaccounts were introduced to end.
 *
 * So these tests intercept the transport and read the payload.
 */
final class SubaccountRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'like', 'paystack_%')->delete();
        try { DB::table('gates_payment_routes')->delete(); } catch (\Throwable) {}
    }

    /** A PaymentService that records the outgoing payload instead of sending it. */
    private function spy(): PaymentService
    {
        return new class extends PaymentService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
            {
                if (str_contains($url, '/transaction/initialize')) {
                    $this->sent[] = $jsonBody ?? [];
                    return ['ok' => true, 'code' => 200, 'raw' => '',
                            'json' => ['status' => true,
                                       'data' => ['authorization_url' => 'https://checkout.paystack.com/x']]];
                }
                // The settings screen's verification call.
                return ['ok' => true, 'code' => 200, 'raw' => '',
                        'json' => ['status' => true,
                                   'data' => ['business_name' => 'Test Sub', 'settlement_bank' => 'GTB',
                                              'active' => true]]];
            }
        };
    }

    private function route(string $stream, string $code, string $bearer = 'account'): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'paystack_sub_' . $stream],
                                                    ['value' => $code]);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'paystack_bearer_' . $stream],
                                                    ['value' => $bearer]);
    }

    // ══ 1 · the right subaccount for the right stream ════════════════════════

    /**
     * Three streams, three subaccounts, and each reference must reach its own.
     *
     * Asserted together rather than one per test on purpose: the failure this guards against
     * is a CROSSED wire — shop money settling into the events account — and that is only
     * visible when all three are configured differently at once. One stream tested in
     * isolation passes even when the mapping is reversed.
     */
    public function test_each_stream_reaches_its_own_subaccount(): void
    {
        $this->route('shop',   'ACCT_shop000001');
        $this->route('events', 'ACCT_events00001');
        $this->route('votes',  'ACCT_votes00001');

        $svc = $this->spy();
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-SHP-aaa111',   'https://s.test/cb');
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-EVT-BBB222',   'https://s.test/cb');
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-PVOTE-ccc333', 'https://s.test/cb');
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-GIVE-ddd444',  'https://s.test/cb');
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-eeee5555',     'https://s.test/cb');

        $this->assertSame('ACCT_shop000001',  $svc->sent[0]['subaccount'] ?? null, 'shop order');
        $this->assertSame('ACCT_events00001', $svc->sent[1]['subaccount'] ?? null, 'event ticket');
        $this->assertSame('ACCT_votes00001',  $svc->sent[2]['subaccount'] ?? null, 'paid vote');
        $this->assertSame('ACCT_votes00001',  $svc->sent[3]['subaccount'] ?? null, 'donation');
        $this->assertSame('ACCT_votes00001',  $svc->sent[4]['subaccount'] ?? null, 'generic vote pack');
    }

    /**
     * An unrouted stream sends NO subaccount field — not an empty one.
     *
     * Rule 1 of the feature: an operator who never opens that screen must not discover their
     * settlements have changed. `'subaccount' => ''` is not the same request as no field at
     * all, and Paystack would reject it.
     */
    public function test_an_unrouted_stream_sends_no_subaccount_field(): void
    {
        $this->route('shop', 'ACCT_shop000001');       // shop only

        $svc = $this->spy();
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-EVT-BBB222', 'https://s.test/cb');

        $this->assertArrayNotHasKey('subaccount', $svc->sent[0],
            'an unrouted stream is sending a subaccount field');
        $this->assertArrayNotHasKey('bearer', $svc->sent[0]);
    }

    // ══ 2 · who pays Paystack's cut ══════════════════════════════════════════

    /**
     * `bearer` rides ALONG WITH a subaccount and never alone.
     *
     * Paystack rejects `bearer` on its own, and a rejected initialise is a buyer who cannot
     * pay — so the one thing this must never do is send the fee choice for a stream that is
     * not routed.
     */
    public function test_the_fee_bearer_is_only_sent_beside_a_subaccount(): void
    {
        // Configured to 'subaccount' but with NO code — the state an operator leaves behind
        // by clearing the code field and not the dropdown.
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'paystack_bearer_shop'],
                                                    ['value' => 'subaccount']);

        $svc = $this->spy();
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-SHP-aaa111', 'https://s.test/cb');

        $this->assertArrayNotHasKey('bearer', $svc->sent[0],
            'bearer was sent without a subaccount — Paystack refuses that outright');
    }

    /** Chosen explicitly, it goes out; left at the default, it is omitted so Paystack's own default applies. */
    public function test_the_fee_bearer_goes_out_when_the_subaccount_is_to_pay_it(): void
    {
        $this->route('shop',   'ACCT_shop000001', 'subaccount');
        $this->route('events', 'ACCT_events00001', 'account');

        $svc = $this->spy();
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-SHP-aaa111', 'https://s.test/cb');
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-EVT-BBB222', 'https://s.test/cb');

        $this->assertSame('subaccount', $svc->sent[0]['bearer'] ?? null);
        $this->assertArrayNotHasKey('bearer', $svc->sent[1],
            'the main account bearing the fee is Paystack\'s default and needs no field');
    }

    // ══ 3 · the amount and currency are untouched by routing ═════════════════

    /**
     * Routing must not change what the buyer is charged.
     *
     * Worth an explicit assertion because `$payload += $route` merges arrays, and a `+=` whose
     * right-hand side ever gained an `amount` key would silently keep the left — or, written
     * the other way round, silently replace it. The buyer's total is the one number in this
     * request that must be beyond doubt.
     */
    public function test_routing_does_not_touch_the_amount_or_the_currency(): void
    {
        $this->route('shop', 'ACCT_shop000001', 'subaccount');

        $svc = $this->spy();
        $svc->initialize('paystack', 7350, 'a@b.test', 'AFG-SHP-aaa111', 'https://s.test/cb');

        $this->assertSame(735000, $svc->sent[0]['amount'] ?? null, 'kobo conversion changed');
        $this->assertSame('NGN', $svc->sent[0]['currency'] ?? null);
        $this->assertSame('AFG-SHP-aaa111', $svc->sent[0]['reference'] ?? null);
    }

    // ══ 4 · what was used is written on the payment ══════════════════════════

    /**
     * The attribution is recorded from what was SENT, not from what is configured now.
     *
     * Settings change. An order settled to last quarter's subaccount that silently
     * re-attributed itself to this quarter's would make the platform's history stop matching
     * the bank's — and the bank is the party that cannot be argued with.
     */
    public function test_the_subaccount_used_is_recorded_against_the_reference(): void
    {
        $this->route('events', 'ACCT_events00001', 'subaccount');

        $svc = $this->spy();
        $svc->initialize('paystack', 12000, 'a@b.test', 'AFG-EVT-BBB222', 'https://s.test/cb');

        $row = DB::table('gates_payment_routes')->where('reference', 'AFG-EVT-BBB222')->first();
        $this->assertNotNull($row, 'nothing was recorded about where this payment settles');
        $this->assertSame('events',           (string) $row->revenue_stream);
        $this->assertSame('ACCT_events00001', (string) $row->subaccount);
        $this->assertSame('subaccount',       (string) $row->fee_bearer);
        $this->assertSame(12000,              (int) $row->amount_naira);
    }

    /** Re-initialising the same reference updates the row — two rows would double a total. */
    public function test_a_retried_checkout_does_not_record_a_second_attribution(): void
    {
        $this->route('shop', 'ACCT_shop000001');

        $svc = $this->spy();
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-SHP-aaa111', 'https://s.test/cb');
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-SHP-aaa111', 'https://s.test/cb');

        $this->assertSame(1, (int) DB::table('gates_payment_routes')
            ->where('reference', 'AFG-SHP-aaa111')->count());
    }

    /** An unrouted stream records NOTHING — a missing row means "the main account". */
    public function test_an_unrouted_payment_records_no_attribution(): void
    {
        $svc = $this->spy();
        $svc->initialize('paystack', 5000, 'a@b.test', 'AFG-SHP-aaa111', 'https://s.test/cb');

        $this->assertSame(0, (int) DB::table('gates_payment_routes')
            ->where('reference', 'AFG-SHP-aaa111')->count());
    }

    // ══ 5 · a code the gateway will not accept never reaches a buyer ═════════

    /**
     * The settings screen asks Paystack, and a code it does not recognise is refused there —
     * which is the only moment a person is present to read the answer.
     */
    public function test_a_code_paystack_rejects_is_refused_at_save(): void
    {
        $svc = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $m, string $u, ?array $b, array $h): array
            {
                return ['ok' => false, 'code' => 404, 'raw' => '',
                        'json' => ['status' => false, 'message' => 'Subaccount not found']];
            }
        };

        $r = PaymentDestination::save(['shop' => 'ACCT_doesnotexist1'], ['shop' => 'account'], $svc);

        $this->assertArrayHasKey('shop', $r['refused']);
        $this->assertStringContainsString('Subaccount not found', $r['refused']['shop']);
        $this->assertSame('', PaymentDestination::forStream('shop'),
            'a code Paystack rejects was stored anyway');
    }

    /** An inactive one resolves but cannot receive a split, so it is refused too. */
    public function test_an_inactive_subaccount_is_refused_at_save(): void
    {
        $svc = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $m, string $u, ?array $b, array $h): array
            {
                return ['ok' => true, 'code' => 200, 'raw' => '',
                        'json' => ['status' => true,
                                   'data' => ['business_name' => 'Dormant', 'active' => false]]];
            }
        };

        $r = PaymentDestination::save(['events' => 'ACCT_dormant00001'], [], $svc);

        $this->assertArrayHasKey('events', $r['refused']);
        $this->assertStringContainsString('not active', $r['refused']['events']);
    }

    // ══ 6 · and it is REFLECTED where somebody can read it ═══════════════════

    /**
     * ── THE FEATURE HAD NO OUTPUT ────────────────────────────────────────────
     *
     * `gates_payment_routes` was written on every routed payment and read by nothing — not a
     * screen, a service or an export. So the question subaccounts exist to answer, "how much
     * of this is ticket money", still could not be answered from this platform, which is the
     * exact situation the feature's own design note calls the problem.
     *
     * The routing worked. The reflection did not exist.
     */
    public function test_settled_money_is_reported_per_stream(): void
    {
        $this->route('shop',   'ACCT_shop000001');
        $this->route('events', 'ACCT_events00001');

        $svc = $this->spy();
        $svc->initialize('paystack', 9000,  'a@b.test', 'AFG-SHP-paid001', 'https://s.test/cb');
        $svc->initialize('paystack', 12000, 'a@b.test', 'AFG-EVT-PAID01',  'https://s.test/cb');
        // Started and abandoned: a route row exists, and it must NOT count as income.
        $svc->initialize('paystack', 99000, 'a@b.test', 'AFG-SHP-ghost01', 'https://s.test/cb');

        DB::table('gates_orders')->insert([
            'reference' => 'AFG-SHP-paid001', 'email' => 'a@b.test', 'name' => 'Ada',
            'address' => 'Lagos', 'items_json' => '[]', 'subtotal_naira' => 9000,
            'status' => 'paid', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('gates_orders')->insert([
            'reference' => 'AFG-SHP-ghost01', 'email' => 'a@b.test', 'name' => 'Ada',
            'address' => 'Lagos', 'items_json' => '[]', 'subtotal_naira' => 99000,
            'status' => 'pending', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $eid = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'S', 'slug' => 'settle-ev', 'status' => 'published',
            'event_date' => Carbon::now()->addMonth()->toDateTimeString(),
        ]);
        DB::table('gates_event_registrations')->insert([
            'event_id' => $eid, 'name' => 'K', 'email' => 'k@b.test', 'phone' => '0803',
            'quantity' => 1, 'amount_naira' => 12000, 'reference' => 'AFG-EVT-PAID01',
            'status' => 'confirmed', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $by = [];
        foreach (\AfricaGates\Admin\Services\FinanceService::settlement() as $r) {
            $by[$r['stream']] = $r;
        }

        $this->assertSame(9000,  $by['shop']['routed_naira'],
            'the abandoned checkout was counted as settled income');
        $this->assertSame(1,     $by['shop']['routed_count']);
        $this->assertSame('ACCT_shop000001', $by['shop']['configured']);
        $this->assertSame(9000,  $by['shop']['subaccounts']['ACCT_shop000001'] ?? 0);

        $this->assertSame(12000, $by['events']['routed_naira']);
        $this->assertSame(0,     $by['events']['main_naira']);
    }

    /**
     * A confirmed payment with no route row settled to the MAIN account — which is what an
     * absent row means, and what the fallback leaves behind when Paystack refuses a
     * subaccount. Reported separately, because for a routed stream it is the visible symptom
     * of a misconfiguration that is otherwise silent.
     */
    public function test_a_payment_with_no_attribution_is_reported_against_the_main_account(): void
    {
        DB::table('gates_orders')->insert([
            'reference' => 'AFG-SHP-noroute', 'email' => 'a@b.test', 'name' => 'Ada',
            'address' => 'Lagos', 'items_json' => '[]', 'subtotal_naira' => 4000,
            'status' => 'paid', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $by = [];
        foreach (\AfricaGates\Admin\Services\FinanceService::settlement() as $r) {
            $by[$r['stream']] = $r;
        }

        $this->assertSame(4000, $by['shop']['main_naira']);
        $this->assertSame(0,    $by['shop']['routed_naira']);
    }

    /**
     * And the Finance page must not count an unpaid ticket as revenue.
     *
     * `bySource()` said "no status column, so a row IS the payment" — true when tickets were
     * free RSVPs, false from the day they could be bought. It counted abandoned checkouts and
     * cancelled bookings as income, on the screen most likely to be read against a bank
     * statement.
     */
    public function test_an_unpaid_ticket_is_not_revenue(): void
    {
        $eid = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'R', 'slug' => 'rev-ev', 'status' => 'published',
            'event_date' => Carbon::now()->addMonth()->toDateTimeString(),
        ]);
        foreach ([['AFG-EVT-REV001', 'confirmed', 5000], ['AFG-EVT-REV002', 'pending', 80000],
                  ['AFG-EVT-REV003', 'cancelled', 90000]] as [$ref, $status, $naira]) {
            DB::table('gates_event_registrations')->insert([
                'event_id' => $eid, 'name' => 'K', 'email' => $ref . '@b.test', 'phone' => '0803',
                'quantity' => 1, 'amount_naira' => $naira, 'reference' => $ref,
                'status' => $status, 'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        }

        $by = \AfricaGates\Admin\Services\FinanceService::bySource();

        $this->assertSame(5000, $by['event']['gross'],
            'unpaid and cancelled tickets are being reported as money the organisation has');
        $this->assertSame(1, $by['event']['count']);
    }

    /**
     * But an UNREACHABLE gateway is not a refusal.
     *
     * An outbound blip at the moment somebody presses Save must not reject a configuration
     * that is perfectly good — that would turn a network hiccup into a settings screen that
     * cannot be used.
     */
    public function test_a_gateway_that_cannot_be_reached_does_not_refuse_a_good_code(): void
    {
        $svc = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            protected function request(string $m, string $u, ?array $b, array $h): array
            {
                throw new \RuntimeException('cURL transport error: timed out');
            }
        };

        $r = PaymentDestination::save(['shop' => 'ACCT_perfectlyfine1'], [], $svc);

        $this->assertSame([], $r['refused'], 'a timeout was treated as a bad code');
        $this->assertSame('ACCT_perfectlyfine1', PaymentDestination::forStream('shop'));
    }
}
