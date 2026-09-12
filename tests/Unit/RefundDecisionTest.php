<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaymentService;
use AfricaGates\Services\RefundDecision;
use AfricaGates\Services\RefundService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who gets refunded, and — the harder half — who does not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO MISTAKES THIS SITS BETWEEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * PEOPLE WHO PAID were being told their payment "was never completed", because our
 * row said pending and nothing re-asked the bank before the email went out.
 *
 * PEOPLE WHO DID NOT PAY ask for refunds in complete good faith. An abandoned
 * checkout leaves a pending authorisation with most Nigerian banks — the money
 * leaves the available balance and looks exactly like a settled charge, then
 * reverses in 3–10 working days. Paying that out is money leaving for nothing, and
 * a platform that does it once will be asked to do it a thousand times.
 *
 * Trusting the claimant fails the second. Trusting our own row fails the first. So
 * every test here is about the same rule: **the gateway decides, and its answer is
 * written down.**
 *
 * The single most important assertion in the file is
 * `test_an_unreachable_gateway_is_never_read_as_never_paid`. A confident refusal
 * manufactured out of a network timeout is the worst thing this code could produce,
 * because it is indistinguishable from a correct refusal to everyone except the
 * person whose money it is.
 */
final class RefundDecisionTest extends TestCase
{
    private int $nomineeId = 0;
    private int $openCat = 0;
    private int $shutCat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 97, 'title' => 'P', 'slug' => 'p-970']);

        // One cycle still voting, one long closed — the two sides of "can these
        // votes still be delivered", which is what separates OWED from DELIVERABLE.
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 970, 'programme_id' => 97, 'year' => 2026,
            'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(5)->toDateTimeString()]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 971, 'programme_id' => 97, 'year' => 2025,
            'status' => 'results',
            'voting_open' => Carbon::now()->subDays(40)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDays(20)->toDateTimeString()]);

        DB::table('gates_award_categories')->insertOrIgnore(['id' => 970, 'cycle_id' => 970, 'title' => 'Open', 'slug' => 'o-970']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 971, 'cycle_id' => 971, 'title' => 'Shut', 'slug' => 's-971']);
        $this->openCat = 970;
        $this->shutCat = 971;

        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 971, 'name' => 'Race Over', 'status' => 'approved', 'vote_count' => 0]);
    }

    /**
     * A gateway that answers from a script.
     *
     * `$answer === null` means the network failed — `verify()` returns ok=false,
     * which is the UNREACHABLE case and the one that matters most.
     */
    private function gateway(?array $answer): PaymentService
    {
        return new class ($answer) extends PaymentService {
            public function __construct(private ?array $answer) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                if ($this->answer === null) {
                    return ['ok' => false, 'status' => 'pending', 'amount' => 0,
                            'currency' => '', 'meta' => [], 'message' => 'unreachable'];
                }
                return ['ok' => true, 'currency' => 'NGN', 'meta' => []] + $this->answer;
            }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            {
                return ['ok' => true, 'status' => 'refunded', 'message' => 'ok',
                        'provider_ref' => 'rfnd_1', 'retryable' => false];
            }
        };
    }

    private function order(string $ref, array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name' => 'Feyi', 'donor_email' => 'f@example.test',
            'amount_naira' => 9600, 'tier' => 'paid-vote', 'bonus_votes' => 50, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'confirmed',
            'provider' => 'paystack',
            'confirmed_at' => Carbon::now()->subHours(6)->toDateTimeString(),
            'created_at'   => Carbon::now()->subHours(7)->toDateTimeString(),
        ]);
    }

    private function decide(string $ref, ?array $answer): array
    {
        return (new RefundDecision($this->gateway($answer)))->for($ref);
    }

    // ══ the fraud guard ══════════════════════════════════════════════════════

    /**
     * THE CASE THE OPERATOR IS TIRED OF.
     *
     * A checkout started and never finished. The bank placed a hold that looks
     * exactly like a charge, so the buyer asks for a refund in good faith. The
     * gateway is asked, says there is no completed payment, and the answer is a
     * refusal — with the pending-authorisation explanation attached, because
     * without it the refusal reads as an accusation.
     */
    public function test_an_unfinished_checkout_is_refused_with_the_reason_attached(): void
    {
        $this->order('AFG-PVOTE-ABANDON', ['status' => 'pending', 'confirmed_at' => null]);

        $v = $this->decide('AFG-PVOTE-ABANDON', ['status' => 'failed', 'amount' => 0]);

        $this->assertSame('NEVER_PAID', $v['outcome']);
        $this->assertFalse($v['owed'], 'nothing settled to us, so nothing is owed');
        $this->assertStringContainsString('PENDING AUTHORISATION', $v['say'],
            'the refusal must carry the explanation, or it reads as calling them a liar');
        $this->assertStringContainsString('Never imply they are lying', $v['say']);
    }

    /**
     * THE MOST IMPORTANT ASSERTION IN THIS FILE.
     *
     * A network timeout must never become a confident refusal. It is
     * indistinguishable from a correct one to everybody except the person whose
     * money it is.
     */
    public function test_an_unreachable_gateway_is_never_read_as_never_paid(): void
    {
        $this->order('AFG-PVOTE-SILENT');

        $v = $this->decide('AFG-PVOTE-SILENT', null);

        $this->assertSame('UNVERIFIABLE', $v['outcome']);
        $this->assertFalse($v['owed']);
        $this->assertStringContainsString('Do NOT tell them the payment failed', $v['say']);
    }

    /** And a refund cannot be issued on an unanswered check. */
    public function test_no_money_moves_while_the_gateway_is_silent(): void
    {
        $this->order('AFG-PVOTE-SILENT2');

        $r = (new RefundService($this->gateway(null)))
            ->refundByReference('AFG-PVOTE-SILENT2', 'admin:1');

        $this->assertFalse($r['ok']);
        $this->assertSame('UNVERIFIABLE', $r['outcome']);
        $this->assertNull(DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-SILENT2')->value('refunded_at'));
    }

    /** Nor on a payment the gateway says never happened. */
    public function test_no_money_moves_on_a_payment_that_never_settled(): void
    {
        $this->order('AFG-PVOTE-NOPAY');

        $r = (new RefundService($this->gateway(['status' => 'failed', 'amount' => 0])))
            ->refundByReference('AFG-PVOTE-NOPAY', 'admin:1');

        $this->assertFalse($r['ok']);
        $this->assertSame('NEVER_PAID', $r['outcome']);
        $this->assertNull(DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-NOPAY')->value('refunded_at'));
    }

    // ══ the genuine cases ════════════════════════════════════════════════════

    /** Paid, undeliverable, closed cycle — the one case that IS owed. */
    public function test_a_paid_order_that_can_never_deliver_is_owed(): void
    {
        $this->order('AFG-PVOTE-OWED');

        $v = $this->decide('AFG-PVOTE-OWED', ['status' => 'success', 'amount' => 9600]);

        $this->assertSame('OWED', $v['outcome']);
        $this->assertTrue($v['owed']);
    }

    /**
     * MINTING BEATS REFUNDING. It is what the buyer paid for and it costs nothing.
     */
    public function test_a_paid_order_that_can_still_deliver_is_not_refunded(): void
    {
        $live = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->openCat, 'name' => 'Still Running', 'status' => 'approved', 'vote_count' => 0]);
        $this->order('AFG-PVOTE-LIVE', ['intent_nominee_id' => $live]);

        $v = $this->decide('AFG-PVOTE-LIVE', ['status' => 'success', 'amount' => 9600]);

        $this->assertSame('DELIVERABLE', $v['outcome']);
        $this->assertFalse($v['owed'], 'deliver the votes rather than returning the money');
    }

    /** Already delivered — nothing owed, and it points at the checkable page. */
    public function test_a_delivered_order_owes_nothing(): void
    {
        $id = $this->order('AFG-PVOTE-DONE', ['votes_used' => 50]);
        DB::table('gates_votes')->insert([
            'nominee_id' => $this->nomineeId, 'category_id' => $this->shutCat,
            'voter_email_hash' => 'paid:' . $id, 'vote_type' => 'paid', 'weight' => 50,
            'donation_id' => $id, 'voted_at' => Carbon::now()->toDateTimeString()]);

        $v = $this->decide('AFG-PVOTE-DONE', ['status' => 'success', 'amount' => 9600]);

        $this->assertSame('DELIVERED', $v['outcome']);
        $this->assertFalse($v['owed']);
        $this->assertStringContainsString('/vote/verify', $v['say']);
    }

    /** A refund already under way is never started twice. */
    public function test_a_refund_in_flight_is_not_started_again(): void
    {
        $this->order('AFG-PVOTE-FLIGHT', [
            'refund_state' => 'pending',
            'refund_requested_at' => Carbon::now()->subHour()->toDateTimeString()]);

        $v = $this->decide('AFG-PVOTE-FLIGHT', ['status' => 'success', 'amount' => 9600]);

        $this->assertSame('IN_FLIGHT', $v['outcome']);
        $this->assertFalse($v['owed']);
    }

    public function test_an_already_refunded_order_says_so(): void
    {
        $this->order('AFG-PVOTE-BACK', ['refunded_at' => Carbon::now()->toDateTimeString()]);

        $this->assertSame('ALREADY_REFUNDED',
            $this->decide('AFG-PVOTE-BACK', ['status' => 'success', 'amount' => 9600])['outcome']);
    }

    // ══ the evidence ═════════════════════════════════════════════════════════

    /**
     * The decision has to survive being questioned months later, which means the
     * gateway's own answer is recorded rather than read and discarded.
     */
    public function test_the_gateway_answer_is_written_down(): void
    {
        $this->order('AFG-PVOTE-EV');

        $this->decide('AFG-PVOTE-EV', ['status' => 'failed', 'amount' => 0]);

        $row = DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-EV')->first();
        $this->assertSame('failed', (string) $row->gateway_verdict);
        $this->assertNotNull($row->gateway_checked_at);
        $this->assertStringContainsString('paystack', (string) $row->gateway_evidence);
    }

    /** An unreachable check is recorded AS unreachable, not as a failure. */
    public function test_an_unreachable_check_is_recorded_honestly(): void
    {
        $this->order('AFG-PVOTE-EV2');

        $this->decide('AFG-PVOTE-EV2', null);

        $this->assertSame('unreachable',
            (string) DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-EV2')->value('gateway_verdict'));
    }

    // ══ the override ═════════════════════════════════════════════════════════

    /**
     * The override exists because reality produces cases no rule anticipates. It
     * is deliberately expensive, and refusing to build it would only move those
     * cases into somebody's personal banking app where there is no record at all.
     */
    public function test_an_override_needs_a_written_reason(): void
    {
        $this->order('AFG-PVOTE-OV1');

        $r = (new RefundService($this->gateway(['status' => 'failed', 'amount' => 0])))
            ->refundByReference('AFG-PVOTE-OV1', 'admin:1', override: true, why: '');

        $this->assertFalse($r['ok']);
        $this->assertSame('NEED_REASON', $r['outcome']);
    }

    /** With a reason it goes through, and it is marked so it can never be mistaken. */
    public function test_an_override_is_recorded_as_an_override_forever(): void
    {
        $this->order('AFG-PVOTE-OV2');

        $r = (new RefundService($this->gateway(['status' => 'failed', 'amount' => 0])))
            ->refundByReference('AFG-PVOTE-OV2', 'admin:7',
                override: true, why: 'duplicate charge across two references');

        $this->assertTrue($r['ok'], $r['say']);

        $row = DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-OV2')->first();
        $this->assertSame('overridden', (string) $row->refund_state,
            'never indistinguishable from a routine refund');
        $this->assertStringContainsString('OVERRIDE by admin:7', (string) $row->refund_reason);
        $this->assertStringContainsString('duplicate charge', (string) $row->refund_reason);
    }

    // ══ the queue ════════════════════════════════════════════════════════════

    /**
     * "Left for a human" has to mean a human can see it. Everything the automatic
     * path refuses must appear here, with the reason in words.
     */
    public function test_orders_the_sweep_refuses_are_visible_to_a_person(): void
    {
        $this->order('AFG-PVOTE-Q1', ['refund_state' => 'manual',
            'refund_reason' => 'over the per-order ceiling']);
        $this->order('AFG-PVOTE-Q2', ['refund_state' => 'exhausted', 'refund_attempts' => 4,
            'refund_requested_at' => null]);
        $this->order('AFG-PVOTE-Q3');   // plain owed, untouched

        $q  = RefundDecision::queue();
        $by = [];
        foreach ($q as $r) $by[$r['reference']] = $r;

        $this->assertArrayHasKey('AFG-PVOTE-Q1', $by);
        $this->assertSame('over the per-order ceiling', $by['AFG-PVOTE-Q1']['why_manual']);
        $this->assertArrayHasKey('AFG-PVOTE-Q2', $by);
        $this->assertStringContainsString('until we stopped trying', $by['AFG-PVOTE-Q2']['why_manual']);
        $this->assertArrayHasKey('AFG-PVOTE-Q3', $by);
        $this->assertSame('waiting for the automatic sweep', $by['AFG-PVOTE-Q3']['why_manual']);
    }

    /** The queue makes no gateway calls — otherwise it is a page nobody opens. */
    public function test_the_queue_never_calls_a_gateway(): void
    {
        $this->order('AFG-PVOTE-Q4');
        $before = (string) DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-Q4')->value('gateway_checked_at');

        RefundDecision::queue();

        $this->assertSame($before,
            (string) DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-Q4')->value('gateway_checked_at'),
            'reading the queue must not touch any gateway');
    }

    /** An unknown reference is not an oracle for whether one exists. */
    public function test_an_unknown_reference_explains_our_format_instead(): void
    {
        $v = $this->decide('AFG-PVOTE-GHOST', ['status' => 'success', 'amount' => 1]);
        $this->assertSame('NOT_FOUND', $v['outcome']);
        $this->assertStringContainsString('AFG-', $v['say']);
    }

    /**
     * A DELIVERED order does not need a gateway to be called delivered.
     *
     * Reproduced on MySQL: the gateway check ran BEFORE the delivered check, so a
     * fully delivered order whose provider could not be reached came back
     * UNVERIFIABLE. The one screen somebody checks before answering a complaint
     * said "I cannot say either way" about an order that was demonstrably fine —
     * the answer that costs the most trust, because it reads as a platform that
     * cannot account for its own money.
     *
     * The vote rows are on OUR tally. Nothing a provider could say changes that.
     */
    public function test_a_delivered_order_is_delivered_even_with_no_gateway(): void
    {
        $this->minted('AFG-PVOTE-NOGW', ordered: 50, delivered: 50);

        // gateway(null) is the unreachable provider — the condition that used to
        // turn a fine order into "I cannot say either way".
        foreach ([false, true] as $ask) {
            $v = (new RefundDecision($this->gateway(null)))->for('AFG-PVOTE-NOGW', $ask);
            $this->assertSame('DELIVERED', $v['outcome'],
                'askGateway=' . var_export($ask, true) . ': the votes are on the tally, so the '
                . 'question of whether a refund is owed is already answered.');
            $this->assertFalse($v['owed']);
        }
    }

    /**
     * And a PARTIAL delivery must NOT be waved through as delivered.
     *
     * The guard is `delivered >= ordered`. Somebody who paid for fifty votes and
     * has ten is still owed something, and that case has to keep falling through
     * to the gateway rather than being closed early.
     */
    public function test_a_partial_delivery_is_not_treated_as_delivered(): void
    {
        $this->minted('AFG-PVOTE-PART', ordered: 50, delivered: 10);

        $v = (new RefundDecision($this->gateway(null)))->for('AFG-PVOTE-PART', false);
        $this->assertNotSame('DELIVERED', $v['outcome'],
            'Ten votes out of fifty is not delivered.');
    }

    /**
     * A confirmed order with `$delivered` weight already on the tally.
     *
     * Shaped like {@see test_a_delivered_order_owes_nothing} — same closed cycle, so
     * the votes cannot be minted now and the only thing that can make this order
     * DELIVERED is the vote rows themselves.
     */
    private function minted(string $ref, int $ordered, int $delivered): void
    {
        $id = $this->order($ref, ['bonus_votes' => $ordered, 'votes_used' => $delivered]);

        if ($delivered > 0) {
            DB::table('gates_votes')->insert([
                'nominee_id' => $this->nomineeId, 'category_id' => $this->shutCat,
                'voter_email_hash' => 'paid:' . $id, 'vote_type' => 'paid', 'weight' => $delivered,
                'donation_id' => $id, 'voted_at' => Carbon::now()->toDateTimeString()]);
        }
    }
}
