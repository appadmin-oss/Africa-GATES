<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CheckoutMailer;
use AfricaGates\Services\DisputeAlert;
use AfricaGates\Services\EventTicketMailer;
use AfricaGates\Services\ShopOrderService;
use AfricaGates\Services\SupportTicketService;
use AfricaGates\Services\WebhookService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The two things Paystack's own documentation says a webhook handler must not do.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * 1 · IT MUST ANSWER INSIDE ~30 SECONDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Paystack allows roughly 30 seconds per delivery attempt and treats a slower
 * response as a failure: live mode retries every three minutes for four attempts
 * and then hourly for up to 72 hours, and eventually gives up. Its docs are
 * explicit that SMTP, PDF generation and third-party calls belong on a queue.
 *
 * Measured against this codebase before the fix, one delivery could spend
 *
 *     up to 15s   verifying with Paystack (PaymentService::TIMEOUT)
 *   + up to  8s   PER active outbound integration (WebhookService: 5s + 3s connect)
 *   + up to 12s   sending the receipt over SMTP (PHPMailer Timeout = 12)
 *
 * — 27 seconds with no integrations configured, and over budget the moment an
 * admin adds one from a console that has no visible connection to payments.
 * Nothing would have looked broken here: our confirmation is idempotent, so the
 * retries would land on an already-confirmed order and do nothing, while
 * Paystack's dashboard filled with failed deliveries.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * 2 · A CHARGEBACK STARTS A 16-HOUR CLOCK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * If the window closes with no response, Paystack accepts the dispute on the
 * merchant's behalf and refunds the customer out of the merchant's balance. The
 * handler used to claw back the votes and write a log line — and a log line on a
 * cPanel host with no shell is not a notification, so the platform was in effect
 * configured to concede every dispute and pay for it.
 */
final class PaystackWebhookComplianceTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    // ── 1 · nothing slow inside the handler ─────────────────────────────────

    /**
     * The confirm path must not send the receipt itself.
     *
     * Asserted on the source because the cost is a wall-clock property of a code
     * path, and there is no way to observe "this took 27 seconds" in a unit test
     * without actually waiting 27 seconds.
     */
    public function test_the_confirm_path_queues_the_receipt_instead_of_sending_it(): void
    {
        $src = $this->src('src/Controllers/PaymentController.php');

        $this->assertStringContainsString('CheckoutMailer::queueReceipt(', $src);
        $this->assertStringNotContainsString('CheckoutMailer::receipt(', $src,
            'the receipt is being sent inside the gateway webhook — SMTP is up to 12s of a '
            . '~30s budget');
    }

    /** And it must not fan out to every configured integration inline either. */
    public function test_the_confirm_path_queues_the_outbound_dispatch(): void
    {
        $src = $this->src('src/Controllers/PaymentController.php');

        $this->assertStringContainsString('WebhookService::dispatchLater(', $src);
        $this->assertDoesNotMatchRegularExpression('/WebhookService::dispatch\(/', $src,
            'an outbound integration is being posted to inside the gateway webhook — one '
            . 'HTTP request each, 8s worst case, multiplied by however many an admin added');
    }

    /**
     * MINTING STAYS INLINE. This is the other half of the trade and the easier one
     * to get wrong by over-applying the rule: the votes are what the supporter is
     * watching, and a tally that updates on the next cron tick instead of now is the
     * complaint this platform gets most. It is a handful of indexed writes.
     */
    public function test_minting_is_still_done_immediately(): void
    {
        $src = $this->src('src/Controllers/PaymentController.php');

        $this->assertStringContainsString('PaidVoteService::mint(', $src,
            'minting has been pushed onto the queue — the supporter now waits for cron');
    }

    /** Every queued job needs a registered handler, or they simply pile up. */
    public function test_every_queued_job_has_a_handler_in_the_drainer(): void
    {
        $drainer = $this->src('src/Support/Maintenance.php');

        // The drainer registers by CONSTANT, not by literal, so match either — the
        // property under test is "a handler exists", not how it spells the name.
        foreach ([
            ['JOB_RECEIPT',  CheckoutMailer::JOB_RECEIPT,  'the buyer never gets a receipt'],
            ['JOB_DISPATCH', WebhookService::JOB_DISPATCH, 'integrations are never notified'],
            ['JOB',          DisputeAlert::JOB,            'nobody is told about a chargeback'],
            // The two that arrived when the webhook learned to confirm the OTHER two revenue
            // streams. Both send email, so an unregistered handler is a buyer who paid and
            // was never told — which is the failure this whole area exists to prevent.
            ['JOB_RECEIPT',  ShopOrderService::JOB_RECEIPT, 'a shop buyer never gets a receipt'],
            ['JOB',          EventTicketMailer::JOB,        'a ticket buyer is never sent their ticket'],
        ] as [$constant, $literal, $consequence]) {
            $this->assertTrue(
                str_contains($drainer, '::' . $constant) || str_contains($drainer, $literal),
                'no handler for ' . $literal . ' — ' . $consequence
            );
        }
    }

    /**
     * The shop and the ticket paths are inside the budget too, now.
     *
     * Both used to send inline, which was correct while only a browser could confirm them —
     * nobody is on a gateway's clock on a page a human is waiting for. The webhook now
     * confirms both, so the same SMTP call that was free is 12 seconds of a ~30-second budget,
     * on top of the up-to-15 the verify may already have spent.
     */
    public function test_the_shop_confirm_path_queues_its_receipt(): void
    {
        $src = $this->src('src/Services/ShopOrderService.php');

        $this->assertStringContainsString('JOB_RECEIPT', $src,
            'the shop receipt is being sent inside the gateway webhook');
        $this->assertStringContainsString('WebhookService::dispatchLater(', $src);
        $this->assertDoesNotMatchRegularExpression('/WebhookService::dispatch\(/', $src,
            'an outbound integration is being posted to inside the gateway webhook');
    }

    /** And the webhook queues the ticket email rather than sending it. */
    public function test_the_webhook_queues_the_ticket_email(): void
    {
        $src = $this->src('src/Controllers/PaymentController.php');

        $this->assertStringContainsString('EventTicketMailer::queue(', $src,
            'the ticket email is being sent inside the gateway webhook — SMTP is up to 12s '
            . 'of a ~30s budget');
        $this->assertStringNotContainsString('EventTicketMailer::send(', $src);
    }

    /**
     * ── AND THE HANDLER MUST NEVER 500 ───────────────────────────────────────
     *
     * A 500 is a delivery Paystack retries every three minutes and then hourly for 72 hours,
     * against a handler that has just proved it throws. Answering 200 and recording the
     * failure is strictly better: the sweep picks the payment up regardless, which is what the
     * sweep is for.
     */
    public function test_a_throwing_handler_still_acknowledges_the_delivery(): void
    {
        $src = $this->src('src/Controllers/PaymentController.php');

        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable \$e\) \{.*?withStatus\(200\)/s', $src,
            'a handler exception escapes as a 5xx, which puts the delivery into a 72-hour '
            . 'retry schedule against code that is known to throw');
    }

    /**
     * Queueing the receipt is only safe because sending it twice sends one email.
     * The queue is at-least-once by design, so this is load-bearing rather than
     * incidental.
     */
    public function test_sending_the_receipt_twice_sends_one_email(): void
    {
        $id = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Ada', 'donor_email' => 'ada@example.com', 'amount_naira' => 1000,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 5,
            'payment_ref' => 'AFG-PVOTE-receipt01', 'status' => 'confirmed',
            'created_at' => '2026-08-01 10:00:00',
        ]);

        $first  = CheckoutMailer::receipt($id);
        $second = CheckoutMailer::receipt($id);

        // Whichever way the first call went, the SECOND must not repeat it. When the
        // first one sent, the claim blocks the second; when it could not send, the
        // claim is given back and the second reaches the same conclusion — never a
        // second email either way.
        if (!empty($first['sent'])) {
            $this->assertFalse((bool) ($second['sent'] ?? false),
                'the same queued job ran twice and sent two receipts');
            $this->assertSame('already_sent', $second['reason'] ?? null);
        } else {
            $this->assertSame($first['reason'] ?? null, $second['reason'] ?? null,
                'a retry of the same job reached a different conclusion');
        }
    }

    // ── 2 · a chargeback reaches a human ────────────────────────────────────

    public function test_a_dispute_queues_an_alert_from_the_webhook(): void
    {
        $src = $this->src('src/Controllers/PaymentController.php');

        $this->assertStringContainsString('DisputeAlert::queue(', $src,
            'a chargeback still only writes a log line, and Paystack concedes it in 16 hours');
    }

    /** It fires even when the reference matches no order we hold. */
    public function test_an_unmatched_dispute_still_alerts(): void
    {
        DisputeAlert::queue('AFG-PVOTE-neverseen', 'charge.dispute.create', 'paystack', null);

        $this->assertSame(1, DB::table('gates_jobs')->where('type', DisputeAlert::JOB)->count(),
            'a dispute we cannot tie to an order is the case MOST in need of a person');
    }

    /** One dispute, one alert, however many times the gateway tells us. */
    public function test_repeated_notices_about_one_dispute_do_not_bury_the_desk(): void
    {
        foreach (range(1, 4) as $ignored) {
            DisputeAlert::queue('AFG-PVOTE-same0001', 'charge.dispute.create', 'paystack', 5000);
        }

        $this->assertSame(1, DB::table('gates_jobs')->where('type', DisputeAlert::JOB)->count());
    }

    /**
     * The alert names the DEADLINE as a time, and says what happens if it passes.
     *
     * "16 hours" asks somebody who has just been handed bad news to do arithmetic.
     * And a chargeback notice that does not say "doing nothing loses the money" is
     * describing an event rather than prompting an action.
     */
    public function test_the_alert_names_the_deadline_and_the_cost_of_ignoring_it(): void
    {
        DisputeAlert::send([
            'reference' => 'AFG-PVOTE-abc0001', 'event' => 'charge.dispute.create',
            'provider'  => 'paystack', 'amount' => 5000,
            'seen_at'   => '2026-08-10 09:00:00',
        ], null);

        $t = DB::table('gates_support_tickets')->orderByDesc('id')->first();
        $this->assertNotNull($t, 'no ticket was opened, so a mail failure means nobody knows');

        // 09:00 + 16h = 01:00 the next day, stated rather than left to be worked out.
        $this->assertStringContainsString('2026-08-11 01:00:00', (string) $t->subject);
        $this->assertStringContainsString('AFG-PVOTE-abc0001', (string) $t->transcript);
        $this->assertStringContainsString('accepts the dispute for you', (string) $t->transcript);
    }

    /**
     * THE TICKET MUST BE URGENT, and that is not a cosmetic label.
     *
     * SupportAutoResolver skips `urgent` tickets and works everything else. The
     * severity classifier reads a member's own words, which is right for a member
     * and wrong for a machine-written chargeback notice: this body contains "money"
     * and "refunded" and classifies as `high`, which would have left the support
     * assistant free to reply to an internal chargeback ticket.
     */
    public function test_a_chargeback_ticket_is_urgent_so_no_machine_answers_it(): void
    {
        DisputeAlert::send([
            'reference' => 'AFG-PVOTE-abc0002', 'event' => 'charge.dispute.create',
            'provider'  => 'paystack', 'amount' => 5000,
        ], null);

        $t = DB::table('gates_support_tickets')->orderByDesc('id')->first();
        $this->assertSame('urgent', (string) $t->severity);

        // And the classifier really would have said otherwise, so the override is
        // doing work rather than agreeing with the default.
        $this->assertNotSame('urgent', SupportTicketService::severity((string) $t->transcript),
            'the text alone reads as urgent, so this test proves nothing about the override');
    }

    /** An explicit severity is honoured, and a nonsense one is ignored. */
    public function test_the_severity_override_is_whitelisted(): void
    {
        $svc = new SupportTicketService();

        $svc->open('Something ordinary happened.', [], new \AfricaGates\Services\SupportContext(null, null),
                   [], ['severity' => 'not-a-severity']);
        $bad = DB::table('gates_support_tickets')->orderByDesc('id')->first();

        $this->assertContains((string) $bad->severity, ['urgent', 'high', 'normal'],
            'an arbitrary string became a severity the queue cannot filter on');
    }
}
