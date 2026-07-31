<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaymentReconciler;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reconciliation: asking the gateway what really happened.
 *
 * ── WHY A FAKE GATEWAY AND NOT A MOCKED SERVICE ──────────────────────────────
 *
 * Every decision here turns on what `verify()` returns, so the tests are written
 * against a stub that answers like a gateway — success, failed, still-pending, an
 * amount that disagrees, an outright API failure. Mocking at a higher level would
 * mean asserting that the reconciler called something, which is not the property that
 * matters. The property that matters is what ends up in the database.
 *
 * ── THE BIAS OF THESE TESTS ──────────────────────────────────────────────────
 *
 * Most of them assert that money does NOT move. Confirming a payment is the easy
 * half; the failures that cost real money are all over-eager:
 *
 *   • confirming on an amount that does not match is either accepting a partial
 *     payment or honouring a tampered reference;
 *   • confirming when the gateway API merely FAILED to answer is reading a network
 *     blip as "the customer paid";
 *   • confirming twice double-credits votes on a live leaderboard;
 *   • and a "check" that quietly writes makes the read-only preview a lie — which
 *     would be the worst of the set, because it is the button an operator presses
 *     precisely when they are not yet sure.
 */
final class PaymentReconcilerTest extends TestCase
{
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();
        try { DB::table('gates_reconciliation_runs')->delete(); } catch (\Throwable) {}

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 940, 'title' => 'P', 'slug' => 'p-940']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 940, 'programme_id' => 940, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 940, 'cycle_id' => 940, 'title' => 'Cat', 'slug' => 'cat-940']);
        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 940, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    /**
     * A PaymentService that answers from a script instead of the network.
     *
     * @param array<string,array{ok:bool,status:string,amount:int}> $answers keyed by reference
     */
    private function gateway(array $answers): PaymentService
    {
        return new class ($answers) extends PaymentService {
            /** @param array<string,array<string,mixed>> $answers */
            public function __construct(private array $answers) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                return $this->answers[$reference]
                    ?? ['ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN', 'meta' => [], 'message' => 'unknown reference'];
            }
        };
    }

    /** A pending paid-vote order, old enough to be swept. */
    private function pendingVoteOrder(string $ref, int $naira, int $votes = 5, int $minutesAgo = 60): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Kwame Mensah', 'donor_email' => 'k@example.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => $votes, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'pending',
            'created_at' => Carbon::now()->subMinutes($minutesAgo)->toDateTimeString(),
        ]);
    }

    private function reconciler(array $answers): PaymentReconciler
    {
        return new PaymentReconciler($this->gateway($answers));
    }

    // ── The happy path, which must also DELIVER ──────────────────────────────

    /**
     * Confirming is not enough. A paid-vote order left 'confirmed' with votes_used = 0
     * is money taken with no votes on the nominee — and this sweep is the backstop
     * that exists FOR the dropped callbacks that cause exactly that.
     */
    public function test_a_genuinely_paid_order_is_confirmed_and_its_votes_minted(): void
    {
        $this->pendingVoteOrder('AFG-R1', 2500, 5);

        $r = $this->reconciler(['AFG-R1' => ['ok' => true, 'status' => 'success', 'amount' => 2500]])
            ->run(apply: true);

        $this->assertSame(1, $r['confirmed']);
        $this->assertSame(2500, $r['naira']);
        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-R1')->value('status'));
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'),
            'the supporter paid for votes and must receive them');
        $this->assertSame(5, (int) DB::table('gates_donations')->where('payment_ref', 'AFG-R1')->value('votes_used'));
    }

    // ── Everything that must NOT move money ──────────────────────────────────

    /** A short payment is a person's problem, not a cron's. */
    public function test_an_amount_mismatch_is_reported_and_never_confirmed(): void
    {
        $this->pendingVoteOrder('AFG-R2', 2500);

        $r = $this->reconciler(['AFG-R2' => ['ok' => true, 'status' => 'success', 'amount' => 500]])
            ->run(apply: true);

        $this->assertSame(1, $r['mismatch']);
        $this->assertSame(0, $r['confirmed']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-R2')->value('status'));
        $this->assertStringContainsString('500', $r['items'][0]['note']);
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    /** A gateway that did not answer has not said "unpaid". */
    public function test_an_api_failure_leaves_the_row_alone_for_the_next_run(): void
    {
        $this->pendingVoteOrder('AFG-R3', 2500);

        $r = $this->reconciler(['AFG-R3' => ['ok' => false, 'status' => 'pending', 'amount' => 0]])
            ->run(apply: true);

        $this->assertSame(0, $r['confirmed']);
        $this->assertSame(1, $r['unverifiable']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-R3')->value('status'));
    }

    public function test_a_payment_still_open_at_the_gateway_is_left_pending(): void
    {
        $this->pendingVoteOrder('AFG-R4', 2500);

        $r = $this->reconciler(['AFG-R4' => ['ok' => true, 'status' => 'pending', 'amount' => 2500]])
            ->run(apply: true);

        $this->assertSame(0, $r['confirmed']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-R4')->value('status'));
    }

    /**
     * THE PREVIEW MUST BE READ-ONLY. It is the button an operator presses precisely
     * when they are not yet sure, so a check that writes would be the worst defect here.
     */
    public function test_check_mode_reports_what_would_happen_and_writes_nothing(): void
    {
        $this->pendingVoteOrder('AFG-R5', 2500, 5);

        $r = $this->reconciler(['AFG-R5' => ['ok' => true, 'status' => 'success', 'amount' => 2500]])
            ->run(apply: false);

        $this->assertFalse($r['applied']);
        $this->assertSame(1, $r['confirmed'], 'it still REPORTS what it would do');
        $this->assertStringContainsString('would be confirmed', $r['items'][0]['note']);

        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-R5')->value('status'),
            'check mode must not touch the database');
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    /** Two runs — a cron and an admin pressing the button — must not double-credit. */
    public function test_running_twice_credits_once(): void
    {
        $this->pendingVoteOrder('AFG-R6', 2500, 5);
        $answers = ['AFG-R6' => ['ok' => true, 'status' => 'success', 'amount' => 2500]];

        $this->reconciler($answers)->run(apply: true);
        $second = $this->reconciler($answers)->run(apply: true);

        $this->assertSame(0, $second['checked'], 'the row is no longer pending, so it is not swept again');
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
        $this->assertSame(1, DB::table('gates_votes')->count());
    }

    /** A checkout from thirty seconds ago is someone mid-flow, not a discrepancy. */
    public function test_a_fresh_pending_row_is_not_swept(): void
    {
        $this->pendingVoteOrder('AFG-R7', 2500, 5, minutesAgo: 0);

        $r = $this->reconciler(['AFG-R7' => ['ok' => true, 'status' => 'success', 'amount' => 2500]])
            ->run(apply: true, minutes: 15);

        $this->assertSame(0, $r['checked'], 'the live callback must not be raced');
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-R7')->value('status'));
    }

    // ── Shop orders ──────────────────────────────────────────────────────────

    public function test_a_paid_shop_order_is_confirmed_and_marked_paid(): void
    {
        DB::table('gates_orders')->insert([
            'reference' => 'SHOP-1', 'email' => 'b@example.test', 'name' => 'Buyer',
            'items_json' => '[]', 'subtotal_naira' => 12000, 'status' => 'pending',
            'provider' => 'paystack',
            'created_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);

        $r = $this->reconciler(['SHOP-1' => ['ok' => true, 'status' => 'success', 'amount' => 12000]])
            ->run(apply: true);

        $this->assertSame(1, $r['confirmed']);
        $this->assertSame('paid', (string) DB::table('gates_orders')->where('reference', 'SHOP-1')->value('status'));
        $this->assertNotNull(DB::table('gates_orders')->where('reference', 'SHOP-1')->value('paid_at'));
    }

    /** A definitive "failed" is worth recording, so the row stops haunting the queue. */
    public function test_an_order_the_gateway_calls_failed_is_marked_failed(): void
    {
        DB::table('gates_orders')->insert([
            'reference' => 'SHOP-2', 'email' => 'b@example.test', 'name' => 'Buyer',
            'items_json' => '[]', 'subtotal_naira' => 8000, 'status' => 'pending',
            'provider' => 'paystack',
            'created_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);

        $r = $this->reconciler(['SHOP-2' => ['ok' => true, 'status' => 'failed', 'amount' => 0]])
            ->run(apply: true);

        $this->assertSame(1, $r['failed']);
        $this->assertSame('failed', (string) DB::table('gates_orders')->where('reference', 'SHOP-2')->value('status'));
    }

    /**
     * An order whose gateway is no longer configured cannot be verified either way.
     * Surfaced for a human rather than skipped silently — money nobody can account
     * for is exactly what this feature exists to make visible.
     */
    public function test_an_order_from_a_disabled_gateway_is_surfaced_not_skipped(): void
    {
        DB::table('gates_orders')->insert([
            'reference' => 'SHOP-3', 'email' => 'b@example.test', 'name' => 'Buyer',
            'items_json' => '[]', 'subtotal_naira' => 9000, 'status' => 'pending',
            'provider' => 'some-dead-gateway',
            'created_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);

        $r = $this->reconciler([])->run(apply: true);

        $this->assertSame(1, $r['unverifiable']);
        $this->assertStringContainsString('not configured', $r['items'][0]['note']);
        $this->assertSame('pending', (string) DB::table('gates_orders')->where('reference', 'SHOP-3')->value('status'));
    }

    // ── The audit trail ──────────────────────────────────────────────────────

    /** A finance correction with no trail is indistinguishable from tampering. */
    public function test_a_run_is_recorded_with_who_did_it_and_what_changed(): void
    {
        $this->pendingVoteOrder('AFG-R8', 2500, 5);

        $r = $this->reconciler(['AFG-R8' => ['ok' => true, 'status' => 'success', 'amount' => 2500]])
            ->run(apply: true);
        PaymentReconciler::log($r, 'admin:7');

        $runs = PaymentReconciler::history();
        $this->assertCount(1, $runs);
        $this->assertSame('admin:7', (string) $runs[0]->actor);
        $this->assertSame('apply', (string) $runs[0]->mode);
        $this->assertSame(1, (int) $runs[0]->confirmed);
        $this->assertSame(2500, (int) $runs[0]->naira);
        $this->assertStringContainsString('AFG-R8', (string) $runs[0]->detail_json);
    }

    public function test_a_check_is_logged_as_a_check(): void
    {
        $this->pendingVoteOrder('AFG-R9', 2500);
        PaymentReconciler::log(
            $this->reconciler(['AFG-R9' => ['ok' => true, 'status' => 'success', 'amount' => 2500]])->run(apply: false),
            'admin:7'
        );

        $this->assertSame('check', (string) PaymentReconciler::history()[0]->mode);
    }
}
