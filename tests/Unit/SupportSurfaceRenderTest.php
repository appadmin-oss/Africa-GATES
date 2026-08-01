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

    public function test_the_pressure_points_all_include_it(): void
    {
        // Named explicitly: this is the list that stops the prompt quietly
        // disappearing from the page it matters most on during a redesign.
        $root = dirname(__DIR__, 2) . '/templates/pages/';
        foreach ([
            'pay-success.twig'       => 'the wallet payer who is told only "awaiting confirmation"',
            'vote-paid-success.twig' => 'the buyer whose votes were not minted',
            'vote-nominee.twig'      => 'the ballot, where they are one tap from paying twice',
            'error.twig'             => 'a 500 catches somebody mid-payment',
            'status.twig'            => 'green board, missing money — the most frustrating combination there is',
        ] as $file => $why) {
            $this->assertStringContainsString(
                "partials/support-prompt.twig", (string) file_get_contents($root . $file),
                $file . ': ' . $why
            );
        }
    }

    public function test_the_unconfirmed_payment_page_hands_over_its_reference(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/pay-success.twig');

        $this->assertMatchesRegularExpression(
            "/support-prompt\.twig' with \{ sp_kind: 'payment', sp_ref: reference/", $src,
            'the whole point is that the reader never retypes the reference'
        );
    }

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

    public function test_the_nominee_brief_appears_once_and_is_never_truncated(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote-nominee.twig');

        // It belongs in the About card, where there is room for it, and NOT in
        // the hero as well — printing it twice made the reader meet the same
        // paragraph again forty pixels later, and pushed the vote count and the
        // CTA down the page on a phone.
        $this->assertSame(1, substr_count($src, '{{ n.tagline }}') + substr_count($src, '{{ _brief }}'),
            'the brief is printed exactly once');
        $this->assertStringNotContainsString('vn-bioline', $src,
            'the hero copy is gone, and so is the CSS that positioned it');

        // And nothing is destroyed on the way there.
        $this->assertStringNotContainsString('n.tagline|slice', $src);
        $this->assertStringNotContainsString('n.tagline|u.truncate', $src);
        $this->assertStringContainsString('overflow-wrap:anywhere', $src,
            'a pasted URL must break rather than widen the column');
    }

    public function test_a_registry_bio_no_longer_swallows_the_nomination_brief(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote-nominee.twig');

        // The brief is the one place a nominee's case is stated in their
        // nominator's words. It used to vanish entirely whenever a registry
        // profile existed, because the bio REPLACED it rather than joining it.
        $this->assertStringNotContainsString("(profile and profile.bio) ? profile.bio : n.tagline", $src);
        $this->assertStringContainsString('_brief != _bio', $src,
            'and when the two are the same text, only one of them prints');
    }
}
