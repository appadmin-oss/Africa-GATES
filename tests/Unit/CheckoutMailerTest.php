<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CheckoutMailer;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A transport that records instead of sending, and can be told to fail.
 *
 * Subclasses the real OtpService so the production call signature is exercised — a
 * hand-written double would let a changed argument list pass here and fail live.
 */
final class RecordingMailer extends OtpService
{
    /** @var list<array{to:string, subject:string, html:string, text:string, category:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function __construct(bool $fail = false)
    {
        parent::__construct(['username' => 'u', 'password' => 'p']);
        $this->fail = $fail;
    }

    public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0): array
    {
        if ($this->fail) return ['success' => false, 'error' => 'connection refused'];
        $this->sent[] = compact('to', 'subject', 'category') + ['html' => $htmlBody, 'text' => $plainBody];
        return ['success' => true];
    }
}

/**
 * The two emails a checkout owes a buyer — neither of which existed.
 *
 * ── WHAT WAS REPORTED ────────────────────────────────────────────────────────
 *
 *     "I also need an email to be sent automatically to those who initiate payment
 *      without completion + status sent to those that vote. I also noticed that
 *      there's no emails being sent to voters"
 *
 * Both halves were true, for different reasons:
 *
 *  • A PAID vote sent nothing, ever. mint() bumped the tally and dispatched a webhook;
 *    the callback minted and redirected. No receipt, no reference, no record. On a site
 *    with free voting disabled the OTP mail is refused at the boundary with a 403, so
 *    the paid path is the ONLY vote path — and it was silent end to end.
 *  • A buyer who reached the gateway and stopped heard nothing. The pending row sat
 *    there until someone read `cycles:audit` by hand.
 *
 * These tests pin the behaviour that is expensive to get wrong: sending exactly once
 * when three callers race, never congratulating a buyer on votes they do not have, and
 * never telling somebody who paid that they did not.
 */
class CheckoutMailerTest extends TestCase
{
    private RecordingMailer $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new RecordingMailer();
        CheckoutMailer::using($this->mail);
        $this->seedCycle();
    }

    private function seedCycle(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'gates', 'title' => 'GATES Awards', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $cyc = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-10 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cyc, 'slug' => 'music', 'title' => 'Music of the Year', 'sort_order' => 1,
        ]);
        DB::table('gates_nominees')->insert([
            'id' => 5, 'category_id' => $cat, 'name' => 'Ada Obi', 'status' => 'approved',
        ]);
    }

    /** @param array<string,mixed> $over */
    private function order(array $over = []): int
    {
        // `$over` FIRST: with `+`, the left operand's keys win, so defaults on the left
        // would silently ignore every override — which is exactly the bug this comment
        // is standing on top of.
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name'        => 'A Supporter',
            'donor_email'       => 'buyer@example.test',
            'amount_naira'      => 7500,
            'tier'              => 'paid-vote',
            'bonus_votes'       => 75,
            'votes_used'        => 75,
            'intent_nominee_id' => 5,
            'payment_ref'       => 'AFG-PVOTE-' . bin2hex(random_bytes(4)),
            'status'            => 'confirmed',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    private function ago(string $spec): string
    {
        return date('Y-m-d H:i:s', strtotime($spec));
    }

    /* ══════════════════════════════════════════════════════════════════════
       THE RECEIPT
    ══════════════════════════════════════════════════════════════════════ */

    public function test_a_paid_voter_finally_receives_a_receipt(): void
    {
        $id  = $this->order();
        $ref = (string) DB::table('gates_donations')->where('id', $id)->value('payment_ref');

        $r = CheckoutMailer::receipt($id);

        $this->assertTrue($r['sent']);
        $this->assertCount(1, $this->mail->sent);
        $m = $this->mail->sent[0];

        $this->assertSame('buyer@example.test', $m['to']);
        // Everything a buyer needs to recognise the purchase and to dispute it.
        $this->assertStringContainsString('75', $m['subject']);
        $this->assertStringContainsString('Ada Obi', $m['subject']);
        $this->assertStringContainsString('₦7,500', $m['html']);
        $this->assertStringContainsString($ref, $m['html'], 'the reference is what a refund or a query is made against');
        $this->assertStringContainsString('Music of the Year', $m['html']);
        // Categorised, so gates_mail_log can answer "are buyers getting receipts?".
        $this->assertSame('Paid votes', $m['category']);
    }

    /**
     * THE RACE THIS COLUMN EXISTS FOR.
     *
     * A paid-vote order confirms from the browser callback, from the signature-verified
     * gateway webhook, and from `payments:reconcile` — any two of which can land within
     * the same second. Without a claim, one payment produces two or three receipts.
     */
    public function test_one_payment_produces_exactly_one_receipt_however_many_callers_confirm(): void
    {
        $id = $this->order();

        $first  = CheckoutMailer::receipt($id);   // browser callback
        $second = CheckoutMailer::receipt($id);   // gateway webhook, a second later
        $third  = CheckoutMailer::receipt($id);   // payments:reconcile, later still

        $this->assertTrue($first['sent']);
        $this->assertFalse($second['sent']);
        $this->assertSame('already_sent', $second['reason']);
        $this->assertFalse($third['sent']);
        $this->assertCount(1, $this->mail->sent, 'a buyer must never receive two receipts for one payment');
    }

    /**
     * THE STATE THAT MUST NOT BE CELEBRATED.
     *
     * `votes_used = 0` on a CONFIRMED order is the platform's existing "paid but never
     * minted — refund owed" signal: mint() refuses to push weighted votes into a cycle
     * that closed between payment and confirmation. A receipt thanking that buyer for
     * votes they do not have would be the platform lying about a payment.
     */
    public function test_a_paid_order_whose_votes_never_landed_is_told_the_truth(): void
    {
        $id = $this->order(['votes_used' => 0]);

        $r = CheckoutMailer::receipt($id);
        $this->assertTrue($r['sent']);
        $this->assertSame('unminted', $r['kind']);

        $m = $this->mail->sent[0];
        $this->assertStringContainsString('could not be added', $m['subject'] . ' ' . $m['html']);
        $this->assertStringContainsString('refund', strtolower($m['html']));
        // And it must NOT claim the votes are counted.
        $this->assertStringNotContainsString('already counted', $m['html']);
        $this->assertStringNotContainsString('votes are in', strtolower($m['subject']));
    }

    public function test_a_confirmed_and_minted_order_never_mentions_a_refund(): void
    {
        CheckoutMailer::receipt($this->order());

        $this->assertStringNotContainsString('refund', strtolower($this->mail->sent[0]['html']),
            'the happy receipt must not read like a problem');
    }

    /**
     * A failed send GIVES THE CLAIM BACK.
     *
     * Claiming before sending is what stops duplicates; never releasing it would mean a
     * transient SMTP failure permanently loses the receipt for a completed payment,
     * which is the worse of the two failures.
     */
    public function test_a_transport_failure_leaves_the_receipt_re_sendable(): void
    {
        $id = $this->order();

        CheckoutMailer::using(new RecordingMailer(fail: true));
        $failed = CheckoutMailer::receipt($id);

        $this->assertFalse($failed['sent']);
        $this->assertSame('send_failed', $failed['reason']);
        $this->assertNull(DB::table('gates_donations')->where('id', $id)->value('receipt_sent_at'),
            'the claim must be released so a retry can send');

        // A later retry with a working transport delivers it.
        CheckoutMailer::using($this->mail);
        $this->assertTrue(CheckoutMailer::receipt($id)['sent']);
        $this->assertCount(1, $this->mail->sent);
    }

    public function test_a_receipt_is_never_sent_for_an_order_that_does_not_warrant_one(): void
    {
        foreach ([
            'not_confirmed' => ['status' => 'pending'],
            'refunded'      => ['refunded_at' => date('Y-m-d H:i:s')],
            'not_paid_vote' => ['tier' => 'donation'],
            'no_email'      => ['donor_email' => 'not-an-address'],
        ] as $expected => $over) {
            $r = CheckoutMailer::receipt($this->order($over));
            $this->assertFalse($r['sent'], $expected);
            $this->assertSame($expected, $r['reason']);
        }
        $this->assertSame([], $this->mail->sent);
        $this->assertFalse(CheckoutMailer::receipt(999999)['sent']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       THE ABANDONED-CHECKOUT RECOVERY
    ══════════════════════════════════════════════════════════════════════ */

    public function test_an_abandoned_paid_vote_checkout_gets_one_recovery_email(): void
    {
        $id = $this->order(['status' => 'pending', 'votes_used' => 0, 'created_at' => $this->ago('-2 hours')]);

        $r = CheckoutMailer::sweepAbandoned();

        $this->assertSame(1, $r['considered']);
        $this->assertSame(1, $r['sent']);
        $m = $this->mail->sent[0];
        $this->assertStringContainsString('Ada Obi', $m['subject']);
        $this->assertStringContainsString('75', $m['html']);
        // A link straight back to the ballot, not the site root.
        $this->assertStringContainsString('/vote/gates/5-ada-obi', $m['html']);
        $this->assertSame('Checkout', $m['category']);
        $this->assertNotNull(DB::table('gates_donations')->where('id', $id)->value('abandoned_mail_at'));
    }

    /**
     * THE SENTENCE THIS EMAIL MUST NOT CONTAIN.
     *
     * A pending row means no SUCCESSFUL verification was ever seen — NOT that no money
     * moved. A dropped callback on a real payment produces exactly the same row. So the
     * copy must never assert the buyer was not charged; it invites the correction and
     * gives them the reference to quote.
     */
    public function test_the_recovery_email_never_claims_the_buyer_was_not_charged(): void
    {
        $id  = $this->order(['status' => 'pending', 'votes_used' => 0, 'created_at' => $this->ago('-2 hours')]);
        $ref = (string) DB::table('gates_donations')->where('id', $id)->value('payment_ref');

        CheckoutMailer::sweepAbandoned();
        $html = strtolower($this->mail->sent[0]['html']);

        foreach (['not charged', 'no money', 'nothing was taken', 'you were not billed'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, 'this may be false: the payment may have succeeded');
        }
        $this->assertStringContainsString('if your bank shows a charge', $html);
        $this->assertStringContainsString($ref, $this->mail->sent[0]['html'],
            'they need the reference to have a real conversation about it');
    }

    /** A buyer still at the gateway is not chased. */
    public function test_a_checkout_inside_the_grace_period_is_left_alone(): void
    {
        $this->order(['status' => 'pending', 'votes_used' => 0, 'created_at' => $this->ago('-5 minutes')]);

        $r = CheckoutMailer::sweepAbandoned();
        $this->assertSame(0, $r['considered']);
        $this->assertSame([], $this->mail->sent, 'someone typing their bank OTP must not be told they gave up');
    }

    /**
     * The window is what makes the FIRST run safe.
     *
     * This ships to a database already holding every pending row ever written. Without a
     * lower bound, the first maintenance tick would try to mail all of them.
     */
    public function test_an_ancient_abandoned_checkout_is_not_resurrected(): void
    {
        $this->order(['status' => 'pending', 'votes_used' => 0, 'created_at' => $this->ago('-30 days')]);

        $this->assertSame(0, CheckoutMailer::sweepAbandoned()['considered']);
        $this->assertSame([], $this->mail->sent);
    }

    public function test_the_recovery_email_is_sent_at_most_once_ever(): void
    {
        $this->order(['status' => 'pending', 'votes_used' => 0, 'created_at' => $this->ago('-2 hours')]);

        // The sweep runs on every maintenance tick — roughly every fifteen minutes.
        CheckoutMailer::sweepAbandoned();
        CheckoutMailer::sweepAbandoned();
        CheckoutMailer::sweepAbandoned();

        $this->assertCount(1, $this->mail->sent, 'a nudge that repeats every tick is spam, not recovery');
    }

    /**
     * THE MISTAKE THAT WOULD COST A CUSTOMER.
     *
     * Every press of "pay" writes its own pending row, so a supporter who bounced off the
     * gateway once and succeeded on the second attempt leaves a pending row AND a
     * confirmed one. Telling a paying customer they did not pay is worse than not
     * mailing at all.
     */
    public function test_a_buyer_who_completed_on_a_later_attempt_is_never_told_they_did_not(): void
    {
        $abandoned = $this->order(['status' => 'pending', 'votes_used' => 0, 'created_at' => $this->ago('-3 hours')]);
        $this->order(['status' => 'confirmed', 'created_at' => $this->ago('-2 hours')]); // same email, succeeded

        $r = CheckoutMailer::sweepAbandoned();

        $this->assertSame(0, $r['sent']);
        $this->assertSame(1, $r['reasons']['completed_elsewhere'] ?? 0);
        $this->assertSame([], $this->mail->sent);
        // Claimed anyway, so the sweep stops reconsidering it every tick.
        $this->assertNotNull(DB::table('gates_donations')->where('id', $abandoned)->value('abandoned_mail_at'));
    }

    /** A confirmed order is not an abandoned one, whatever else is true of it. */
    public function test_a_confirmed_order_is_never_swept(): void
    {
        $this->order(['created_at' => $this->ago('-4 hours')]);

        $this->assertSame(0, CheckoutMailer::sweepAbandoned()['considered']);
    }

    public function test_a_dry_run_sends_nothing_and_claims_nothing(): void
    {
        $id = $this->order(['status' => 'pending', 'votes_used' => 0, 'created_at' => $this->ago('-2 hours')]);

        $r = CheckoutMailer::sweepAbandoned(40, true);

        $this->assertTrue($r['dry_run']);
        $this->assertSame(1, $r['sent'], 'the count is what WOULD be sent');
        $this->assertSame([], $this->mail->sent);
        $this->assertNull(DB::table('gates_donations')->where('id', $id)->value('abandoned_mail_at'),
            'a dry run that claims rows silently suppresses the real send that follows it');
    }

    public function test_a_non_vote_pending_payment_gets_generic_copy_not_a_ballot_link(): void
    {
        $this->order([
            'status' => 'pending', 'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
            'intent_nominee_id' => null, 'created_at' => $this->ago('-2 hours'),
        ]);

        CheckoutMailer::sweepAbandoned();
        $m = $this->mail->sent[0];

        $this->assertStringNotContainsString('Ada Obi', $m['html'], 'a gift is not a vote for anybody');
        $this->assertStringContainsString('₦7,500', $m['html']);
        $this->assertStringContainsString('/donate', $m['html']);
    }

    public function test_the_batch_limit_is_respected(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->order([
                'status' => 'pending', 'votes_used' => 0,
                'donor_email' => "buyer{$i}@example.test",
                'created_at' => $this->ago('-2 hours'),
            ]);
        }

        $this->assertSame(2, CheckoutMailer::sweepAbandoned(2)['sent']);
        $this->assertCount(2, $this->mail->sent);
    }

    /* ══════════════════════════════════════════════════════════════════════
       DIAGNOSIS — the reason "no emails are being sent" went unanswered
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Every send path is best-effort by design, so a total delivery outage is
     * indistinguishable from normal operation from the outside. This is the check that
     * settles it.
     */
    public function test_status_says_plainly_when_no_email_has_ever_been_delivered(): void
    {
        $s = CheckoutMailer::status();

        $this->assertStringStartsWith('NEVER', (string) $s['last_successful_send']);
        $this->assertArrayHasKey('receipts_owed', $s);
        $this->assertArrayHasKey('abandoned_awaiting_mail', $s);
    }

    public function test_status_counts_the_buyers_who_have_paid_and_been_told_nothing(): void
    {
        $this->order();                                     // confirmed, no receipt yet
        $this->order(['refunded_at' => date('Y-m-d H:i:s')]); // refunded — owed nothing
        $this->order(['status' => 'pending', 'votes_used' => 0]);

        $this->assertSame(1, CheckoutMailer::status()['receipts_owed']);
    }

    public function test_a_delivered_send_is_visible_in_the_audit_log(): void
    {
        // The real transport writes gates_mail_log; the recording double does not, so
        // this asserts the plumbing the doctor check reads.
        DB::table('gates_mail_log')->insert([
            'to_masked' => 'bu***@example.test', 'subject' => 'x', 'category' => 'Paid votes',
            'status' => 'sent', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $s = CheckoutMailer::status();
        $this->assertSame(1, $s['mail_sent_24h']);
        $this->assertStringNotContainsString('NEVER', (string) $s['last_successful_send']);
    }

    /* ══════════════════════════════════════════════════════════════════════
       WIRING — every confirm path must send, or the feature is half-built
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * The receipt is worthless if only one of the three confirm paths sends it. The
     * webhook path is the one that MOST needs it: a webhook confirm means the browser
     * never came back, so the buyer never even saw the confirmation page.
     */
    public function test_every_place_that_confirms_a_paid_vote_also_sends_the_receipt(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'src/Controllers/PaidVoteController.php' => 'the browser callback',
            'src/Controllers/PaymentController.php'  => 'the gateway webhook',
            // Was PaymentReconcileCommand. The deciding moved into a service so the
            // admin console can run the same sweep from a browser; the command is now a
            // thin printer, and this is where the confirm actually happens.
            'src/Services/PaymentReconciler.php'     => 'the dropped-callback backstop',
        ] as $file => $what) {
            $src = (string) file_get_contents($root . '/' . $file);
            // Either spelling counts. queueReceipt() is the same receipt sent off the
            // request, which the gateway-webhook path must do: Paystack allows about 30
            // seconds for a whole delivery and SMTP alone can take 12. What this test
            // guards is that the path sends A receipt at all — that property is
            // unchanged, and the distinction between now and shortly is not this
            // test's business.
            $this->assertMatchesRegularExpression('/CheckoutMailer::(queueReceipt|receipt)\(/', $src,
                $what . ' confirms a paid-vote order but sends no receipt');
        }
    }

    /**
     * `payments:reconcile` confirmed paid-vote orders and STOPPED — money taken, no
     * votes minted, and indistinguishable from the deliberate "voting closed" refusal
     * the same column encodes. It is the backstop that exists FOR dropped callbacks and
     * it was the one path that did not mint.
     */
    public function test_the_reconcile_backstop_mints_the_votes_it_confirms(): void
    {
        // The backstop is PaymentReconciler now: the console command delegates to it and
        // so does the admin console's Check/Apply button. ONE implementation, so this
        // property cannot hold on one path and quietly not on the other.
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString('PaidVoteService::mint(',
            (string) file_get_contents($root . '/src/Services/PaymentReconciler.php'));
        $this->assertStringNotContainsString('PaidVoteService::mint(',
            (string) file_get_contents($root . '/src/Console/Commands/PaymentReconcileCommand.php'),
            'the command must delegate, not keep a second copy of the confirm logic');
    }

    /**
     * Reconciliation must run BEFORE the recovery mail on the same tick, or the first
     * thing a paying supporter whose callback was dropped receives is an email telling
     * them they did not pay.
     */
    public function test_maintenance_reconciles_payments_before_mailing_abandoned_carts(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $reconcile = strpos($src, "\$ran[] = ['payments',");
        $mail      = strpos($src, "\$ran[] = ['checkout-mail',");

        $this->assertNotFalse($reconcile, 'payments:reconcile is scheduled nowhere');
        $this->assertNotFalse($mail, 'the abandoned-checkout sweep is scheduled nowhere');
        $this->assertLessThan($mail, $reconcile,
            'confirm the genuinely-paid orders first, then chase whoever is still pending');
    }

    /** The voter confirmation must be categorised, or the delivery audit cannot see it. */
    public function test_the_free_vote_confirmation_is_categorised_for_the_mail_log(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/ApiController.php');

        $this->assertMatchesRegularExpression("~Your vote for \{\\\$nomName\} is confirmed~", $src);
        $this->assertMatchesRegularExpression("~— Africa GATES\",\s*\n\s*'Votes'~", $src,
            "without a category, gates_mail_log cannot answer 'are voters receiving anything?'");
    }

    /** The claim columns are what "exactly once" rests on. */
    public function test_the_claim_columns_exist_in_both_schemas(): void
    {
        foreach (['receipt_sent_at', 'abandoned_mail_at'] as $col) {
            $this->assertTrue(DB::schema()->hasColumn('gates_donations', $col), $col);
        }
        $mysql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $this->assertStringContainsString('receipt_sent_at', $mysql);
        $this->assertStringContainsString('abandoned_mail_at', $mysql);
    }
}
