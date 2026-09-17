<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaymentService;
use AfricaGates\Services\RefundService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * How MUCH goes back, and who decides.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *     $this->payments->refund($provider, $ref, (int) $don->amount_naira);
 *
 * Both gateways read a supplied amount as "refund exactly this much". So every
 * refund was really a PARTIAL refund that happened to equal the full charge for as
 * long as our row agreed with what the gateway collected.
 *
 * When it did not agree, the two directions were not symmetrical:
 *
 *   TOO HIGH  refused outright — "exceeds the transaction amount". Loud, classified
 *             permanent, parked for a person. Annoying, and safe.
 *   TOO LOW   SUCCEEDS. The gateway does exactly as instructed. `refund_state`
 *             becomes `refunded`, `refunded_at` is stamped, the buyer is emailed a
 *             figure they did not receive, and nothing anywhere records that
 *             anything was short. Every screen says settled and somebody is out of
 *             pocket.
 *
 * The second is the one worth a test file: it is invisible from our side by
 * construction, and the only person who can detect it is the person who lost the
 * money — which is exactly the shape of failure this platform keeps having to
 * apologise for.
 *
 * ── THE FIX IS ABOUT AUTHORITY, NOT ARITHMETIC ───────────────────────────────
 *
 * The gateway knows what it collected; we only know what we asked for. So the
 * instruction is "return the whole transaction" (no amount) and the gateway's own
 * figure is recorded. Our number stops being an instruction and becomes a claim
 * that can be checked — which is why a mismatch is now escalated rather than
 * absorbed.
 */
final class RefundAmountTest extends TestCase
{
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 98, 'title' => 'P', 'slug' => 'p-980']);
        // A closed cycle: nothing can be minted, so these orders are genuinely owed.
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 980, 'programme_id' => 98, 'year' => 2025,
            'status' => 'results',
            'voting_open'  => Carbon::now()->subDays(40)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDays(20)->toDateTimeString()]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 980, 'cycle_id' => 980,
            'title' => 'Shut', 'slug' => 's-980']);

        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 980, 'name' => 'Race Over', 'status' => 'approved', 'vote_count' => 0]);
    }

    /**
     * A gateway that RECORDS what it was asked to refund, and answers with its own
     * figure — the two halves this file is about.
     *
     * @param int|null $reports what the gateway claims it sent back (null = says nothing)
     */
    private function gateway(?int $reports, ?array &$spy = null): PaymentService
    {
        return new class ($reports, $spy) extends PaymentService {
            public function __construct(private ?int $reports, private ?array &$spy) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                return ['ok' => true, 'status' => 'success', 'amount' => 9600,
                        'currency' => 'NGN', 'meta' => [], 'message' => 'ok'];
            }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            {
                // THE ASSERTION TARGET: what did the caller instruct?
                $this->spy = ['asked' => $amountNaira, 'reference' => $reference];
                return ['ok' => true, 'status' => 'refunded', 'message' => 'ok',
                        'provider_ref' => 'rfnd_1', 'retryable' => false,
                        'amount_naira' => $this->reports];
            }
        };
    }

    private function owedOrder(string $ref, int $charged = 9600): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Feyi', 'donor_email' => 'f@example.test',
            'amount_naira' => $charged, 'tier' => 'paid-vote', 'bonus_votes' => 50, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'confirmed',
            'provider' => 'paystack',
            'confirmed_at' => Carbon::now()->subHours(6)->toDateTimeString(),
            'created_at'   => Carbon::now()->subHours(7)->toDateTimeString(),
        ]);
    }

    private function row(string $ref): object
    {
        return DB::table('gates_donations')->where('payment_ref', $ref)->first();
    }

    // ══ the bug ══════════════════════════════════════════════════════════════

    /**
     * THE REGRESSION. No amount may be handed to the gateway.
     *
     * Passing one is what turned every refund into a partial refund. This asserts on
     * the ARGUMENT rather than on the outcome, because with a cooperative gateway the
     * outcome looks identical either way — which is precisely why the bug survived.
     */
    public function test_the_gateway_is_asked_for_the_whole_transaction(): void
    {
        $spy = null;
        $this->owedOrder('AFG-PVOTE-FULL');

        (new RefundService($this->gateway(9600, $spy), null))->sweep();

        $this->assertNotNull($spy, 'The gateway was never asked at all.');
        $this->assertNull($spy['asked'],
            'An amount was passed, so this is a PARTIAL refund of whatever we happened to '
            . 'have in our own column — the gateway is the authority on what it collected.');
    }

    /** The gateway's own figure is what gets written down. */
    public function test_the_recorded_amount_is_the_gateways_not_ours(): void
    {
        $spy = null;
        $this->owedOrder('AFG-PVOTE-REC', charged: 9600);

        (new RefundService($this->gateway(9600, $spy), null))->sweep();

        $d = $this->row('AFG-PVOTE-REC');
        $this->assertSame('refunded', (string) $d->refund_state);
        $this->assertSame(9600, (int) $d->refund_amount_naira);
    }

    /**
     * A DISAGREEMENT between the charge and the refund must not be absorbed.
     *
     * This is the case the old code could not even represent. The refund still goes
     * through — the money is back, which is what the buyer wanted — but our figure
     * was wrong, and an operator answering a question about this order needs to know
     * that before they quote a number.
     */
    public function test_a_mismatch_is_recorded_rather_than_smoothed_over(): void
    {
        $spy = null;
        $this->owedOrder('AFG-PVOTE-DIFF', charged: 9600);

        // The gateway says it sent back 9,000 — not the 9,600 our row claims.
        (new RefundService($this->gateway(9000, $spy), null))->sweep();

        $d = $this->row('AFG-PVOTE-DIFF');
        $this->assertSame(9600, (int) $d->amount_naira,      'What we charged is untouched.');
        $this->assertSame(9000, (int) $d->refund_amount_naira, 'What came back is its own fact.');
        $this->assertSame('refunded', (string) $d->refund_state,
            'The money IS back — a discrepancy is not a failed refund.');
    }

    /**
     * An unreported figure stays NULL. It is never filled in from our own column.
     *
     * The column's whole purpose is to be evidence of what the gateway said. A
     * restatement of what we asked for, written into the same field, would be
     * indistinguishable from confirmation to anybody reading the row later — and the
     * row is read precisely when somebody is disputing the amount.
     */
    public function test_an_unreported_amount_is_left_null_not_guessed(): void
    {
        $spy = null;
        $this->owedOrder('AFG-PVOTE-QUIET', charged: 9600);

        (new RefundService($this->gateway(null, $spy), null))->sweep();

        $d = $this->row('AFG-PVOTE-QUIET');
        $this->assertSame('refunded', (string) $d->refund_state);
        $this->assertNull($d->refund_amount_naira,
            'Copying amount_naira here would dress our own number up as the gateway\'s.');
    }

    // ══ the gateway layer's own reporting ════════════════════════════════════

    /**
     * Paystack reports in KOBO and Flutterwave in naira. Getting that backwards puts
     * every recorded figure out by a factor of a hundred, in a column people will
     * quote at supporters.
     */
    public function test_paystack_kobo_is_converted_and_flutterwave_naira_is_not(): void
    {
        $svc = new class extends PaymentService {
            public array $reply = [];
            public function isEnabled(string $p): bool { return true; }
            protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
            {
                return ['ok' => true, 'code' => 200, 'json' => $this->reply, 'raw' => ''];
            }
        };

        // Paystack: 960000 kobo → ₦9,600
        $svc->reply = ['status' => true, 'message' => 'ok',
                       'data' => ['status' => 'processed', 'id' => 1, 'amount' => 960000]];
        $this->assertSame(9600, $svc->refund('paystack', 'AFG-X')['amount_naira']);

        // Flutterwave: 9600 naira → ₦9,600 (no division)
        $svc->reply = ['status' => 'success', 'message' => 'ok',
                       'data' => ['status' => 'completed', 'id' => 2, 'amount_refunded' => 9600]];
        $this->assertSame(9600, $svc->refund('flutterwave', 'AFG-X')['amount_naira']);
    }

    /** Every refund reply carries the key, so a caller never has to guess its absence. */
    public function test_every_refusal_shape_still_reports_an_amount_key(): void
    {
        $svc = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
        };

        foreach ([
            'unknown provider' => ['mystery', 'AFG-X'],
            'blank reference'  => ['paystack', '  '],
        ] as $what => [$provider, $ref]) {
            $r = $svc->refund($provider, $ref);
            $this->assertArrayHasKey('amount_naira', $r, "{$what}: key missing");
            $this->assertNull($r['amount_naira'], "{$what}: nothing was refunded, so no figure");
        }
    }

    // ══ the ceiling has to measure real money ═══════════════════════════════

    /**
     * The daily ceiling counts what LEFT, not what we meant to send.
     *
     * A ceiling measured on intent rather than outcome is an estimate, and on the one
     * day the two diverge it errs towards letting more money out than the ceiling
     * permits — which is the opposite of what a ceiling is for.
     */
    public function test_the_daily_ceiling_counts_the_gateways_figures(): void
    {
        // Already refunded today: charged 5,000, gateway actually returned 12,000.
        DB::table('gates_donations')->insert([
            'donor_name' => 'Prior', 'donor_email' => 'p@example.test',
            'amount_naira' => 5000, 'tier' => 'paid-vote', 'bonus_votes' => 10, 'votes_used' => 0,
            'payment_ref' => 'AFG-PVOTE-PRIOR', 'status' => 'confirmed', 'provider' => 'paystack',
            'refund_state' => 'refunded', 'refund_amount_naira' => 12000,
            'refund_requested_at' => Carbon::now()->toDateTimeString(),
            'refunded_at' => Carbon::now()->toDateTimeString(),
            'created_at'  => Carbon::now()->subDay()->toDateTimeString(),
        ]);

        $svc  = new RefundService($this->gateway(1, $spy), null);
        $spend = (new \ReflectionClass($svc))->getMethod('spentToday');
        $spend->setAccessible(true);

        $this->assertSame(12000, (int) $spend->invoke($svc),
            'Counting 5,000 here would understate the day by the exact amount that '
            . 'actually went out over it.');
    }

    /** Rows with no recorded gateway figure still count, at the charge. */
    public function test_older_rows_without_a_gateway_figure_still_count(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Old', 'donor_email' => 'o@example.test',
            'amount_naira' => 7000, 'tier' => 'paid-vote', 'bonus_votes' => 10, 'votes_used' => 0,
            'payment_ref' => 'AFG-PVOTE-OLD', 'status' => 'confirmed', 'provider' => 'paystack',
            'refund_state' => 'refunded', 'refund_amount_naira' => null,
            'refund_requested_at' => Carbon::now()->toDateTimeString(),
            'refunded_at' => Carbon::now()->toDateTimeString(),
            'created_at'  => Carbon::now()->subDay()->toDateTimeString(),
        ]);

        $svc  = new RefundService($this->gateway(1, $spy), null);
        $spend = (new \ReflectionClass($svc))->getMethod('spentToday');
        $spend->setAccessible(true);

        $this->assertSame(7000, (int) $spend->invoke($svc),
            'A pre-migration refund must not vanish from the day\'s total.');
    }
}
