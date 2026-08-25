<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{PaymentService, RefundService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Giving money back automatically.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE PROTECTING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Money out is the one duplicate this platform cannot take back. A double
 * credit can be clawed back, a double email is noise, a double vote is caught by
 * an index — a second refund is simply gone, and the person who received it has
 * no reason to mention it.
 *
 * So most of what is asserted here is what the sweep REFUSES to do. It should be
 * hard to make this thing pay anybody, and trivially easy to make it stop.
 *
 * The gateway is faked and records every call, so "how many times was the
 * refund endpoint hit" is a fact rather than an inference.
 */
final class AutoRefundTest extends TestCase
{
    private const REF = 'paystack_6413965117_hw8rf';
    private int $openCat = 0;
    private int $closedCat = 0;
    private int $openNominee = 0;
    private int $closedNominee = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        DB::table('gates_settings')->where('key_name', 'auto_refund_unminted')->delete();

        // Ids are ALLOCATED, never chosen. `gates_award_programmes.id` is
        // TINYINT UNSIGNED — 255 — so a test that writes a memorable literal like
        // 880 does not fail, it is silently ignored by insertOrIgnore AND drags
        // the table's AUTO_INCREMENT up to its ceiling, so the NEXT test to
        // insert a programme normally dies with "out of range". A fixture must
        // not be able to break tests it has never heard of.
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Refund Awards', 'slug' => 'refund-' . bin2hex(random_bytes(3)), 'is_active' => 1,
        ]);

        // One cycle still voting, one whose voting has closed. The closed one is
        // the entire automatic case.
        $openCycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-2 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        $shutCycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2025, 'status' => 'results',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-40 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-9 days')),
        ]);

        $this->openCat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $openCycle, 'title' => 'Open', 'slug' => 'open-' . bin2hex(random_bytes(3))]);
        $this->closedCat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $shutCycle, 'title' => 'Shut', 'slug' => 'shut-' . bin2hex(random_bytes(3))]);

        $this->openNominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->openCat, 'name' => 'Still Running', 'status' => 'approved', 'vote_count' => 0]);
        $this->closedNominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->closedCat, 'name' => 'Race Over', 'status' => 'approved', 'vote_count' => 0]);
    }

    /** A gateway that answers as told and counts how often it was asked. */
    private function gateway(array $answer = ['ok' => true, 'status' => 'refunded']): PaymentService
    {
        return new class ($answer) extends PaymentService {
            public array $refunds = [];
            public function __construct(private array $answer) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                return ['ok' => true, 'status' => 'success', 'amount' => 0, 'currency' => 'NGN', 'meta' => []];
            }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            {
                $this->refunds[] = ['ref' => $reference, 'amount' => $amountNaira];
                return $this->answer + ['message' => 'ok', 'provider_ref' => 'rfnd_1'];
            }
        };
    }

    private function order(array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name' => 'Okun Alimosho', 'donor_email' => 'okun@example.test',
            'amount_naira' => 3920, 'tier' => 'paid-vote', 'bonus_votes' => 20, 'votes_used' => 0,
            'intent_nominee_id' => $this->closedNominee,
            'payment_ref' => self::REF, 'status' => 'confirmed',
            // Well outside the grace window.
            'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours')),
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('gates_donations')->where('id', $id)->first();
    }

    // ── the one case it automates ────────────────────────────────────────────

    public function test_a_paid_order_that_can_never_mint_is_refunded(): void
    {
        $id = $this->order();
        $gw = $this->gateway();

        $this->assertSame(1, (new RefundService($gw))->sweep());

        $this->assertCount(1, $gw->refunds);
        $this->assertSame(self::REF, $gw->refunds[0]['ref']);
        // This asserted the charge (3920) was passed through. The intent was "the
        // whole thing, not a guess at it", and that is now expressed more strongly:
        // NO amount is passed, so the gateway returns the whole transaction from its
        // own record rather than trusting our column. Passing a figure made every
        // refund a partial refund, and a column that was too LOW succeeded silently.
        // See RefundAmountTest.
        $this->assertNull($gw->refunds[0]['amount'],
            'the whole transaction, decided by the gateway rather than by our row');

        $d = $this->row($id);
        $this->assertSame('refunded', $d->refund_state);
        $this->assertNotNull($d->refunded_at);
        $this->assertStringContainsString('voting closed', (string) $d->refund_reason,
            'a person reading this row in a month needs to know why');
    }

    public function test_a_queued_refund_is_recorded_as_pending_not_as_done(): void
    {
        // Both gateways settle refunds hours later. Marking a queued refund as
        // complete would tell the buyer money had arrived that had not.
        $id = $this->order();
        (new RefundService($this->gateway(['ok' => true, 'status' => 'pending'])))->sweep();

        $d = $this->row($id);
        $this->assertSame('pending', $d->refund_state);
        $this->assertNull($d->refunded_at, 'refunded_at means the money is actually back');
    }

    // ── everything it refuses ────────────────────────────────────────────────

    public function test_an_order_whose_cycle_is_still_open_is_left_alone(): void
    {
        // It can still mint. Refunding it takes the votes away from somebody who
        // is about to get them.
        $this->order(['intent_nominee_id' => $this->openNominee]);
        $gw = $this->gateway();

        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds);
    }

    public function test_an_order_that_did_mint_is_never_refunded(): void
    {
        $this->order(['votes_used' => 20]);
        $gw = $this->gateway();

        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds, 'they got what they paid for');
    }

    public function test_a_recent_payment_is_inside_the_grace_window(): void
    {
        // A mint that is merely LATE is not a mint that failed.
        $this->order(['created_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);
        $gw = $this->gateway();

        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds);
    }

    public function test_an_unconfirmed_payment_is_not_refunded(): void
    {
        // Nothing was taken. A refund here would be a gift.
        $this->order(['status' => 'pending']);
        $gw = $this->gateway();

        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds);
    }

    public function test_an_already_refunded_order_is_not_refunded_again(): void
    {
        $this->order(['refunded_at' => date('Y-m-d H:i:s')]);
        $gw = $this->gateway();

        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds);
    }

    public function test_an_order_over_the_per_order_ceiling_waits_for_a_person(): void
    {
        $this->order(['amount_naira' => 500_000]);
        $gw = $this->gateway();

        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds,
            'a large unminted order is exactly the one somebody should look at');
    }

    public function test_an_order_with_no_nominee_waits_for_a_person(): void
    {
        // Nothing to deliver against means nothing to prove was undeliverable.
        $this->order(['intent_nominee_id' => null]);
        $gw = $this->gateway();

        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds);
    }

    public function test_the_switch_turns_it_off(): void
    {
        $this->order();
        DB::table('gates_settings')->insert(['key_name' => 'auto_refund_unminted', 'value' => '0']);
        $gw = $this->gateway();

        $this->assertFalse(RefundService::autoEnabled());
        $this->assertSame(0, (new RefundService($gw))->sweep());
        $this->assertSame([], $gw->refunds);
    }

    // ── and the guard that matters most ──────────────────────────────────────

    public function test_two_sweeps_refund_once(): void
    {
        $this->order();
        $gw = $this->gateway();
        $svc = new RefundService($gw);

        $svc->sweep();
        $svc->sweep();
        $svc->sweep();

        $this->assertCount(1, $gw->refunds,
            'the claim stamp is written before the gateway is called for exactly this reason');
    }

    public function test_a_refused_refund_releases_the_claim_so_it_can_be_retried(): void
    {
        // A definite refusal is the one outcome where we KNOW no money moved.
        $id = $this->order();
        $gw = $this->gateway(['ok' => false, 'status' => 'failed']);
        (new RefundService($gw))->sweep();

        $d = $this->row($id);
        $this->assertSame('failed', $d->refund_state);
        $this->assertNull($d->refund_requested_at, 'safe to try again — nothing was sent');
        $this->assertNull($d->refunded_at);
    }

    public function test_an_unreachable_gateway_keeps_the_claim(): void
    {
        // We do NOT know whether it was accepted. Erring towards a stuck row is
        // right: a stuck row is visible and fixable, a double payment is neither.
        $id = $this->order();
        $gw = $this->gateway(['ok' => false, 'status' => 'pending']);
        $svc = new RefundService($gw);
        $svc->sweep();
        $svc->sweep();

        $this->assertCount(1, $gw->refunds);
        $this->assertNotNull($this->row($id)->refund_requested_at);
    }

    // ── what the assistant is allowed to see ─────────────────────────────────

    public function test_the_assistant_can_report_a_refund_but_never_cause_one(): void
    {
        $id = $this->order();
        (new RefundService($this->gateway()))->sweep();

        $s = RefundService::statusFor(self::REF);
        $this->assertTrue($s['found']);
        $this->assertSame('refunded', $s['state']);
        $this->assertStringContainsString('refunded in full', $s['say']);

        // The tool surface carries no way to start one.
        $tools = array_column((new \AfricaGates\Services\SupportContext())->tools(), 'name');
        $this->assertContains('refund_status', $tools);
        foreach ($tools as $t) {
            $this->assertStringNotContainsString('issue_refund', $t);
            $this->assertStringNotContainsString('start_refund', $t);
        }
    }

    public function test_a_pending_refund_reads_as_on_its_way(): void
    {
        $this->order();
        (new RefundService($this->gateway(['ok' => true, 'status' => 'pending'])))->sweep();

        $s = RefundService::statusFor(self::REF);
        $this->assertSame('pending', $s['state']);
        $this->assertStringContainsString('on its way', $s['say']);
    }

    public function test_an_unknown_reference_discloses_nothing(): void
    {
        $s = RefundService::statusFor('paystack_nope_nope');
        $this->assertFalse($s['found']);
        $this->assertStringNotContainsString('okun@', $s['say']);
    }

    // ══ PACING ═══════════════════════════════════════════════════════════════
    //
    // The values were never the problem. The mechanics around them were, and
    // three of the four faults are the same shape as the rest of this audit:
    // a guard that documented one thing and measured another, and an alert that
    // fired on a fourteen-minute loop until nobody read it.

    /** A mailer that keeps its post instead of sending it, so alerts are countable. */
    private function mailbox(): \AfricaGates\Services\OtpService
    {
        return new class ([]) extends \AfricaGates\Services\OtpService {
            /** @var list<string> */
            public array $sent = [];
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = ''): array
            {
                // Subject AND body. What an alert SAYS is the point of it — an
                // email that arrives and explains the wrong thing is barely
                // better than one that never arrives.
                $this->sent[] = $subject . "\n" . $plainBody;
                return ['ok' => true];
            }
        };
    }

    /**
     * THE GRACE MEASURED THE WRONG CLOCK.
     *
     * Its own docblock says "do not refund a payment that CONFIRMED within this
     * window", and the query said `created_at` — when the buyer STARTED checkout —
     * because that was the only timestamp the schema had. An order created at
     * 23:00 and confirmed at 23:59 therefore got one minute of grace, not sixty.
     */
    public function test_the_grace_window_runs_from_when_the_money_arrived(): void
    {
        $id = $this->order([
            'created_at'   => date('Y-m-d H:i:s', strtotime('-3 hours')),   // long past the old bound
            'confirmed_at' => date('Y-m-d H:i:s', strtotime('-5 minutes')), // but it only just paid
        ]);
        $gw = $this->gateway();
        (new RefundService($gw))->sweep();

        $this->assertCount(0, $gw->refunds, 'five minutes old — the mint may still be on its way');
        $this->assertNull($this->row($id)->refund_requested_at);
    }

    /** And once the money has genuinely been sitting there an hour, it goes back. */
    public function test_an_order_confirmed_over_an_hour_ago_is_refunded(): void
    {
        $this->order([
            'created_at'   => date('Y-m-d H:i:s', strtotime('-5 hours')),
            'confirmed_at' => date('Y-m-d H:i:s', strtotime('-4 hours')),
        ]);
        $gw = $this->gateway();
        (new RefundService($gw))->sweep();

        $this->assertCount(1, $gw->refunds);
    }

    /**
     * A REFUSAL USED TO MEAN A HUNDRED REFUSALS.
     *
     * Releasing the claim is right — a definite refusal is the one outcome where
     * we know no money moved. But `owed()` re-selects an unclaimed row immediately
     * and maintenance ticks every fourteen minutes, so one refusal became roughly
     * a hundred gateway calls and a hundred identical admin emails a day, each
     * saying the refund would not be retried automatically.
     */
    public function test_a_refused_refund_waits_before_it_is_tried_again(): void
    {
        $id  = $this->order();
        $gw  = $this->gateway(['ok' => false, 'status' => 'failed']);
        $svc = new RefundService($gw);

        $svc->sweep();
        $svc->sweep();
        $svc->sweep();

        $this->assertCount(1, $gw->refunds, 'three sweeps, one attempt — the rest are inside the backoff');
        $d = $this->row($id);
        $this->assertSame('failed', $d->refund_state);
        $this->assertNull($d->refund_requested_at, 'the claim is still released — nothing was sent');
        $this->assertSame(1, (int) $d->refund_attempts);
        $this->assertNotNull($d->refund_retry_after);
    }

    /** When the wait is over it tries again — an insufficient balance clears by itself. */
    public function test_the_retry_happens_once_the_backoff_has_passed(): void
    {
        $id = $this->order();
        (new RefundService($this->gateway(['ok' => false, 'status' => 'failed'])))->sweep();

        // The settlement landed; the gateway will take it now.
        DB::table('gates_donations')->where('id', $id)
            ->update(['refund_retry_after' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        $gw = $this->gateway();
        (new RefundService($gw))->sweep();

        $this->assertCount(1, $gw->refunds);
        $d = $this->row($id);
        $this->assertSame('refunded', $d->refund_state);
        $this->assertNull($d->refund_retry_after, 'a settled refund must not still look like it is waiting');
    }

    /**
     * A REFUSAL THAT WAITING CAN FIX GETS THE FULL SCHEDULE.
     *
     * "Insufficient settlement balance" is the commonest refusal on these gateways
     * and it is a clock problem, not a decision: Nigerian card settlement is T+1,
     * so a refund refused at 09:00 very often succeeds that evening with nobody
     * doing anything. Four attempts across 31 hours covers that cycle.
     */
    public function test_a_transient_refusal_is_retried_across_a_settlement_cycle(): void
    {
        $id   = $this->order();
        $post = $this->mailbox();
        $answer = ['ok' => false, 'status' => 'failed', 'retryable' => true];

        for ($i = 0; $i < 5; $i++) {
            (new RefundService($this->gateway($answer), $post))->sweep();
            DB::table('gates_donations')->where('id', $id)
                ->update(['refund_retry_after' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);
        }

        $d = $this->row($id);
        $this->assertSame('exhausted', $d->refund_state);
        $this->assertSame(4, (int) $d->refund_attempts);
        // Told at both ends and never in between: a hundred copies of the same
        // paragraph is how an alert becomes a mail filter.
        $this->assertCount(2, $post->sent);
        $this->assertStringContainsString('refused', $post->sent[0]);
        $this->assertStringContainsString('GAVE UP', $post->sent[1]);
    }

    /**
     * A REFUSAL THAT WAITING CANNOT FIX GOES STRAIGHT TO A PERSON.
     *
     * A revoked key, an unknown reference, a transaction past its refundable age.
     * Under a single schedule for every refusal these burned thirty-one hours of
     * pointless retries before anybody heard about them — which is thirty-one
     * hours in which somebody could have fixed the key.
     */
    public function test_a_permanent_refusal_is_escalated_at_once_and_never_retried(): void
    {
        $id   = $this->order();
        $post = $this->mailbox();
        $answer = ['ok' => false, 'status' => 'failed', 'retryable' => false];

        $svc = new RefundService($this->gateway($answer), $post);
        $svc->sweep();
        $svc->sweep();

        $d = $this->row($id);
        $this->assertSame('exhausted', $d->refund_state, 'no schedule — a person has it now');
        $this->assertSame(1, (int) $d->refund_attempts);
        $this->assertNull($d->refund_retry_after, 'there is no next attempt to schedule');
        $this->assertCount(1, $post->sent);
        $this->assertStringContainsString('NOT retryable', $post->sent[0]);
    }

    /**
     * AND A REFUSAL WE DO NOT RECOGNISE GETS ONE TRY, THEN A PERSON.
     *
     * An unrecognised message usually means a gateway has reworded an error. The
     * costs are lopsided: guess "retryable" and a dead refund waits a day and a
     * half; guess "permanent" and a self-healing one reaches a human who can
     * simply re-run it. One retry buys the common transient case without making
     * anybody wait out a full schedule for wording we do not understand.
     */
    public function test_an_unclassified_refusal_gets_one_retry_then_a_person(): void
    {
        $id   = $this->order();
        $post = $this->mailbox();

        for ($i = 0; $i < 3; $i++) {
            (new RefundService($this->gateway(['ok' => false, 'status' => 'failed']), $post))->sweep();
            DB::table('gates_donations')->where('id', $id)
                ->update(['refund_retry_after' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);
        }

        $d = $this->row($id);
        $this->assertSame('exhausted', $d->refund_state);
        $this->assertSame(2, (int) $d->refund_attempts, 'one attempt, one retry, then stop');
        $this->assertCount(2, $post->sent);
        $this->assertStringContainsString('could not tell', $post->sent[0],
            'the first alert says plainly that the wording was unrecognised');
    }

    /** An exhausted order is a person's problem now, so the sweep leaves it alone. */
    public function test_an_exhausted_order_is_not_picked_up_again(): void
    {
        $this->order(['refund_state' => 'exhausted', 'refund_attempts' => 3]);
        $gw = $this->gateway();
        (new RefundService($gw))->sweep();

        $this->assertCount(0, $gw->refunds);
    }

    /**
     * "LEFT FOR A HUMAN" MEANT LEFT FOR A HUMAN WHO WAS NEVER TOLD.
     *
     * An over-ceiling order was written to error_log on every one of the day's
     * hundred sweeps and surfaced nowhere anybody looks. It now gets a state, so
     * it appears on the finance page beside every other refund state, leaves this
     * queue, and produces exactly one email.
     */
    public function test_an_over_ceiling_order_is_parked_visibly_and_alerts_once(): void
    {
        $id   = $this->order(['amount_naira' => 450000]);
        $post = $this->mailbox();
        $svc  = new RefundService($this->gateway(), $post);

        $svc->sweep();
        $svc->sweep();
        $svc->sweep();

        $d = $this->row($id);
        $this->assertSame('manual', $d->refund_state);
        $this->assertStringContainsString('ceiling', (string) $d->refund_reason);
        $this->assertNull($d->refund_requested_at, 'nothing was asked of any gateway, so the claim stays free');
        $this->assertCount(1, $post->sent, 'one email, not one per sweep');
    }

    /** The ceilings are dials now, so an incident does not need a deploy. */
    public function test_the_per_order_ceiling_can_be_raised_deliberately(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'refund_max_order_naira'], ['value' => '500000']);

        $id = $this->order(['amount_naira' => 450000]);
        $gw = $this->gateway();
        (new RefundService($gw))->sweep();

        // The point of this test is the CEILING, not the amount: an order that the
        // default ₦200,000 limit would have parked went through because the dial was
        // raised. So it asserts the order was actually refunded, and the amount check
        // is now the shared "no partial refunds" rule.
        $this->assertCount(1, $gw->refunds);
        $this->assertNull($gw->refunds[0]['amount']);
        $this->assertSame('refunded', $this->row($id)->refund_state,
            'the raised ceiling let a large order through rather than parking it');
    }

    /** But not without limit. A ceiling that can be raised to anything is not a ceiling. */
    public function test_a_silly_ceiling_setting_falls_back_to_something_sane(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'refund_max_daily_naira'], ['value' => '0']);
        $this->assertSame(1_000_000, RefundService::maxDailyNaira(), 'zero reads as broken, not as a policy');

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'refund_max_daily_naira'], ['value' => '999999999']);
        $this->assertSame(20_000_000, RefundService::maxDailyNaira(), 'capped in code');
    }

    // ── what support is allowed to say about all this ────────────────────────

    /**
     * "Failed" no longer means finished. Telling somebody their refund failed when
     * it is being retried over the next day is how one complaint becomes two.
     */
    public function test_a_retrying_refund_does_not_read_as_a_dead_end(): void
    {
        $this->order();
        (new RefundService($this->gateway(['ok' => false, 'status' => 'failed'])))->sweep();

        $s = RefundService::statusFor(self::REF);
        $this->assertSame('retrying', $s['state']);
        $this->assertFalse($s['settled']);
        $this->assertStringContainsString('retried', $s['say']);
    }

    /** And a parked order is honestly described as a person's job, not as nothing. */
    public function test_a_parked_refund_reads_as_being_handled_by_a_person(): void
    {
        $this->order(['refund_state' => 'manual', 'amount_naira' => 450000]);

        $s = RefundService::statusFor(self::REF);
        $this->assertSame('manual', $s['state']);
        $this->assertStringContainsString('person', $s['say']);
        $this->assertStringNotContainsString('No refund has been started', $s['say']);
    }
}
