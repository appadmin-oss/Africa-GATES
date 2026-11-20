<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{PaymentTriage, PaymentService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The repair path, which decides whether votes appear for money we were told about.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE WAS WRITTEN FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There are two routes from a payment to minted votes:
 *
 *   deliverOwed()  → gatewayAgrees() → mint()      guarded
 *   askGateway()   → repair()        → mint()      NOT guarded
 *
 * `gatewayAgrees()` refuses an amount below what the order asked for, and its own
 * docblock says why: mint() issues `bonus_votes` off our stored row and never
 * looks at what was actually paid, so a success for a fraction of the order
 * delivers the FULL quantity. That "silently inflates a result rather than failing
 * loudly".
 *
 * `askGateway()` checked only `status === 'success'`. The same underpayment the
 * other path refused, this one confirmed — and the operator screen presented it as
 * a verified charge. Two doors to one room, a lock on one of them.
 *
 * The second half is timing. Verification happened in an earlier request and
 * travelled here through the session; between the two clicks a payment can be
 * refunded at the gateway. The stash is the right way to carry the DECISION and
 * the wrong evidence to confirm money on, so repair() now asks again as it acts.
 */
final class PaymentTriageRepairTest extends TestCase
{
    /** A gateway we control, standing in for Paystack. */
    private function gateway(array $byRef): PaymentService
    {
        return new class($byRef) extends PaymentService {
            /** @param array<string,array{status:string,amount:int}> $byRef */
            public function __construct(private array $byRef) {}
            public function isEnabled(string $provider): bool { return $provider === 'paystack'; }
            public function verify(string $provider, string $reference): array
            {
                if (!isset($this->byRef[$reference])) return ['ok' => false];
                return ['ok' => true] + $this->byRef[$reference];
            }
        };
    }

    private function order(string $ref, int $naira, int $votes = 5, string $status = 'pending'): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Buyer', 'donor_email' => 'buyer@example.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => $votes,
            'votes_used' => 0, 'intent_nominee_id' => 1, 'payment_ref' => $ref,
            'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function row(int $id): object
    {
        return (object) (array) DB::table('gates_donations')->where('id', $id)->first();
    }

    // ── askGateway ───────────────────────────────────────────────────────────

    /** THE BUG. A part-payment must not be reported as a clean charge. */
    public function test_an_underpaid_order_is_not_counted_as_charged(): void
    {
        $full  = $this->order('AFG-FULL', 50000);
        $short = $this->order('AFG-SHORT', 50000);

        $t = new PaymentTriage($this->gateway([
            'AFG-FULL'  => ['status' => 'success', 'amount' => 50000],
            'AFG-SHORT' => ['status' => 'success', 'amount' => 1000],
        ]));

        $r = $t->askGateway([$this->row($full), $this->row($short)]);

        $this->assertCount(1, $r['charged']);
        $this->assertSame('AFG-FULL', (string) $r['charged'][0]['order']->payment_ref);

        // Reported, not silently dropped into `clean` — somebody really did pay
        // something, and that needs a human rather than a bucket.
        $this->assertCount(1, $r['underpaid']);
        $this->assertSame('AFG-SHORT', (string) $r['underpaid'][0]['order']->payment_ref);
        $this->assertSame(1000, $r['underpaid'][0]['amount']);
        $this->assertSame(0, $r['clean']);
    }

    /** Overpayment is fine, exactly as it is on the live confirm path. */
    public function test_an_overpaid_order_is_still_charged(): void
    {
        $id = $this->order('AFG-OVER', 5000);
        $t  = new PaymentTriage($this->gateway(['AFG-OVER' => ['status' => 'success', 'amount' => 6000]]));

        $this->assertCount(1, $t->askGateway([$this->row($id)])['charged']);
    }

    public function test_an_abandoned_checkout_is_clean(): void
    {
        $id = $this->order('AFG-ABANDONED', 5000);
        $t  = new PaymentTriage($this->gateway([]));

        $r = $t->askGateway([$this->row($id)]);
        $this->assertSame(1, $r['clean']);
        $this->assertSame([], $r['charged']);
        $this->assertSame([], $r['underpaid']);
    }

    // ── repair ───────────────────────────────────────────────────────────────

    public function test_a_genuinely_charged_order_is_confirmed(): void
    {
        $id = $this->order('AFG-GOOD', 5000);
        $t  = new PaymentTriage($this->gateway(['AFG-GOOD' => ['status' => 'success', 'amount' => 5000]]));

        $r = $t->repair([['order' => $this->row($id), 'provider' => 'paystack', 'amount' => 5000]]);

        $this->assertSame(1, $r['fixed']);
        $this->assertSame([], $r['refused']);
        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('id', $id)->value('status'));
    }

    /**
     * THE SECOND HALF. Even handed a list that says "charged", repair asks again —
     * so an underpayment that slipped through an older stash cannot be confirmed.
     */
    public function test_repair_refuses_an_underpayment_even_when_told_it_was_charged(): void
    {
        $id = $this->order('AFG-SHORT', 50000);
        $t  = new PaymentTriage($this->gateway(['AFG-SHORT' => ['status' => 'success', 'amount' => 1000]]));

        // Exactly what the old askGateway would have stashed.
        $r = $t->repair([['order' => $this->row($id), 'provider' => 'paystack', 'amount' => 50000]]);

        $this->assertSame(0, $r['fixed']);
        $this->assertCount(1, $r['refused']);
        $this->assertStringContainsString('AFG-SHORT', $r['refused'][0]);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('id', $id)->value('status'),
            'the order stays pending rather than being confirmed on a part-payment');
    }

    /**
     * The staleness window: verified before lunch, refunded at the gateway during
     * it, repaired after. The stash still says charged; the gateway does not.
     */
    public function test_repair_refuses_a_payment_reversed_since_it_was_verified(): void
    {
        $id = $this->order('AFG-REVERSED', 5000);
        $t  = new PaymentTriage($this->gateway(['AFG-REVERSED' => ['status' => 'reversed', 'amount' => 5000]]));

        $r = $t->repair([['order' => $this->row($id), 'provider' => 'paystack', 'amount' => 5000]]);

        $this->assertSame(0, $r['fixed']);
        $this->assertCount(1, $r['refused']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('id', $id)->value('status'));
    }

    /** And with no gateway reachable at all, nothing is confirmed on faith. */
    public function test_repair_confirms_nothing_when_the_gateway_cannot_be_asked(): void
    {
        $id = $this->order('AFG-NOGATE', 5000);

        $t = new PaymentTriage(new class extends PaymentService {
            public function __construct() {}
            public function isEnabled(string $provider): bool { return false; }
            public function verify(string $provider, string $reference): array { return ['ok' => false]; }
        });

        $r = $t->repair([['order' => $this->row($id), 'provider' => 'paystack', 'amount' => 5000]]);

        $this->assertSame(0, $r['fixed']);
        $this->assertCount(1, $r['refused']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('id', $id)->value('status'));
    }

    /** An order a webhook confirmed in the meantime is left alone, not double-run. */
    public function test_an_order_confirmed_by_something_else_is_not_touched(): void
    {
        $id = $this->order('AFG-RACED', 5000);
        $t  = new PaymentTriage($this->gateway(['AFG-RACED' => ['status' => 'success', 'amount' => 5000]]));
        $stale = $this->row($id);

        DB::table('gates_donations')->where('id', $id)->update(['status' => 'confirmed']);

        $r = $t->repair([['order' => $stale, 'provider' => 'paystack', 'amount' => 5000]]);

        $this->assertSame(0, $r['fixed'], 'the conditional update claimed nothing');
    }
}
