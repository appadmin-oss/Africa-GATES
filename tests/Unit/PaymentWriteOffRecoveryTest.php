<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaymentReconciler;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\PaymentTriage;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The money the gateway ledger could see and nothing else could touch.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE WAS WRITTEN FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every sweeper on this platform filters on `status = 'pending'` — the reconciler, the
 * triage repair, the refund sweep. And the reconciler itself writes `status = 'failed'`
 * to a pending row that nobody could verify for three days, so it can leave the queue
 * instead of crowding it. That fixed the queue and made the row unreachable by every one
 * of those sweepers, permanently.
 *
 * The three-day write-off is a GUESS. It is taken at the one moment the evidence was
 * missing, and the reasons a gateway cannot be reached are systemic rather than per-row:
 * a rotated key, a provider switched off in the environment, an outbound firewall. Any of
 * those writes off every payment in the window — the successful ones included — and once
 * the key is fixed, nothing goes back to look.
 *
 * Which is the reported symptom exactly. `GatewayLedger` walks PAYSTACK's list and reports
 * "Paystack took ₦12,000 and our row says failed". Triage and the reconciler walk OURS,
 * see `failed`, and file it under "nobody was charged" without asking. The votes are never
 * minted and no screen explains why.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * SO THE FOUR PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. A ROW WE WROTE OFF IS NOT A ROW THE GATEWAY DECLINED. `expired_at` is what tells
 *      the guess from the verdict, and only the guess gets re-asked.
 *   2. THE REPAIR PATH CAN ACTUALLY TOUCH IT. Widening the queue without widening the
 *      conditional update would list the money and refuse to fix it.
 *   3. THE RECOVERY PASS CONVERGES. `recovery_checked_at` is stamped on an ANSWER, never
 *      on an attempt — otherwise the single chance is burned by the very outage that
 *      caused the write-off.
 *   4. IT STILL CANNOT MINT ON THIN EVIDENCE. Underpayment and the wrong currency are
 *      refused here exactly as everywhere else.
 */
final class PaymentWriteOffRecoveryTest extends TestCase
{
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        DB::table('gates_votes')->delete();

        // A real open ballot, because the last test in this file asserts that a recovered
        // order actually credits votes — and mint() refuses on a closed cycle for its own
        // good reasons. A test that skipped this would be asserting the refusal.
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 960, 'title' => 'P', 'slug' => 'p-960']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 960, 'programme_id' => 960, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 960, 'cycle_id' => 960, 'title' => 'Cat', 'slug' => 'cat-960']);
        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 960, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    /** A gateway we control, standing in for Paystack. */
    private function gateway(array $byRef, bool $reachable = true): PaymentService
    {
        return new class($byRef, $reachable) extends PaymentService {
            public int $calls = 0;
            public function __construct(private array $byRef, private bool $reachable) {}
            public function isEnabled(string $provider): bool { return $provider === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function isKnownProvider(string $provider): bool { return $provider === 'paystack'; }
            public function verify(string $provider, string $reference): array
            {
                $this->calls++;
                // An unreachable gateway is `ok => false`. That is a different answer from
                // "not paid", and conflating them is the whole bug.
                if (!$this->reachable)              return ['ok' => false, 'message' => 'timeout'];
                if (!isset($this->byRef[$reference])) return ['ok' => false, 'message' => 'unknown reference'];
                return ['ok' => true, 'currency' => 'NGN'] + $this->byRef[$reference];
            }
        };
    }

    /** A row this platform gave up on: status failed, with our own tombstone on it. */
    private function writtenOff(string $ref, int $naira, int $votes = 5, string $ago = '-5 days'): int
    {
        $when = Carbon::parse($ago)->toDateTimeString();
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Buyer', 'donor_email' => 'buyer@example.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => $votes,
            'votes_used' => 0, 'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref,
            'status' => 'failed', 'expired_at' => $when, 'created_at' => $when,
        ]);
    }

    /** A row the GATEWAY declined: status failed, no tombstone. */
    private function declined(string $ref, int $naira): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Buyer', 'donor_email' => 'buyer@example.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 5,
            'votes_used' => 0, 'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref,
            'status' => 'failed', 'created_at' => Carbon::now()->subDays(5)->toDateTimeString(),
        ]);
    }

    private function pending(string $ref, int $naira, string $ago = '-2 days'): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Buyer', 'donor_email' => 'buyer@example.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 5,
            'votes_used' => 0, 'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref,
            'status' => 'pending', 'created_at' => Carbon::parse($ago)->toDateTimeString(),
        ]);
    }

    private function row(int $id): object
    {
        return (object) (array) DB::table('gates_donations')->where('id', $id)->first();
    }

    // ══ 1. the guess is not the verdict ══════════════════════════════════════

    /** THE test in this file: a written-off row is now visible, and it is its own bucket. */
    public function test_a_row_we_wrote_off_is_not_counted_as_never_started(): void
    {
        $this->writtenOff('AFG-WO-1', 12000);
        $this->declined('AFG-DECLINED-1', 3000);

        $b = PaymentTriage::buckets(30);

        $this->assertSame(1, $b['counts']['written_off'],
            'the row this platform gave up on is invisible again');
        $this->assertSame(12000, $b['naira']['written_off']);
        $this->assertSame(1, $b['counts']['failed'],
            'a genuine decline must not be dragged into the recovery queue');
    }

    /**
     * And the two go to different places. Re-asking a decline forever is a bill with
     * nothing at the end of it; not re-asking a write-off is somebody's money.
     */
    public function test_only_our_own_write_offs_are_offered_to_the_gateway(): void
    {
        $mine = $this->writtenOff('AFG-WO-1', 12000);
        $theirs = $this->declined('AFG-DECLINED-1', 3000);

        $refs = array_map(static fn (object $o): string => (string) $o->payment_ref,
                          PaymentTriage::recoverable(30));

        $this->assertContains('AFG-WO-1', $refs);
        $this->assertNotContains('AFG-DECLINED-1', $refs);
        $this->assertSame('failed', $this->row($theirs)->status);
        $this->assertSame('failed', $this->row($mine)->status, 'nothing is written by looking');
    }

    public function test_a_stuck_pending_row_is_still_in_the_queue(): void
    {
        // The widening must not have moved the goalposts: the bucket this screen was
        // built for is still the first thing in it.
        $this->pending('AFG-STUCK-1', 5000);
        $this->writtenOff('AFG-WO-1', 12000);

        $refs = array_map(static fn (object $o): string => (string) $o->payment_ref,
                          PaymentTriage::recoverable(30));

        $this->assertContains('AFG-STUCK-1', $refs);
        $this->assertContains('AFG-WO-1', $refs);
    }

    /**
     * A generous checkout window used to hide a charged row from the repair queue for the
     * whole of that window — the same class of bug one layer up, decided by a local clock
     * rather than by the gateway.
     */
    public function test_a_long_checkout_window_no_longer_hides_a_charged_row(): void
    {
        $id = $this->pending('AFG-INFLIGHT-1', 5000, '-3 hours');
        DB::table('gates_donations')->where('id', $id)
            ->update(['checkout_expires_at' => Carbon::now()->addDays(2)->toDateTimeString()]);

        $this->assertSame(1, PaymentTriage::buckets(30)['counts']['in_flight'],
            'sanity: the local clock still calls this one in flight');

        $refs = array_map(static fn (object $o): string => (string) $o->payment_ref,
                          PaymentTriage::recoverable(30));
        $this->assertContains('AFG-INFLIGHT-1', $refs,
            'a row charged three hours ago waited out somebody else\'s expiry setting');
    }

    public function test_a_checkout_opened_a_minute_ago_is_left_alone(): void
    {
        // The other side of that: a live callback finishing its own request must not be
        // raced by a sweep asking about the same reference.
        $this->pending('AFG-FRESH-1', 5000, '-1 minute');

        $refs = array_map(static fn (object $o): string => (string) $o->payment_ref,
                          PaymentTriage::recoverable(30));
        $this->assertNotContains('AFG-FRESH-1', $refs);
    }

    // ══ 2. the repair path can touch it ═════════════════════════════════════

    /**
     * Widening the queue without widening the update would have produced the worst
     * possible outcome: a screen that lists the missing money and a button that silently
     * does nothing to it.
     */
    public function test_a_written_off_row_can_actually_be_repaired(): void
    {
        $id = $this->writtenOff('AFG-WO-1', 12000, 6);
        $t = new PaymentTriage($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 12000]]));

        $found = $t->askGateway(PaymentTriage::recoverable(30));
        $this->assertCount(1, $found['charged']);

        $r = $t->repair($found['charged']);

        $this->assertSame(1, $r['fixed'], 'the money was listed and then left where it was');
        $this->assertSame([], $r['refused']);
        $row = $this->row($id);
        $this->assertSame('confirmed', $row->status);
        $this->assertNull($row->expired_at,
            'a confirmed row still stamped "we gave up on this" argues with itself');
    }

    public function test_repairing_still_refuses_an_underpayment(): void
    {
        // mint() issues the stored quantity without looking at what was paid, so a success
        // for a fraction of the order would deliver the full number of votes.
        $id = $this->writtenOff('AFG-WO-1', 12000);
        $t = new PaymentTriage($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 4000]]));

        $found = $t->askGateway(PaymentTriage::recoverable(30));

        $this->assertSame([], $found['charged']);
        $this->assertCount(1, $found['underpaid']);
        $this->assertSame('failed', $this->row($id)->status);
    }

    public function test_a_row_confirmed_between_looking_and_repairing_is_not_touched_again(): void
    {
        $id = $this->writtenOff('AFG-WO-1', 12000);
        $t = new PaymentTriage($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 12000]]));
        $found = $t->askGateway(PaymentTriage::recoverable(30));

        // A webhook lands while the operator is looking at the list.
        DB::table('gates_donations')->where('id', $id)
            ->update(['status' => 'confirmed', 'votes_used' => 5]);

        $r = $t->repair($found['charged']);

        $this->assertSame(0, $r['fixed'], 'the conditional update was not conditional');
        $this->assertSame(5, (int) $this->row($id)->votes_used, 'the votes were minted twice');
    }

    // ══ 3. the recovery pass converges ══════════════════════════════════════

    /** The automatic half: nobody should have to press a button for this. */
    public function test_the_sweep_recovers_a_written_off_payment_by_itself(): void
    {
        $id = $this->writtenOff('AFG-WO-1', 12000, 6);
        $rec = new PaymentReconciler($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 12000]]));

        $r = $rec->run(true);

        $this->assertSame(1, $r['recovered']);
        $this->assertSame(12000, $r['naira'], 'the run under-reports what it put back');
        $this->assertSame('confirmed', $this->row($id)->status);
        $this->assertNull($this->row($id)->expired_at);
    }

    public function test_the_read_only_pass_reports_it_and_changes_nothing(): void
    {
        $id = $this->writtenOff('AFG-WO-1', 12000);
        $rec = new PaymentReconciler($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 12000]]));

        $r = $rec->run(false);

        $this->assertSame(1, $r['recovered']);
        $this->assertSame('failed', $this->row($id)->status, 'a look moved money');
        $this->assertNull($this->row($id)->recovery_checked_at, 'a look spent the one re-ask');
        $this->assertStringContainsString('WAS PAID ALL ALONG', $r['items'][0]['note']);
    }

    /**
     * THE property that makes the pass safe to run on every sweep. A row that got a real
     * answer is never asked a third time.
     */
    public function test_a_row_that_got_an_answer_is_not_asked_again(): void
    {
        $this->writtenOff('AFG-WO-1', 12000);
        $gw = $this->gateway(['AFG-WO-1' => ['status' => 'failed', 'amount' => 0]]);
        $rec = new PaymentReconciler($gw);

        $first = $rec->run(true);
        $callsAfterFirst = $gw->calls;

        $second = $rec->run(true);

        $this->assertSame(1, $first['expired'], 'the verdict at last: it was never paid');
        $this->assertSame(0, $second['recovered']);
        $this->assertSame(0, $second['expired'], 'the same row was re-asked forever');
        $this->assertSame($callsAfterFirst, $gw->calls, 'a second sweep spent more calls on a settled row');
        $this->assertNotNull($this->row(
            (int) DB::table('gates_donations')->where('payment_ref', 'AFG-WO-1')->value('id')
        )->recovery_checked_at);
    }

    /**
     * And the mirror image, which is the one that matters: the outage that caused the
     * write-off is usually STILL GOING during the first attempt. Stamping on "asked"
     * rather than "answered" would burn the single chance at exactly the wrong moment and
     * lose the money for good.
     */
    public function test_an_unreachable_gateway_does_not_spend_the_one_re_ask(): void
    {
        $id = $this->writtenOff('AFG-WO-1', 12000, 6);

        // Sweep one: the key is still wrong, nothing answers.
        $down = new PaymentReconciler($this->gateway([], false));
        $r1 = $down->run(true);

        $this->assertSame(0, $r1['recovered']);
        $this->assertNull($this->row($id)->recovery_checked_at,
            'the one chance was spent on an outage rather than on an answer');
        $this->assertStringContainsString('will ask again', $r1['items'][0]['note']);

        // Sweep two: somebody has fixed the key.
        $up = new PaymentReconciler($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 12000]]));
        $r2 = $up->run(true);

        $this->assertSame(1, $r2['recovered'], 'the money was lost for good by a stamp');
        $this->assertSame('confirmed', $this->row($id)->status);
    }

    public function test_the_pass_does_not_touch_a_decline_the_gateway_stood_behind(): void
    {
        $id = $this->declined('AFG-DECLINED-1', 3000);
        $gw = $this->gateway(['AFG-DECLINED-1' => ['status' => 'success', 'amount' => 3000]]);

        // Even with the gateway now claiming success, a row with no tombstone is not this
        // pass's business: it was marked failed on the gateway's own verdict, and quietly
        // reversing that on a later answer is a different decision needing a person.
        (new PaymentReconciler($gw))->run(true);

        $this->assertSame('failed', $this->row($id)->status);
        $this->assertSame(0, $gw->calls, 'a settled decline cost an API call');
    }

    public function test_ancient_write_offs_are_left_alone(): void
    {
        // A two-year-old abandoned checkout is not a payment anybody is waiting on, and a
        // pass with no floor would re-ask the platform's whole history every time somebody
        // fixed a key.
        $this->writtenOff('AFG-ANCIENT-1', 12000, 5, '-400 days');
        $gw = $this->gateway(['AFG-ANCIENT-1' => ['status' => 'success', 'amount' => 12000]]);

        $r = (new PaymentReconciler($gw))->run(true);

        $this->assertSame(0, $r['recovered']);
        $this->assertSame(0, $gw->calls);
    }

    // ══ 4. it still cannot mint on thin evidence ════════════════════════════

    public function test_the_pass_refuses_an_underpayment_and_says_it_was_paid(): void
    {
        $id = $this->writtenOff('AFG-WO-1', 12000);
        $rec = new PaymentReconciler($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 4000]]));

        $r = $rec->run(true);

        $this->assertSame(0, $r['recovered']);
        $this->assertSame(1, $r['mismatch']);
        $this->assertSame('failed', $this->row($id)->status);
        // Both halves matter: somebody DID pay something, and it is not enough to mint on.
        $this->assertStringContainsString('WAS PAID', $r['items'][0]['note']);
        $this->assertStringContainsString('a person must decide', $r['items'][0]['note']);
    }

    public function test_the_pass_refuses_a_payment_in_another_currency(): void
    {
        // ₦5,000 and $5,000 are the same integer and three orders of magnitude apart.
        $id = $this->writtenOff('AFG-WO-1', 12000);
        $gw = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $p, string $ref): array
            {
                return ['ok' => true, 'status' => 'success', 'amount' => 12000, 'currency' => 'USD'];
            }
        };

        $r = (new PaymentReconciler($gw))->run(true);

        $this->assertSame(0, $r['recovered']);
        $this->assertSame(1, $r['mismatch']);
        $this->assertSame('failed', $this->row($id)->status);
    }

    public function test_a_recovered_paid_vote_order_actually_gets_its_votes(): void
    {
        // The whole point. A confirmed row with votes_used = 0 is the same failure wearing
        // a different status, and this pass exists because "voting cannot be minted".
        $id = $this->writtenOff('AFG-WO-1', 12000, 6);

        (new PaymentReconciler($this->gateway(['AFG-WO-1' => ['status' => 'success', 'amount' => 12000]])))
            ->run(true);

        $row = $this->row($id);
        $this->assertSame('confirmed', $row->status);
        $this->assertGreaterThan(0, (int) $row->votes_used,
            'confirmed with no votes is the same money missing in a different column');
    }
}
