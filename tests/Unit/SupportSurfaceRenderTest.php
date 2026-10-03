<?php
declare(strict_types=1);
namespace Tests\Unit;

use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The support offer, wherever it appears.
 *
 * ── WHY THIS IS TESTED AT ALL ────────────────────────────────────────────────
 *
 * Support that only lives at /support reaches only the people who already
 * believe somebody can help. The person whose payment has just stalled is
 * looking at a payment page, and what they do next — refresh, give up, or pay a
 * second time for votes they have already bought — is decided by what is on
 * THAT screen.
 *
 * So the prompt is a partial dropped onto the pressure points, and the thing
 * that makes it worth anything is that it carries the reference. Handing
 * somebody a bare support link asks them to copy forty characters by hand, which
 * is where a digit gets dropped, the lookup fails, and they conclude — fairly —
 * that nothing here works. These tests pin the link, not the wording.
 */
final class SupportSurfaceRenderTest extends TestCase
{
    private const REF = 'paystack_6413965117_hw8rf';

    private function twig(): Environment
    {
        $t = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => false]);
        // The two globals the partial reads. Supplied here rather than through the
        // container so this stays a template test and not a container test.
        $t->addGlobal('csp_nonce', 'test-nonce');
        $t->addGlobal('support_email', 'gates@afrovanguard.org.ng');
        return $t;
    }

    private function render(array $vars): string
    {
        return $this->twig()->render('partials/support-prompt.twig', $vars);
    }

    // ── the link ─────────────────────────────────────────────────────────────

    public function test_the_reference_travels_into_the_assistant(): void
    {
        $out = $this->render(['sp_kind' => 'payment', 'sp_ref' => self::REF]);

        $this->assertStringContainsString('/support/assistant?topic=payment', $out);
        $this->assertStringContainsString('ref=' . self::REF, $out);
        $this->assertStringContainsString('ask=1', $out,
            'with a reference there is a real repair to attempt, so it should just happen');
    }

    public function test_a_reference_is_url_encoded(): void
    {
        $out = $this->render(['sp_kind' => 'payment', 'sp_ref' => 'ref with spaces&x=1']);

        $this->assertStringNotContainsString('ref=ref with spaces', $out);
        $this->assertStringContainsString('ref=ref%20with%20spaces%26x%3D1', $out);
    }

    public function test_without_a_reference_it_does_not_auto_ask(): void
    {
        $out = $this->render(['sp_kind' => 'payment']);

        $this->assertStringContainsString('/support/assistant?topic=payment', $out);
        $this->assertStringNotContainsString('ask=1', $out,
            'firing off a vague question on the reader\'s behalf teaches them the assistant guesses');
        $this->assertStringContainsString('Open the assistant', $out);
    }

    public function test_the_call_to_action_changes_with_what_is_possible(): void
    {
        $this->assertStringContainsString('Re-check it now', $this->render(['sp_kind' => 'payment', 'sp_ref' => self::REF]));
        $this->assertStringContainsString('Open the assistant', $this->render(['sp_kind' => 'general']));
    }

    public function test_the_email_fallback_uses_the_configured_inbox(): void
    {
        $out = $this->render(['sp_kind' => 'payment', 'sp_ref' => self::REF]);

        $this->assertStringContainsString('mailto:gates@afrovanguard.org.ng', $out);
        $this->assertStringNotContainsString('support@afrovanguard.org.ng', $out);
    }

    public function test_the_compact_form_is_a_single_line_with_no_card(): void
    {
        $out = $this->render(['sp_compact' => true, 'sp_kind' => 'payment', 'sp_ref' => self::REF]);

        $this->assertStringContainsString('ag-sprompt--line', $out);
        $this->assertStringNotContainsString('<aside', $out);
    }

    /**
     * The compact line points at the philosophy, NOT at a mailbox.
     *
     * It is included in exactly one place — directly beneath the pay button on a
     * nominee's ballot — and there it used to print the support address in full. Three
     * problems, all specific to that position: an email is a strictly worse answer than
     * the assistant link beside it (which re-checks the payment with the gateway on the
     * spot), a long address in a narrow ballot column had nothing to wrap on and ran off
     * the edge of the panel on a phone, and a live mailto in public HTML on one of the
     * busiest pages on the platform is free food for address harvesters.
     *
     * What a reader with their thumb over a pay button actually wants is why a vote
     * carries a contribution at all.
     */
    public function test_the_compact_line_offers_the_philosophy_and_publishes_no_address(): void
    {
        $out = $this->render(['sp_compact' => true, 'sp_kind' => 'payment', 'sp_ref' => self::REF]);

        $this->assertStringContainsString('/philosophy', $out);
        $this->assertStringNotContainsString('mailto:', $out,
            'the compact line is back to publishing a mailbox under the pay button');
        $this->assertStringNotContainsString('afrovanguard.org.ng', $out,
            'the support address is in the page source again');
    }

    /** And a caller can point that clause somewhere else when the context differs. */
    public function test_the_compact_lines_second_clause_is_overridable(): void
    {
        $out = $this->render([
            'sp_compact'   => true,
            'sp_kind'      => 'account',
            'sp_alt_href'  => '/help/paid-votes',
            'sp_alt_label' => 'how paid votes are counted',
        ]);

        $this->assertStringContainsString('/help/paid-votes', $out);
        $this->assertStringContainsString('how paid votes are counted', $out);
        $this->assertStringNotContainsString('/philosophy', $out);
    }

    /**
     * The CARD form keeps its email, and that is not an inconsistency. It sits in a
     * wide layout under a heading that says what it is for, beside a primary action —
     * there is room for a second-best option there, and none under a pay button.
     */
    public function test_the_card_form_still_offers_the_inbox(): void
    {
        $out = $this->render(['sp_kind' => 'payment', 'sp_ref' => self::REF]);

        $this->assertStringContainsString('Email the team', $out);
        $this->assertStringContainsString('mailto:gates@afrovanguard.org.ng', $out);
    }

    public function test_every_kind_renders_rather_than_falling_through_to_nothing(): void
    {
        foreach (['payment', 'votes', 'account', 'general'] as $kind) {
            $out = $this->render(['sp_kind' => $kind]);
            $this->assertStringContainsString('/support/assistant?topic=' . $kind, $out, $kind);
            $this->assertMatchesRegularExpression('/<strong>\S/', $out, $kind . ' must have a heading');
        }
    }

    public function test_its_styles_travel_with_it(): void
    {
        // It is included on a dark ballot hero, a white success card and an error
        // page that loads no page CSS at all. The last of those has no stylesheet
        // to add a rule to, so the component carries its own.
        $out = $this->render(['sp_kind' => 'general']);

        $this->assertStringContainsString('<style nonce="test-nonce">', $out);
        $this->assertStringContainsString('.ag-sprompt{', $out);
        $this->assertStringContainsString('.ag-sprompt--dark', $out, 'and a dark-surface variant');
    }

    // ── where it is actually placed ──────────────────────────────────────────

    // ── the ticket thread ────────────────────────────────────────────────────

    public function test_an_escalated_conversation_opens_as_turns_not_as_one_blob(): void
    {
        // The transcript is STORED as "User: … / Support: …" because it is a
        // frozen snapshot. Rendering that as a single message attributed to the
        // member prints the literal word "User:" at somebody who knows who they
        // are, and puts the assistant's earlier replies in their mouth.
        $turns = \AfricaGates\Services\SupportTicketService::opening(
            "User: I paid and nothing came.\n\nSupport: Which reference was it?\n\nUser: paystack_1_x");

        $this->assertCount(3, $turns);
        $this->assertFalse($turns[0]['staff']);
        $this->assertSame('I paid and nothing came.', $turns[0]['body'], 'the label is not part of what they said');
        $this->assertTrue($turns[1]['staff']);
        $this->assertSame('paystack_1_x', $turns[2]['body']);
    }

    public function test_a_ticket_raised_directly_is_a_single_unlabelled_turn(): void
    {
        // Most tickets never went through the assistant, so there are no labels
        // to parse and the text must survive exactly as typed.
        $turns = \AfricaGates\Services\SupportTicketService::opening(
            "My votes have not arrived.\n\nI paid at 12:36 with OPay.");

        $this->assertCount(1, $turns);
        $this->assertFalse($turns[0]['staff']);
        $this->assertStringContainsString('OPay', $turns[0]['body']);
        $this->assertStringContainsString("\n\n", $turns[0]['body'], 'their paragraphs are theirs');
    }

    public function test_an_empty_transcript_produces_no_turns_rather_than_an_empty_bubble(): void
    {
        $this->assertSame([], \AfricaGates\Services\SupportTicketService::opening('   '));
    }

    // ── the nominee brief ────────────────────────────────────────────────────
}
