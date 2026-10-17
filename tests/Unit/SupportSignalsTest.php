<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{SupportContext, SupportKnowledge, SupportSignals};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Knowing what is happening right now.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS WORTH TESTING RATHER THAN JUST WRITING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The buyers in the incident this platform was fixed after were not unlucky
 * individuals — they were the visible edge of one broken webhook, and each of
 * them was told, in effect, that they were the only one. That is what an
 * assistant with no live awareness does: it troubleshoots an outage one person
 * at a time and sounds surprised every time.
 *
 * So the assertions here are about the DIFFERENCE the signal makes to what gets
 * said, not about the arithmetic. One stuck payment is a case. Four is an
 * incident, and an incident has to be named out loud.
 *
 * The other half is silence. A report that says "all normal" on every turn is
 * tokens spent teaching the model to skim the one section it must never skim,
 * so an uneventful platform has to produce nothing at all.
 */
final class SupportSignalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        DB::table('gates_support_tickets')->delete();
    }

    private function stuckPayment(int $n, string $when = '-10 minutes'): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_donations')->insert([
                'donor_name' => 'Buyer ' . $i, 'donor_email' => 'b' . $i . '@example.test',
                'amount_naira' => 2000, 'tier' => 'paid-vote', 'bonus_votes' => 10, 'votes_used' => 0,
                'payment_ref' => 'paystack_stuck_' . $i, 'status' => 'confirmed',
                'created_at' => date('Y-m-d H:i:s', strtotime($when)),
            ]);
        }
    }

    private function ticket(string $subject): void
    {
        DB::table('gates_support_tickets')->insert([
            'reference' => 'AGS-' . strtoupper(bin2hex(random_bytes(3))),
            'email' => 'x@example.test', 'subject' => $subject, 'transcript' => $subject,
            'severity' => 'normal', 'status' => 'open', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ── silence when there is nothing to say ─────────────────────────────────

    public function test_a_quiet_platform_raises_no_alarm(): void
    {
        // Asserted on the ABSENCE of incident language rather than on an empty
        // string: this environment has no gateway key and no SMTP host, so the
        // health probe correctly reports both as degraded and the report is not
        // empty. That is the signal working. What must not appear is any claim
        // that people's payments are stuck when none are.
        $brief = SupportSignals::brief();

        $this->assertStringNotContainsString('live incident', $brief);
        $this->assertStringNotContainsString('uncredited', $brief);
        $this->assertStringNotContainsString('support ticket', $brief);
        $this->assertStringNotContainsString('not imagining it', $brief);
    }

    public function test_a_degraded_service_is_named_so_it_is_never_called_working(): void
    {
        // Nothing is configured in the test environment, which is exactly the
        // state a half-deployed production host is in — and the failure mode is
        // an assistant cheerfully telling somebody payments are fine.
        $brief = SupportSignals::brief();

        $this->assertStringContainsString('DEGRADED', $brief);
        $this->assertStringContainsString('Do not tell anybody it is working', $brief);
    }

    // ── and a clear voice when there is ──────────────────────────────────────

    public function test_one_stuck_payment_is_a_case(): void
    {
        $this->stuckPayment(1);
        $brief = SupportSignals::brief();

        $this->assertStringContainsString('1 payment', $brief);
        $this->assertStringContainsString('Normal background level', $brief);
        $this->assertStringNotContainsString('live incident', $brief);
    }

    public function test_several_stuck_payments_are_an_incident_and_must_be_named(): void
    {
        $this->stuckPayment(6);
        $brief = SupportSignals::brief();

        $this->assertStringContainsString('6 payments', $brief);
        $this->assertStringContainsString('live incident', $brief);
        $this->assertStringContainsString('affecting other people', $brief,
            'the person must be told they are not alone — that is the whole point');
        $this->assertStringContainsString('fix_payment', $brief,
            'and repaired anyway, because the repair works per payment during an outage');
    }

    public function test_stale_stuck_payments_do_not_count_as_happening_now(): void
    {
        $this->stuckPayment(6, '-3 days');
        $this->assertStringNotContainsString('live incident', SupportSignals::brief(),
            'an incident last week is history, not a situation report');
    }

    public function test_a_shared_complaint_is_surfaced(): void
    {
        foreach (['Paystack payment missing', 'paystack charge not showing', 'My paystack order'] as $s) $this->ticket($s);
        $brief = SupportSignals::brief();

        $this->assertStringContainsString('paystack', $brief);
        $this->assertStringContainsString('not imagining it', $brief);
    }

    public function test_one_person_repeating_a_word_is_not_a_cluster(): void
    {
        // Counted per SUBJECT, not per occurrence: one person writing "payment"
        // four times in a sentence is not four people with a payment problem.
        $this->ticket('payment payment payment payment payment');
        $this->ticket('Something entirely different');
        $this->assertStringNotContainsString('not imagining it', SupportSignals::brief());
    }

    public function test_open_tickets_are_counted(): void
    {
        $this->ticket('A thing');
        $this->assertStringContainsString('1 support ticket', SupportSignals::brief());
    }

    public function test_a_cycle_closing_soon_is_a_warning_before_somebody_pays(): void
    {
        // Ids allocated, not chosen — gates_award_programmes.id is TINYINT.
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Closing Awards', 'slug' => 'closing-' . bin2hex(random_bytes(3)), 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-5 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+6 hours')),
        ]);

        $brief = SupportSignals::brief();
        $this->assertStringContainsString('Closing Awards', $brief);
        $this->assertStringContainsString('before they pay', $brief,
            'a payment that confirms after the close cannot be counted, so the warning has to come first');
    }

    // ── and it reaches the model ─────────────────────────────────────────────

    public function test_the_report_is_carried_into_the_briefing(): void
    {
        $this->stuckPayment(6);
        $brief = SupportKnowledge::brief(new SupportContext(null, null, false, null));

        $this->assertStringContainsString('WHAT IS HAPPENING RIGHT NOW', $brief);
        $this->assertStringContainsString('live incident', $brief);
    }

    public function test_the_briefing_survives_a_signal_that_cannot_be_read(): void
    {
        // One probe failing must not blank the report — or the incident detection
        // the oldest feature depends on disappears with the newest one's column.
        DB::statement('DROP TABLE gates_support_tickets');

        $brief = SupportKnowledge::brief(new SupportContext(null, null, false, null));
        $this->assertStringContainsString('ABOUT THE PLATFORM', $brief);
        $this->assertStringContainsString('WALLET APP', $brief);
    }

    public function test_the_numbers_are_readable_on_their_own(): void
    {
        $this->stuckPayment(3);
        $s = SupportSignals::read(fresh: true);

        $this->assertSame(3, $s['payments_stuck_1h']);
        $this->assertArrayHasKey('degraded', $s);
        $this->assertArrayHasKey('refunds_pending', $s);
    }
}
