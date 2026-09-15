<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\PaymentController;
use AfricaGates\Services\PaymentReconciler;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The six failure modes an audit of the money paths turned up.
 *
 * Every one of them shares a shape: the platform had a rule that was RIGHT about
 * the case it was written for and silently wrong about the case next to it — and
 * in each, the person who loses is the one who paid.
 *
 *   1. THE QUEUE THAT STARVED. Reconciliation read the pending table oldest-first
 *      with a limit, and abandoned checkouts never left that table. Past the limit,
 *      real payments were permanently out of reach — worse the busier it got.
 *   2. THE REFUND THAT NEVER HAPPENED. Any webhook whose name contained "refund"
 *      claimed back the votes. `refund.failed` means the refund did NOT happen, so
 *      the buyer kept no money AND lost the votes, permanently.
 *   3. THE BUYER WHO PAID TOO MUCH. Amount parity was `!==`, so paying MORE than
 *      the price was refused exactly as hard as paying less, and the row stayed
 *      pending where even the refund sweep could not see it.
 *   4. THE CURRENCY NOBODY CHECKED. ₦5,000 and $5,000 are the same integer.
 *   5. THE GATEWAY WE HAD TO GUESS. Nothing recorded which one took the money, and
 *      the one caller that most needs to know is the one that sends it back.
 *   6. THE DELIVERY THAT STOPPED AT THE STATUS FLIP. Whichever of callback/webhook
 *      lost the confirm race walked away without checking the votes had landed.
 */
final class PaymentAuditTest extends TestCase
{
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 94, 'title' => 'P', 'slug' => 'p-941']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 941, 'programme_id' => 94, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 941, 'cycle_id' => 941, 'title' => 'Cat', 'slug' => 'cat-941']);
        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 941, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    /**
     * A gateway that answers from a script, and COUNTS what it was asked. The count
     * is the assertion for the provider fix: the behaviour that changed is how many
     * gateways get interrogated about one reference.
     *
     * @param array<string,array<string,mixed>> $answers keyed by reference
     */
    private function gateway(array $answers, array $providers = ['paystack']): PaymentService
    {
        return new class ($answers, $providers) extends PaymentService {
            public array $asked = [];
            public function __construct(private array $answers, private array $providers) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return in_array($p, $this->providers, true); }
            public function isEnabled(string $p): bool { return in_array($p, $this->providers, true); }
            public function enabledProviderIds(): array { return $this->providers; }
            public function verify(string $provider, string $reference): array
            {
                $this->asked[] = $provider . ':' . $reference;
                $a = $this->answers[$reference] ?? null;
                if ($a === null || ($a['provider'] ?? $provider) !== $provider) {
                    return ['ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN',
                            'meta' => [], 'message' => 'unknown reference'];
                }
                return ['ok' => true, 'status' => $a['status'], 'amount' => (int) $a['amount'],
                        'currency' => (string) ($a['currency'] ?? 'NGN'), 'meta' => []];
            }
        };
    }

    private function order(string $ref, int $naira, array $extra = [], int $minutesAgo = 60): int
    {
        return (int) DB::table('gates_donations')->insertGetId($extra + [
            'donor_name' => 'Kwame Mensah', 'donor_email' => 'k@example.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'pending',
            'created_at' => Carbon::now()->subMinutes($minutesAgo)->toDateTimeString(),
        ]);
    }

    // ══ 1. THE QUEUE THAT STARVED ════════════════════════════════════════════

    /**
     * THE BUG THAT MATCHED THE COMPLAINT.
     *
     * Three pending rows, a limit of two, and the only one that was actually paid
     * is the newest. Read oldest-first, the sweep spent its whole budget on two
     * abandoned carts and never reached the payment somebody was waiting on — and
     * because abandoned carts are never removed from the queue, it would never
     * reach it on any future run either. The safety net stopped catching anything,
     * silently, and it failed first on the busiest night.
     */
    public function test_a_backlog_of_abandoned_carts_cannot_hide_a_real_payment(): void
    {
        $this->order('AFG-OLD-1', 2500, [], minutesAgo: 60 * 24 * 20);
        $this->order('AFG-OLD-2', 2500, [], minutesAgo: 60 * 24 * 19);
        $this->order('AFG-NEW',   2500, [], minutesAgo: 60);

        $r = (new PaymentReconciler($this->gateway([
            'AFG-NEW' => ['status' => 'success', 'amount' => 2500],
        ])))->run(apply: true, minutes: 15, limit: 2);

        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-NEW')->value('status'),
            'the newest payment must always be inside the first page of the queue');
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
        $this->assertSame(1, $r['confirmed']);
    }

    /** A checkout nobody ever completed has to be allowed to leave the queue. */
    public function test_a_checkout_nobody_completed_is_expired_after_the_ceiling(): void
    {
        $this->order('AFG-DEAD', 2500, [], minutesAgo: 60 * 24 * 5);

        $r = (new PaymentReconciler($this->gateway([])))->run(apply: true);

        $this->assertSame(1, $r['expired']);
        $row = DB::table('gates_donations')->where('payment_ref', 'AFG-DEAD')->first();
        $this->assertSame('failed', (string) $row->status);
        $this->assertNotNull($row->expired_at, 'the tombstone records that TIME decided, not a bank');
    }

    /**
     * The order of operations is the safety property. A bank transfer that settles
     * on day three is asked about BEFORE the age ceiling is applied, so a late
     * payment is confirmed rather than written off.
     */
    public function test_a_late_settlement_on_the_last_day_is_confirmed_not_expired(): void
    {
        $this->order('AFG-LATE', 2500, [], minutesAgo: 60 * 24 * 6);

        $r = (new PaymentReconciler($this->gateway([
            'AFG-LATE' => ['status' => 'success', 'amount' => 2500],
        ])))->run(apply: true);

        $this->assertSame(0, $r['expired']);
        $this->assertSame(1, $r['confirmed']);
        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-LATE')->value('status'));
    }

    /** A young pending row is somebody mid-checkout, not a corpse. */
    public function test_a_recent_unpaid_checkout_is_left_alone(): void
    {
        $this->order('AFG-YOUNG', 2500, [], minutesAgo: 90);

        $r = (new PaymentReconciler($this->gateway([])))->run(apply: true);

        $this->assertSame(0, $r['expired']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-YOUNG')->value('status'));
    }

    // ══ 2. THE REFUND THAT NEVER HAPPENED ════════════════════════════════════

    /** @return array{0:bool,1:?string} [would clawback, event name] */
    private function reversalKind(array $payload): ?string
    {
        $m = new \ReflectionMethod(PaymentController::class, 'webhookReversalKind');
        $m->setAccessible(true);
        return $m->invoke($this->controller(), $payload);
    }

    private function controller(): PaymentController
    {
        return new PaymentController(
            $this->gateway([]),
            new \Slim\Views\Twig(new \Twig\Loader\ArrayLoader(['pages/pay-success.twig' => 'ok'])),
            null
        );
    }

    /**
     * THE WORST OUTCOME THE CODEBASE COULD PRODUCE.
     *
     * `refund.failed` means the gateway tried to give the money back and could not.
     * The buyer still has no refund. Clawing back on it deleted their votes AND
     * stamped `refunded_at`, which blocks re-minting and re-redeeming — so a single
     * webhook saying "nothing happened" took everything, irreversibly.
     */
    public function test_a_failed_refund_does_not_take_anybodys_votes(): void
    {
        foreach (['refund.failed', 'refund.pending', 'refund.processing',
                  'charge.dispute.remind', 'charge.dispute.resolve'] as $event) {
            $this->assertNull($this->reversalKind(['event' => $event]),
                $event . ' is a step, not an outcome — the money has not moved');
        }
    }

    /** And the events that DO mean the money is gone still claw back. */
    public function test_a_settled_refund_or_a_chargeback_still_claws_back(): void
    {
        foreach (['refund.processed', 'charge.refunded', 'charge.dispute.create',
                  'chargeback.created', 'transaction.reversed'] as $event) {
            $this->assertNotNull($this->reversalKind(['event' => $event]),
                $event . ' means the funds are gone and the votes must go with them');
        }
    }

    /** An event a gateway adds later is reported, never guessed at with someone's votes. */
    public function test_an_unrecognised_reversal_shaped_event_does_nothing(): void
    {
        $this->assertNull($this->reversalKind(['event' => 'refund.some.new.thing']));
        $this->assertNull($this->reversalKind(['event' => 'charge.success']));
        $this->assertNull($this->reversalKind([]));
    }

    // ══ 3 & 4. AMOUNT AND CURRENCY ═══════════════════════════════════════════

    /**
     * Paying MORE than the price is not a partial payment and must not be refused.
     *
     * This is not hypothetical: turning on "customer bears the transaction fee" in a
     * gateway dashboard adds the fee to every charged amount. Under strict equality
     * that one toggle refused every payment on the platform — and left each buyer's
     * row `pending`, where the refund sweep (which only reads CONFIRMED orders)
     * could not reach them either.
     */
    public function test_an_overpayment_is_confirmed_and_the_surplus_recorded(): void
    {
        $this->order('AFG-OVER', 2500);

        $r = (new PaymentReconciler($this->gateway([
            'AFG-OVER' => ['status' => 'success', 'amount' => 2537],
        ])))->run(apply: true);

        $this->assertSame(1, $r['confirmed']);
        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-OVER')->value('status'));
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'),
            'they paid for their votes and then some');
    }

    /** Short of the price is still refused, and still by a person rather than a cron. */
    public function test_an_underpayment_is_still_never_confirmed(): void
    {
        $this->order('AFG-SHORT', 2500);

        $r = (new PaymentReconciler($this->gateway([
            'AFG-SHORT' => ['status' => 'success', 'amount' => 500],
        ])))->run(apply: true);

        $this->assertSame(1, $r['mismatch']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-SHORT')->value('status'));
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    /** ₦2,500 and $2,500 are the same integer. The amount check compares integers. */
    public function test_a_payment_in_another_currency_is_not_this_order(): void
    {
        $this->order('AFG-USD', 2500);

        $r = (new PaymentReconciler($this->gateway([
            'AFG-USD' => ['status' => 'success', 'amount' => 2500, 'currency' => 'USD'],
        ])))->run(apply: true);

        $this->assertSame(1, $r['mismatch']);
        $this->assertStringContainsString('USD', $r['items'][0]['note']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-USD')->value('status'));
    }

    // ══ 5. THE GATEWAY WE HAD TO GUESS ═══════════════════════════════════════

    /**
     * With the provider recorded, one reference is one question. Without it, every
     * enabled gateway is asked about every reference — on a live support request,
     * and again on every sweep, each with a fifteen-second timeout.
     */
    public function test_a_recorded_provider_is_asked_first_and_alone(): void
    {
        $this->order('AFG-P1', 2500, ['provider' => 'flutterwave']);
        $gw = $this->gateway(
            ['AFG-P1' => ['status' => 'success', 'amount' => 2500, 'provider' => 'flutterwave']],
            ['paystack', 'flutterwave']
        );

        (new PaymentReconciler($gw))->run(apply: true);

        $this->assertSame(['flutterwave:AFG-P1'], $gw->asked,
            'the gateway that took the money is not second-guessed');
        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-P1')->value('status'));
    }

    /** Orders taken before the column existed still work — the old search remains. */
    public function test_an_order_with_no_recorded_provider_still_asks_everybody(): void
    {
        $this->order('AFG-P2', 2500);
        $gw = $this->gateway(
            ['AFG-P2' => ['status' => 'success', 'amount' => 2500, 'provider' => 'flutterwave']],
            ['paystack', 'flutterwave']
        );

        (new PaymentReconciler($gw))->run(apply: true);

        $this->assertCount(2, $gw->asked);
        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-P2')->value('status'));
    }

    // ══ 6. THE ROW THE SWEEP GAVE UP ON, AND THE BUYER WHO DID NOT ═══════════

    /**
     * Somebody arrives at support holding a bank debit alert for a checkout the
     * sweep wrote off three days ago. The gateway now says it was paid. That row is
     * `failed`, and repair used to refuse to touch anything but `pending` — so the
     * one person with proof got the least help.
     */
    public function test_support_can_still_repair_an_expired_order_the_gateway_says_was_paid(): void
    {
        $this->order('AFG-REVIVE', 2500, ['status' => 'failed', 'provider' => 'paystack'],
            minutesAgo: 60 * 24 * 5);

        $r = (new PaymentReconciler($this->gateway([
            'AFG-REVIVE' => ['status' => 'success', 'amount' => 2500, 'provider' => 'paystack'],
        ])))->reclaim('AFG-REVIVE');

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('CONFIRMED', $r['code']);
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    /**
     * The confirm race has a loser, and the loser used to walk away.
     *
     * Callback and webhook both confirm; only one flips the row. The other took
     * "somebody else got there first" to mean "so the job is done" and returned.
     * It need not be done: mint() can be refused, a process can die between the
     * status flip and the credit, a receipt can throw. And the WEBHOOK is very
     * often the loser — which is exactly the buyer whose browser never came back,
     * so nothing else was ever going to notice.
     *
     * Here the row is confirmed with no votes on it, which is the state that
     * failure leaves behind. A second confirm must finish the job.
     */
    public function test_the_loser_of_the_confirm_race_still_delivers_the_votes(): void
    {
        $this->order('AFG-RACE', 2500, ['status' => 'confirmed', 'votes_used' => 0]);
        $don = DB::table('gates_donations')->where('payment_ref', 'AFG-RACE')->first();

        $m = new \ReflectionMethod(PaymentController::class, 'confirmByReference');
        $m->setAccessible(true);
        $out = $m->invoke($this->controller(), 'paystack', 'AFG-RACE', $don, 'webhook');

        $this->assertSame('already', $out, 'it must not re-confirm or re-verify');
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'),
            'the votes the buyer paid for must land, whichever writer notices first');
        $this->assertSame(5, (int) DB::table('gates_donations')->where('payment_ref', 'AFG-RACE')->value('votes_used'));
    }

    /** And running it again credits nothing more — the claim is what stops that. */
    public function test_delivering_twice_credits_once(): void
    {
        $this->order('AFG-RACE2', 2500, ['status' => 'confirmed', 'votes_used' => 0]);
        $don = DB::table('gates_donations')->where('payment_ref', 'AFG-RACE2')->first();

        $m = new \ReflectionMethod(PaymentController::class, 'confirmByReference');
        $m->setAccessible(true);
        $m->invoke($this->controller(), 'paystack', 'AFG-RACE2', $don, 'webhook');
        $m->invoke($this->controller(), 'paystack', 'AFG-RACE2', $don, 'callback');

        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
        $this->assertSame(1, DB::table('gates_votes')->count());
    }

    /** A confirmed row is never revived by this path — that would be a double credit. */
    public function test_repair_never_touches_a_confirmed_order(): void
    {
        $this->order('AFG-DONE', 2500, ['status' => 'confirmed', 'votes_used' => 5]);

        $r = (new PaymentReconciler($this->gateway([
            'AFG-DONE' => ['status' => 'success', 'amount' => 2500],
        ])))->reclaim('AFG-DONE');

        $this->assertSame('ALREADY', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'),
            'nothing is re-credited');
    }
}
