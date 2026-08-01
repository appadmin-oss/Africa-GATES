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
        $this->assertSame(3920, $gw->refunds[0]['amount'], 'the full charge, not a guess at it');

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
}
