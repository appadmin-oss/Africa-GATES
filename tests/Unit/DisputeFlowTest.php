<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DisputeEvidence;
use AfricaGates\Services\DisputeService;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Answering a chargeback before the 16 hours run out.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE ORDER OF THE STEPS IS THE WHOLE TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Paystack's evidence flow has four steps and three of them punish improvisation:
 *
 *   · the signed upload URL is valid for THIRTY MINUTES and is single-use
 *   · a successful upload returns an EMPTY BODY, so success is the status code —
 *     code looking for the `status: true` that every other Paystack call returns
 *     would treat every successful upload as a failure
 *   · a `declined` resolution is REFUSED outright without an `uploaded_filename`
 *
 * So resolving must come last, and anything that fails above must stop the flow
 * before it. Declining with no evidence attached does not just fail — it spends the
 * attempt, and there are only 16 hours of them.
 *
 * These tests drive the flow with a fake gateway that records the call order,
 * because the order is the property that matters and it cannot be observed from the
 * outcome of a successful run.
 */
final class DisputeFlowTest extends TestCase
{
    /** @return array{0:int,1:string} donation id, reference */
    private function paidOrder(bool $withVotes = true): array
    {
        $ref = 'AFG-PVOTE-dispute01';
        // A real nominee to name. The receipt's whole argument is "these votes went to
        // this person at this time", so a fixture without one tests the wrong thing.
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 1, 'category_id' => 1, 'name' => 'Amara Okonkwo', 'status' => 'approved',
        ]);
        $id = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Ada Okonkwo', 'donor_email' => 'ada@example.test',
            'amount_naira' => 5000, 'tier' => 'paid-vote', 'bonus_votes' => 35,
            'votes_used' => $withVotes ? 35 : 0, 'intent_nominee_id' => 1,
            'payment_ref' => $ref, 'gateway_txn_id' => '4738291042', 'status' => 'confirmed',
            'created_at' => '2026-08-01 10:00:00', 'confirmed_at' => '2026-08-01 10:01:12',
        ]);
        if ($withVotes) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => hash('sha256', 'ada'),
                'vote_type' => 'paid', 'weight' => 35, 'donation_id' => $id,
                'voted_at' => '2026-08-01 10:01:15',
            ]);
        }
        return [$id, $ref];
    }

    /**
     * A PaymentService that records what was called, in order, and answers however
     * the test needs. Every dispute method is overridden, so no test can reach the
     * network.
     */
    private function gateway(array $over = []): PaymentService
    {
        return new class ($over) extends PaymentService {
            public array $calls = [];
            public function __construct(private array $over) { parent::__construct(); }
            private function answer(string $k, array $default): array
            {
                $this->calls[] = $k;
                return $this->over[$k] ?? $default;
            }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function disputes(array $f = []): array
            {
                return $this->answer('disputes', ['ok' => true, 'message' => '', 'disputes' => []]);
            }
            public function dispute(string $id): array
            {
                return $this->answer('dispute', ['ok' => true, 'message' => '',
                    'dispute' => ['id' => $id, 'kind' => 'chargeback', 'reference' => 'AFG-PVOTE-dispute01']]);
            }
            public function disputeUploadUrl(string $id, string $filename): array
            {
                return $this->answer('uploadUrl', ['ok' => true, 'message' => '',
                    'url' => 'https://files.paystack.test/signed', 'filename' => $filename]);
            }
            public function putSignedFile(string $u, string $b, string $ct): array
            {
                return $this->answer('put', ['ok' => true, 'code' => 200, 'message' => '']);
            }
            public function disputeAddEvidence(string $id, array $f): array
            {
                return $this->answer('addEvidence', ['ok' => true, 'message' => '', 'evidence_id' => 77]);
            }
            public function disputeResolve(string $id, array $p): array
            {
                $this->resolved = $p;
                return $this->answer('resolve', ['ok' => true, 'message' => '', 'status' => 'resolved']);
            }
            public array $resolved = [];
        };
    }

    // ── the evidence itself ─────────────────────────────────────────────────

    /**
     * The receipt states what was DELIVERED, read from the vote rows rather than the
     * order's own counter. "I paid and got nothing" is answered with a record or it is
     * not answered at all.
     */
    public function test_the_receipt_states_what_was_delivered(): void
    {
        [, $ref] = $this->paidOrder();

        $f = DisputeEvidence::facts($ref);

        $this->assertTrue($f['found']);
        $this->assertSame(35, $f['votes']);
        $this->assertNotSame('', $f['nominee'], 'the nominee must be named');
        $this->assertSame('2026-08-01 10:01:15', $f['minted_at'],
            'gates_votes has no created_at — reading the wrong column silently prints '
            . '"Credited at: not recorded" on the one line a reviewer looks at hardest');
        $this->assertStringContainsString('/vote/verify', $f['proof_url'],
            'and a URL where anyone can check the same tally');
    }

    /** A dispute carries the GATEWAY's reference, which need not be the one we minted. */
    public function test_the_receipt_can_be_built_from_the_gateways_own_reference(): void
    {
        $this->paidOrder();

        $f = DisputeEvidence::facts('4738291042');

        $this->assertTrue($f['found'], 'a dispute quoting the Paystack id found no order');
        $this->assertSame('AFG-PVOTE-dispute01', $f['reference']);
    }

    public function test_it_renders_a_real_jpeg(): void
    {
        [, $ref] = $this->paidOrder();

        $bytes = DisputeEvidence::jpeg($ref);

        $this->assertNotNull($bytes);
        $this->assertStringStartsWith("\xFF\xD8\xFF", (string) $bytes, 'not a JPEG');
        $this->assertMatchesRegularExpression('/\.jpg$/', DisputeEvidence::filename($ref),
            'Paystack accepts .jpg, .jpeg and .pdf only');
    }

    /**
     * No order, no receipt — and null rather than a blank page. An empty rectangle
     * satisfies the API's need for a file while telling the reviewer nothing, so the
     * dispute would be lost WITH a document attached.
     */
    public function test_it_refuses_to_draw_a_receipt_for_an_unknown_payment(): void
    {
        $this->assertNull(DisputeEvidence::jpeg('AFG-PVOTE-neverexisted'));
    }

    // ── the order of the four steps ─────────────────────────────────────────

    public function test_contesting_uploads_before_it_resolves(): void
    {
        [, $ref] = $this->paidOrder();
        $gw = $this->gateway();

        $r = (new DisputeService($gw))->contest('DIS_1', $ref, '');

        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $put     = array_search('put', $gw->calls, true);
        $resolve = array_search('resolve', $gw->calls, true);
        $this->assertNotFalse($put, 'nothing was uploaded');
        $this->assertNotFalse($resolve, 'nothing was resolved');
        $this->assertLessThan($resolve, $put,
            'it resolved before uploading — Paystack refuses a decline with no evidence, '
            . 'and the attempt is spent');
    }

    /** And the resolution carries the filename the upload was stored under. */
    public function test_the_resolution_names_the_uploaded_file(): void
    {
        [, $ref] = $this->paidOrder();
        $gw = $this->gateway();

        (new DisputeService($gw))->contest('DIS_1', $ref, '');

        $this->assertSame('declined', $gw->resolved['resolution'] ?? null);
        $this->assertSame(DisputeEvidence::filename($ref), $gw->resolved['uploaded_filename'] ?? null);
        $this->assertSame(0, $gw->resolved['refund_amount'] ?? null, 'contesting refunds nothing');
    }

    /**
     * A FAILED UPLOAD MUST NOT RESOLVE. This is the expensive mistake: declining with
     * nothing attached is refused by Paystack, and the 16-hour window has one fewer
     * attempt in it.
     */
    public function test_a_failed_upload_stops_before_resolving(): void
    {
        [, $ref] = $this->paidOrder();
        $gw = $this->gateway(['put' => ['ok' => false, 'code' => 403, 'message' => 'expired signature']]);

        $r = (new DisputeService($gw))->contest('DIS_1', $ref, '');

        $this->assertFalse($r['ok']);
        $this->assertSame('upload', $r['step'], 'the failing step must be named');
        $this->assertNotContains('resolve', $gw->calls,
            'it declined the dispute with no evidence attached');
    }

    /** Same for a refused upload URL — the flow stops at the earliest failure. */
    public function test_a_refused_upload_url_stops_the_flow(): void
    {
        [, $ref] = $this->paidOrder();
        $gw = $this->gateway(['uploadUrl' => ['ok' => false, 'message' => 'nope', 'url' => '', 'filename' => '']]);

        $r = (new DisputeService($gw))->contest('DIS_1', $ref, '');

        $this->assertFalse($r['ok']);
        $this->assertSame('upload-url', $r['step']);
        $this->assertNotContains('put', $gw->calls);
        $this->assertNotContains('resolve', $gw->calls);
    }

    /**
     * And with no receipt to send, it never starts — which also saves the 30-minute
     * URL from being fetched and wasted.
     */
    public function test_with_no_evidence_it_never_asks_for_a_url(): void
    {
        $gw = $this->gateway();

        $r = (new DisputeService($gw))->contest('DIS_1', 'AFG-PVOTE-neverexisted', '');

        $this->assertFalse($r['ok']);
        $this->assertSame('evidence', $r['step']);
        $this->assertSame([], $gw->calls, 'it reached the gateway with nothing to say');
    }

    /** A fraud claim needs a person described, not only a transaction receipted. */
    public function test_a_fraud_claim_also_submits_structured_evidence(): void
    {
        [, $ref] = $this->paidOrder();
        $gw = $this->gateway(['dispute' => ['ok' => true, 'message' => '',
            'dispute' => ['id' => 'DIS_2', 'kind' => 'fraud', 'reference' => $ref]]]);

        $r = (new DisputeService($gw))->contest('DIS_2', $ref, '');

        $this->assertTrue($r['ok']);
        $this->assertContains('addEvidence', $gw->calls,
            'a fraud claim asks "was this you", which a receipt alone does not answer');
        $this->assertSame(77, $gw->resolved['evidence'] ?? null);
    }

    /** An ordinary chargeback does not, because there is nothing extra to say. */
    public function test_a_plain_chargeback_does_not_submit_structured_evidence(): void
    {
        [, $ref] = $this->paidOrder();
        $gw = $this->gateway();

        (new DisputeService($gw))->contest('DIS_1', $ref, '');

        $this->assertNotContains('addEvidence', $gw->calls);
    }

    // ── conceding ───────────────────────────────────────────────────────────

    /**
     * The refund amount is sent in KOBO. A naira figure here would refund a hundredth
     * of what was intended, and nobody would notice until the customer complained
     * again.
     */
    public function test_a_partial_refund_is_sent_in_kobo(): void
    {
        $gw = $this->gateway();

        (new DisputeService($gw))->concede('DIS_1', 5000, '');

        $this->assertSame('merchant-accepted', $gw->resolved['resolution'] ?? null);
        $this->assertSame(500000, $gw->resolved['refund_amount'] ?? null,
            'NGN 5,000 must go as 500000 kobo');
    }

    /** No amount means the whole thing, so no refund_amount is sent at all. */
    public function test_conceding_in_full_sends_no_amount(): void
    {
        $gw = $this->gateway();

        (new DisputeService($gw))->concede('DIS_1', null, '');

        $this->assertArrayNotHasKey('refund_amount', $gw->resolved);
    }

    // ── the clock ───────────────────────────────────────────────────────────

    public function test_the_deadline_is_sixteen_hours_from_when_it_was_raised(): void
    {
        $this->assertSame('2026-08-11 01:00:00', DisputeService::deadline('2026-08-10 09:00:00'));
        $this->assertSame(16, DisputeService::RESPOND_WITHIN_HOURS);
    }

    /** Hours left counts down, and goes negative rather than clamping at zero. */
    public function test_hours_left_goes_negative_once_the_window_has_closed(): void
    {
        $fresh = DisputeService::hoursLeft(Carbon::now()->subHours(2)->toDateTimeString());
        $gone  = DisputeService::hoursLeft(Carbon::now()->subHours(20)->toDateTimeString());

        $this->assertNotNull($fresh);
        $this->assertGreaterThan(13, $fresh);
        $this->assertNotNull($gone);
        $this->assertLessThan(0, $gone,
            'a closed window must read as past the deadline, not as zero hours left');
    }

    public function test_an_undated_dispute_reports_an_unknown_deadline(): void
    {
        $this->assertNull(DisputeService::hoursLeft(''));
        $this->assertSame('', DisputeService::deadline(''));
    }

    // ── the queue ───────────────────────────────────────────────────────────

    /**
     * Soonest deadline first. Ordering by date raised would put a dispute with two
     * hours left below one with fourteen.
     */
    public function test_the_queue_is_ordered_by_time_remaining(): void
    {
        [, $ref] = $this->paidOrder();
        $gw = $this->gateway(['disputes' => ['ok' => true, 'message' => '', 'disputes' => [
            ['id' => 'ROOMY', 'status' => 'awaiting-merchant-feedback', 'kind' => 'chargeback',
             'reference' => $ref, 'amount' => 5000, 'created_at' => Carbon::now()->subHours(1)->toDateTimeString()],
            ['id' => 'URGENT', 'status' => 'awaiting-merchant-feedback', 'kind' => 'chargeback',
             'reference' => $ref, 'amount' => 5000, 'created_at' => Carbon::now()->subHours(14)->toDateTimeString()],
        ]]]);

        $q = (new DisputeService($gw))->queue(30);

        $this->assertTrue($q['ok']);
        $this->assertSame('URGENT', $q['disputes'][0]['id'], 'the one about to expire must be first');
        // And each row carries what our records show, so the screen can print it
        // before anybody presses a button.
        $this->assertTrue($q['disputes'][0]['evidence']['found']);
        $this->assertSame(35, $q['disputes'][0]['evidence']['votes']);
    }

    public function test_a_gateway_that_will_not_answer_is_reported_not_swallowed(): void
    {
        $gw = $this->gateway(['disputes' => ['ok' => false, 'message' => 'no dispute access', 'disputes' => []]]);

        $q = (new DisputeService($gw))->queue();

        $this->assertFalse($q['ok']);
        $this->assertSame('no dispute access', $q['message']);
    }
}
