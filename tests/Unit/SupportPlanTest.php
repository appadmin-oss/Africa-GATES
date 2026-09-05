<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupportContext;
use AfricaGates\Services\SupportPlan;
use Tests\TestCase;

/**
 * Choosing the right tool with no model in the loop.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS DEFENDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The assistant's twenty-four tools are deterministic code — they re-check a
 * payment against Paystack, credit votes, resend a receipt, read the live
 * deadlines. Every one of them used to sit behind an AI provider being configured
 * and reachable, so a site with no API key had a support desk that could only
 * apologise, and an unattended ticket queue that returned 0 from its first line.
 *
 * SupportPlan is the planner half of a model-free turn. The bar it has to clear
 * is not coverage — it is PRECISION. A wrong tool is worse than no tool:
 * fix_payment on a number lifted out of an unrelated sentence spends the repair
 * rate limit and answers a question nobody asked, and asking a free voter for a
 * payment reference that never existed is the specific failure people describe as
 * "the bot is useless".
 */
final class SupportPlanTest extends TestCase
{
    private function guest(): SupportContext
    {
        return new SupportContext(null, null);
    }

    /** @return list<string> */
    private function tools(string $message, array $only = []): array
    {
        return array_column(SupportPlan::steps($message, $this->guest(), $only), 'tool');
    }

    // ── the case the whole thing exists for ──────────────────────────────────

    /**
     * "I paid and my votes never arrived", with a reference. This is the platform's
     * commonest support message and it has a two-second fix.
     */
    public function test_a_reference_and_a_complaint_plans_the_repair(): void
    {
        $t = $this->tools('I paid but my votes have not appeared. Ref AFG-PVOTE-957ef35ed73d');

        $this->assertContains('fix_payment', $t);
        $this->assertSame('AFG-PVOTE-957ef35ed73d',
            SupportPlan::steps('I paid but my votes have not appeared. Ref AFG-PVOTE-957ef35ed73d',
                               $this->guest())[0]['args']['reference'] ?? null);
    }

    /** Proof, not reassurance: vote_proof reads the live rows and returns a URL. */
    public function test_it_also_plans_the_proof_the_person_can_check_themselves(): void
    {
        $this->assertContains('vote_proof',
            $this->tools('my votes are missing, reference AFG-PVOTE-957ef35ed73d'));
    }

    /**
     * A reference at the end of a sentence arrives with a full stop stuck to it far
     * more often than not, and `AFG-PVOTE-957ef35ed73d.` matches nothing.
     */
    public function test_a_trailing_full_stop_is_not_part_of_the_reference(): void
    {
        $this->assertSame('AFG-PVOTE-957ef35ed73d',
            SupportPlan::reference('nothing came through for AFG-PVOTE-957ef35ed73d.'));
    }

    // ── the gateway's own number, which is what people actually have ─────────

    public function test_a_paystack_transaction_number_is_taken_as_the_reference(): void
    {
        $this->assertSame('4738291042',
            SupportPlan::reference('I paid 5000 and my debit alert says 4738291042'));
        $this->assertSame('paystack_6413965117_hw8rf',
            SupportPlan::reference('my payment reference is paystack_6413965117_hw8rf'));
    }

    /**
     * But only when the sentence is about money. A long number in any other
     * sentence is a phone number, an amount, a date or a vote count, and spending
     * the repair rate limit on it helps nobody.
     */
    public function test_a_long_number_in_an_unrelated_sentence_is_not_a_reference(): void
    {
        $this->assertNull(SupportPlan::reference('there are 12345678 people in this category'));
        $this->assertNull(SupportPlan::reference('my number is 08031234567'),
            'an 11-digit 0-prefixed number is a Nigerian mobile, not a transaction id');
    }

    // ── the free voter, who has no payment and no reference at all ───────────

    /**
     * Most votes on this platform are free. Answering one of those with a payment
     * tool asks somebody for a reference that never existed — and that specific
     * wrong turn is what makes an assistant feel useless.
     */
    public function test_a_free_vote_not_showing_never_plans_a_payment_tool(): void
    {
        $t = $this->tools('I voted for my sister yesterday and it is not showing');

        $this->assertContains('free_vote_help', $t);
        $this->assertNotContains('fix_payment', $t);
        $this->assertNotContains('vote_proof', $t);
    }

    public function test_a_free_voter_who_gives_an_email_gets_it_checked(): void
    {
        $this->assertContains('when_did_i_vote',
            $this->tools('my vote is not reflecting, I used ada@example.com'));
    }

    // ── an outage answers a hundred people at once ──────────────────────────

    /**
     * During a provider outage every buyer arrives with the same sentence, and
     * "what is your reference" is the wrong answer given a hundred times.
     */
    public function test_a_failed_payment_checks_the_gateway_first(): void
    {
        $this->assertSame('gateway_status',
            $this->tools('my payment failed and the card was declined')[0] ?? null);
    }

    // ── mail that never arrived ─────────────────────────────────────────────

    /**
     * Far more often a dead domain or a typo (gmial.com) than a spam folder — and
     * "check your spam folder" is useless advice for either.
     */
    public function test_a_missing_code_checks_the_address_that_received_nothing(): void
    {
        $t = $this->tools('my verification code never arrived at ada@gmial.com');

        $this->assertContains('check_email_domain', $t);
        $this->assertSame('ada@gmial.com',
            SupportPlan::email('my verification code never arrived at ada@gmial.com'));
    }

    // ── the three clocks, and the money ─────────────────────────────────────

    public function test_a_question_about_the_clock_reads_the_deadlines(): void
    {
        $this->assertContains('voting_deadlines',
            $this->tools('why can I not pay when voting is still open?'));
    }

    public function test_a_question_about_price_reads_the_pricing(): void
    {
        $this->assertContains('pricing', $this->tools('how much is one vote?'));
    }

    // ── naming a nominee ────────────────────────────────────────────────────

    public function test_an_explicit_vote_for_someone_finds_the_nominee(): void
    {
        $this->assertSame('Amara Okonkwo', SupportPlan::nominee('how do I vote for Amara Okonkwo?'));
        $this->assertContains('find_nominee', $this->tools('how do I vote for Amara Okonkwo?'));
    }

    /**
     * The tail is cut at the first word that starts a new clause. Without that,
     * "vote for Amara but the page is broken" searches the nominee list for
     * "Amara but the page is broken" and finds nobody.
     */
    public function test_the_nominee_name_stops_at_the_end_of_the_clause(): void
    {
        $this->assertSame('Amara', SupportPlan::nominee('I want to vote for Amara but the page is broken'));
    }

    public function test_a_pronoun_is_not_a_nominee_name(): void
    {
        $this->assertNull(SupportPlan::nominee('how do I vote for her'));
        $this->assertNull(SupportPlan::nominee('I cannot find my nominee'));
    }

    // ── what it refuses to do ───────────────────────────────────────────────

    /**
     * The allowlist is a guarantee, not a suggestion. It is how the unattended
     * resolver is confined to safe tools, and a plan that ignored it would hand
     * SupportAutoResolver a step it is not allowed to take.
     */
    public function test_the_allowlist_is_obeyed(): void
    {
        $t = $this->tools('my votes are missing, reference AFG-PVOTE-957ef35ed73d',
                          ['fix_payment', 'help_article']);

        $this->assertContains('fix_payment', $t);
        $this->assertNotContains('vote_proof', $t, 'out of scope for this caller');
    }

    /** A guest is never planned a tool that needs an identity they do not have. */
    public function test_a_guest_is_never_planned_a_members_only_tool(): void
    {
        $allowed = array_column($this->guest()->tools(), 'name');
        $this->assertNotContains('my_transactions', $allowed, 'the fixture must be a guest');

        foreach (['where is my payment', 'I was charged twice', 'show me my votes'] as $q) {
            foreach ($this->tools($q) as $tool) {
                $this->assertContains($tool, $allowed, $q . ' planned an unavailable tool: ' . $tool);
            }
        }
    }

    public function test_an_empty_message_plans_nothing(): void
    {
        $this->assertSame([], SupportPlan::steps('', $this->guest()));
        $this->assertSame([], SupportPlan::steps('   ', $this->guest()));
    }

    /**
     * canAct() is what stops the unattended queue posting a Help Centre link onto
     * somebody's ticket as though it were an answer.
     */
    public function test_reading_an_article_does_not_count_as_being_able_to_act(): void
    {
        $this->assertFalse(SupportPlan::canAct('how is the CPI score calculated?', $this->guest()));
        $this->assertTrue(SupportPlan::canAct('I paid and got nothing, AFG-PVOTE-957ef35ed73d',
                                              $this->guest()));
    }

    /** Three steps at most: enough for repair-then-prove, short enough to stay fast. */
    public function test_a_plan_stays_short(): void
    {
        $long = 'my payment failed and then I paid again but my votes are missing and the receipt '
              . 'never came to ada@example.com, reference AFG-PVOTE-957ef35ed73d, and how much is a '
              . 'vote anyway, and when does voting close?';

        $this->assertLessThanOrEqual(3, count(SupportPlan::steps($long, $this->guest())));
    }

    /** No tool is ever planned twice in one turn. */
    public function test_no_tool_is_planned_twice(): void
    {
        $t = $this->tools('I paid, refund me, my money is gone, AFG-PVOTE-957ef35ed73d');
        $this->assertSame(array_values(array_unique($t)), $t);
    }
}
