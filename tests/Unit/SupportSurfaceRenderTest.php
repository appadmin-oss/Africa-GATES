<?php
declare(strict_types=1);
namespace Tests\Unit;

use Tests\TestCase;

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
 * that nothing here works. These tests pinned the link, not the wording.
 *
 * ── WHAT IS LEFT HERE, AND WHY ───────────────────────────────────────────────
 *
 * `partials/support-prompt.twig` was destroyed on 3 Oct 2026 as an orphan of the old
 * public pages (docs/handoff/DESTROYED.md). The eleven methods that rendered it went with
 * it, and their rules are in docs/handoff/inventory/_partials.md, which the rebuild
 * re-asserts. What remains tests the ticket thread, which survived.
 */
final class SupportSurfaceRenderTest extends TestCase
{
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
