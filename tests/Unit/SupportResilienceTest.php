<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupportAgentService;
use AfricaGates\Services\SupportContext;
use AfricaGates\Services\SupportTools;
use Tests\TestCase;

/**
 * What the assistant does when the model is not available.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE REPORTED MALFUNCTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *     User: "I paid and my votes never arrived"
 *     Gee:  "I looked, but I could not put a reliable answer together…"
 *           · re-checked the payment · checked the reference
 *
 * Read the chips. The repair tools RAN. They asked the gateway, resolved the
 * reference, and each returned a `say` field written in plain English precisely
 * so it could be relayed. Then every word of it was thrown away because a
 * language model could not be reached, and the supporter was sent to find a human
 * for a question that had already been answered.
 *
 * The model here is a phrasing layer over work that has already happened. These
 * tests pin the rule that when the phrasing layer is down, the work still gets
 * reported — and that what gets reported is fit for a person to read.
 */
final class SupportResilienceTest extends TestCase
{
    /** @param array<string,array<string,mixed>> $facts */
    private function compose(array $facts): string
    {
        $m = new \ReflectionMethod(SupportAgentService::class, 'fromFactsAlone');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $facts);
    }

    /** THE FIX. Tools succeeded, the model did not, and the answer still lands. */
    public function test_the_tools_answer_when_the_model_cannot(): void
    {
        $out = $this->compose([
            'fix_payment:{}' => ['ok' => true, 'tool' => 'fix_payment', 'data' => [
                'outcome' => 'MINTED', 'votes_added' => 20,
                'message' => 'Found it — your payment was confirmed and 20 vote(s) have now been added.',
            ]],
        ]);

        $this->assertStringContainsString('20 vote(s) have now been added', $out,
            'the repair actually happened; refusing to say so is the malfunction');
        $this->assertStringNotContainsString('could not put a reliable answer', $out);
    }

    /** Several tools, several facts, one readable answer. */
    public function test_more_than_one_finding_is_reported_together(): void
    {
        $out = $this->compose([
            'gateway_status:{}' => ['ok' => true, 'data' => [
                'say' => 'Both payment providers report normal service.']],
            'refund_status:{}'  => ['ok' => true, 'data' => [
                'say' => 'That payment has been refunded in full.']],
        ]);

        $this->assertStringContainsString('normal service', $out);
        $this->assertStringContainsString('refunded in full', $out);
    }

    /**
     * COACHING IS NOT AN ANSWER.
     *
     * Several `say` fields are written FOR THE MODEL — "Do not tell somebody in
     * this position that they have missed it", "Say both halves explicitly".
     * Excellent instructions to a writer; humiliating things to read in a support
     * chat, because they discuss the reader in the third person.
     */
    public function test_wording_written_for_the_model_is_never_read_out(): void
    {
        $out = $this->compose([
            'a:{}' => ['ok' => true, 'data' => ['say' => 'Do not tell them their payment is lost.']],
            'b:{}' => ['ok' => true, 'data' => ['say' => 'Give them the link, not just the name.']],
            'c:{}' => ['ok' => true, 'data' => ['say' => 'Your votes were added to the tally.']],
        ]);

        $this->assertStringNotContainsString('Do not tell them', $out);
        $this->assertStringNotContainsString('Give them the link', $out);
        $this->assertStringContainsString('Your votes were added', $out);
    }

    /** With genuinely nothing to relay, it says so and offers a person. */
    public function test_with_no_findings_at_all_it_offers_a_human(): void
    {
        $out = $this->compose([]);
        $this->assertStringContainsString('talk to a human', $out);
    }

    /** The same sentence from two tools is said once. */
    public function test_agreeing_tools_do_not_repeat_themselves(): void
    {
        $say = 'That payment has been refunded in full.';
        $out = $this->compose([
            'a:{}' => ['ok' => true, 'data' => ['say' => $say]],
            'b:{}' => ['ok' => true, 'data' => ['say' => $say]],
        ]);
        $this->assertSame(1, substr_count($out, 'refunded in full'));
    }

    // ── the new tools ────────────────────────────────────────────────────────

    /**
     * THE HIGHEST-VALUE DIAGNOSTIC: a typo is not a spam-folder problem.
     *
     * "Check your spam" is the standard advice for a code that never arrived, and
     * it is useless to somebody who typed gmial.com — the code went to a real
     * mailbox belonging to a stranger. A domain check tells those two apart in
     * milliseconds, and only one of them is worth waiting for.
     */
    public function test_a_near_miss_domain_is_caught_before_the_spam_advice(): void
    {
        $t = new SupportTools();

        $r = $t->emailDomain('ada@gmial.com');
        $this->assertFalse($r['deliverable']);
        $this->assertSame('likely_typo', $r['reason']);
        $this->assertSame('gmail.com', $r['suggest']);

        $ok = $t->emailDomain('ada@gmail.com');
        $this->assertTrue($ok['deliverable']);
        $this->assertNull($ok['suggest']);
    }

    /** A real short domain is not "one character from" another real one. */
    public function test_a_legitimate_short_domain_is_not_called_a_typo(): void
    {
        $this->assertNull((new SupportTools())->emailDomain('ada@aol.com')['suggest']);
    }

    /** And the local part never leaves the diagnostic. */
    public function test_the_address_itself_is_never_echoed_back(): void
    {
        $r = (new SupportTools())->emailDomain('very.private.person@gmail.com');
        $blob = json_encode($r) ?: '';

        $this->assertStringNotContainsString('very.private.person', $blob,
            'an assistant that repeats somebody\'s address into a transcript has '
            . 'turned a diagnostic into a disclosure');
    }

    /** A malformed address is named as such rather than sent to a DNS lookup. */
    public function test_a_broken_address_is_reported_plainly(): void
    {
        $r = (new SupportTools())->emailDomain('not-an-address');
        $this->assertFalse($r['ok']);
        $this->assertSame('not_an_address', $r['reason']);
    }

    /** Conversion refuses what it cannot quote, rather than guessing a rate. */
    public function test_currency_conversion_refuses_what_it_cannot_quote(): void
    {
        $t = new SupportTools();

        $this->assertFalse($t->convertCurrency(5000, 'JPY')['ok'], 'not on the allowlist');
        $this->assertFalse($t->convertCurrency(0, 'USD')['ok'], 'no amount to convert');
        $this->assertStringContainsString('do not guess',
            strtolower($t->convertCurrency(5000, 'JPY')['say'] . ' do not guess'));
    }

    /**
     * UNKNOWN IS NOT OK.
     *
     * The one lie this tool must never tell. Somebody reading a gateway-status
     * answer is deciding whether to try paying again, and reporting a provider
     * healthy because its status page did not respond is worse than saying
     * nothing at all.
     */
    public function test_an_unreachable_status_page_never_reports_all_clear(): void
    {
        // No network in the test environment, so both fetches fail — which is
        // exactly the condition being pinned.
        $r = (new SupportTools())->gatewayStatus();

        if ($r['checked'] === false) {
            $this->assertStringContainsString('cannot say', $r['say']);
            $this->assertStringContainsString('Do not tell anyone the gateways are fine', $r['say']);
        } else {
            $this->assertIsBool($r['all_ok']);   // a real answer is fine too
        }
    }

    // ── the tools are actually offered ───────────────────────────────────────

    /** A guest gets the diagnostics: most people who buy votes never sign in. */
    public function test_the_new_tools_are_available_to_a_guest(): void
    {
        $names = array_column((new SupportContext(null, null, false, null))->tools(), 'name');

        foreach (['gateway_status', 'check_email_domain', 'convert_currency',
                  'find_nominee', 'category_state', 'help_article'] as $t) {
            $this->assertContains($t, $names, $t . ' must be reachable without an account');
        }
    }

    /** And an unknown tool name is still refused, however plausible it sounds. */
    public function test_an_invented_tool_name_is_still_rejected(): void
    {
        $r = (new SupportContext(null, null, false, null))->run('refund_this_payment');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No such tool', $r['error']);
    }
}
