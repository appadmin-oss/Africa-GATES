<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{SupportContext, SupportKnowledge};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Written from one real conversation that went wrong.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TRANSCRIPT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   Support: Ask away…
 *   Support: Passed to the team — your reference is AGS-9B5DE7.
 *   User:    Hi! I voted but it is not reflecting on site
 *   Support: …I'm a support assistant and I don't have access to your account
 *            information. However, I can try to help you troubleshoot…
 *            can you please provide the reference number for your payment?
 *   User:    Here's the reference AFG-PVOTE-957ef35ed73d
 *
 * Four separate faults in five lines, and each one is a test below:
 *
 *   1. A TICKET WAS OPENED BEFORE THE USER SPOKE. "Passed to the team" is the
 *      second line of the conversation. A ticket with no content is a promise to
 *      reply to nothing, and it arrived with a reference the reader will now
 *      quote at people.
 *
 *   2. IT DENIED ACCESS IT HAS. It opened by describing its own limitations to
 *      somebody who had just lost votes — the worst possible first sentence.
 *
 *   3. IT WAS TAUGHT THE WRONG REFERENCE SHAPE. The briefing said references
 *      look like `paystack_6413965117_hw8rf`. They do not: ours all start with
 *      AFG-, and `paystack_…` is what a WALLET APP shows for the same payment.
 *      The user's real reference, when it finally came, was AFG-PVOTE-….
 *
 *   4. IT DEMANDED A PAYMENT REFERENCE FOR A FREE VOTE. "I voted but it is not
 *      reflecting" mentions no payment. Most votes here are free and have no
 *      reference at all, so it asked for something that does not exist.
 */
final class SupportConversationFaultsTest extends TestCase
{
    private function guest(): SupportContext
    {
        return new SupportContext(null, null, false, null);
    }

    // ── 3. the reference shape ───────────────────────────────────────────────

    public function test_our_own_reference_formats_are_recognised(): void
    {
        foreach ([
            'AFG-PVOTE-957ef35ed73d',            // bought votes — the one from the transcript
            'AFG-GIVE-1a2b3c4d5e6f',             // a donation
            'AFG-SHP-0f0f0f0f0f0f',              // a shop order
            'AFG-0123456789abcdef',              // older generic
        ] as $ref) {
            $r = $this->guest()->run('check_reference', ['reference' => $ref]);
            $this->assertTrue($r['data']['ok'], $ref . ' is one of ours');
            $this->assertSame('ours', $r['data']['shape']);
        }
    }

    public function test_a_wallet_apps_own_number_is_named_as_theirs_not_denied(): void
    {
        // The exact string from the incident screenshot — OPay's merchant order
        // number for a Paystack charge. It is REAL. It is simply not ours, and
        // "no payment with that reference" reads as us denying a payment they are
        // holding the receipt for.
        $r = $this->guest()->run('check_reference', ['reference' => 'paystack_6413965117_hw8rf'])['data'];

        $this->assertFalse($r['ok'], 'nothing in the database matches this one');
        $this->assertSame('gateway', $r['shape']);
        $this->assertStringContainsString('AFG-', $r['say'], 'and it must say where ours is');
        // The instruction not to deny it, whatever its current wording.
        $this->assertMatchesRegularExpression('/do NOT say the payment (does not exist|cannot be found)/i',
            $r['say'], 'the model must still be told never to deny a real payment');
    }

    /**
     * And when the gateway's number DOES match something, directions are the wrong
     * answer — we can just tell them.
     *
     * The old version of check_reference read the SHAPE and stopped, so a number
     * Paystack put on the buyer's own receipt got a paragraph of homework. See
     * PaymentLookup: it resolves now, so the tool answers instead of instructing.
     */
    public function test_a_gateway_number_we_can_actually_match_is_answered_not_deflected(): void
    {
        $ours = 'AFG-PVOTE-abcdef123456';
        $id = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Ada', 'donor_email' => 'ada@example.com', 'amount_naira' => 1000,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 5,
            'payment_ref' => $ours, 'status' => 'confirmed', 'created_at' => '2026-08-01 10:00:00',
        ]);
        DB::table('gates_donations')->where('id', $id)->update(['gateway_txn_id' => '6413965117']);

        $r = $this->guest()->run('check_reference', ['reference' => '6413965117'])['data'];

        $this->assertTrue($r['ok'], 'a number we can match must not be deflected');
        $this->assertSame('resolved', $r['shape']);
        $this->assertSame($ours, $r['reference'], 'and it hands back the reference the repair needs');
        $this->assertStringContainsString($ours, $r['say']);
    }

    public function test_a_bare_transaction_number_is_also_recognised_as_theirs(): void
    {
        // "My transaction number is 26080114030026346148821" — a real ticket.
        $r = $this->guest()->run('check_reference', ['reference' => '26080114030026346148821'])['data'];
        $this->assertSame('gateway', $r['shape']);
    }

    public function test_a_repair_on_somebody_elses_reference_refuses_before_it_looks(): void
    {
        // Checked BEFORE the lookup, so the answer is directions rather than a
        // denial. Running it would fail truthfully and uselessly.
        $r = $this->guest()->run('fix_payment', ['reference' => 'paystack_6413965117_hw8rf'])['data'];

        $this->assertSame('NOT_OUR_REFERENCE', $r['outcome']);
        $this->assertStringContainsString('AFG-', $r['say']);
    }

    public function test_the_briefing_no_longer_teaches_the_wrong_shape(): void
    {
        $brief = SupportKnowledge::brief($this->guest());

        $this->assertStringContainsString('AFG-PVOTE-', $brief);
        $this->assertStringContainsString('THE WRONG REFERENCE', $brief);
        $this->assertDoesNotMatchRegularExpression(
            '/reference like `paystack_/', $brief,
            'the briefing used to hand the model a wallet number as OUR format'
        );
    }

    // ── 4. the free vote ─────────────────────────────────────────────────────

    public function test_free_vote_help_leads_with_the_discriminating_question(): void
    {
        $r = $this->guest()->run('free_vote_help')['data'];

        $this->assertStringContainsString('free vote', $r['ask_first']);
        $this->assertStringContainsString('no payment and no reference', $r['ask_first'],
            'asking a free voter for a reference is asking for something that does not exist');
    }

    public function test_the_commonest_free_vote_cause_is_listed_first(): void
    {
        $causes = $this->guest()->run('free_vote_help')['data']['causes'];

        $this->assertStringContainsString('code was never entered', $causes[0]['cause'],
            'leaving the page at the code step feels exactly like having voted');
        // And the refusal is explained as correct behaviour rather than a fault.
        $this->assertStringContainsString('integrity system working', $causes[1]['do']);
    }

    public function test_free_vote_help_says_whether_votes_are_being_recorded_at_all(): void
    {
        // "your vote is not there" and "nothing is being recorded" are different
        // problems with different owners, and the assistant cannot tell them apart
        // by asking the person in front of it.
        $r = $this->guest()->run('free_vote_help')['data'];
        $this->assertArrayHasKey('free_votes_last_hour', $r);
        $this->assertIsInt($r['free_votes_last_hour']);
    }

    public function test_the_briefing_teaches_the_free_vote_case_before_the_payment_one(): void
    {
        $brief = SupportKnowledge::brief($this->guest());

        $this->assertStringContainsString('MOST VOTES ON THIS PLATFORM ARE FREE', $brief);
        $this->assertStringContainsString('NOBODY MENTIONED PAYING', $brief);
        // And the playbook routes it correctly.
        $this->assertStringContainsString('ASK FIRST: paid, or the free', $brief);
    }

    // ── 2. never opening with its own limitations ────────────────────────────

    public function test_the_briefing_forbids_the_opening_it_actually_used(): void
    {
        $brief = SupportKnowledge::brief($this->guest());

        $this->assertStringContainsString('NEVER OPEN BY DESCRIBING YOUR OWN LIMITATIONS', $brief);
        // Quoted from the transcript so the rule is unmistakable. Matched on a
        // fragment because the rule is wrapped across lines in the briefing.
        $this->assertStringContainsString("I'm a support assistant and I don't have", $brief);
        // Matched without the line break: the rule is wrapped in the heredoc, so
        // asserting the whole sentence would be asserting the indentation.
        $this->assertStringContainsString('does not need to know the shape of your permissions', $brief);
    }

    // ── 1. no ticket before anything is said ─────────────────────────────────

    public function test_the_escalate_button_asks_before_it_files(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/support-assistant.twig');

        // The placeholder that produced a blank ticket in the transcript.
        $this->assertStringNotContainsString('A visitor asked to speak to someone.', $js);
        $this->assertMatchesRegularExpression(
            '/if \(!last\) \{\s*this\.push\(/s', $js,
            'with nothing said yet it must ask, not open a ticket'
        );
    }

    public function test_the_server_still_refuses_an_empty_escalation(): void
    {
        // The UI guard is a courtesy; this is the one that holds when somebody
        // posts the endpoint directly.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/SupportController.php');
        $this->assertMatchesRegularExpression(
            "/if \\(\\\$message === ''\\) \\{\\s*return \\\$this->json\\(\\\$res, \\['ok' => false, 'message' => 'Describe the problem first/",
            $src
        );
    }

    // ── and the tools are offered to the person who needs them ───────────────

    public function test_a_guest_is_offered_both_new_tools(): void
    {
        $names = array_column($this->guest()->tools(), 'name');

        $this->assertContains('check_reference', $names);
        $this->assertContains('free_vote_help', $names,
            'the person with a free vote problem is very often not signed in');
    }
}
