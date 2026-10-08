<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A PROPOSAL THAT DESCRIBES ITSELF AS UNBUILT MUST STOP DOING SO ONCE IT IS BUILT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS WORTH A TEST AND NOT JUST AN EDIT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `docs/` is read by whoever is about to change the code, and a proposal is the one kind
 * of document that is read in order to ACT. Both of these sat months past their own
 * implementation still opening with a plan:
 *
 *   • the enterprise proposal led with three "integrity defects verified against code"
 *     that had all been fixed — a reader who trusted it would have gone looking for a
 *     `block` branch that is there, and a `device_hash` write that is there;
 *   • the partner-donations proposal said in its second line that nothing was built,
 *     while `/giving/{slug}`, `OrgBrand`, the organiser console, the fee split, the CAC
 *     vetting and the payout path were all live. That one is worse: acted on, it builds a
 *     second donations system beside a working one.
 *
 * This is §19's shape — prose outliving the thing it describes — on documents whose whole
 * purpose is to be acted on. The header each now carries is the fix; this stops the header
 * being quietly deleted, and stops a NEW proposal being added without a status anybody can
 * read.
 *
 * It deliberately checks the STATUS LINE and not the body. The bodies are left exactly as
 * written: a proposal rewritten to describe the world after its own implementation stops
 * being evidence of why any of it was done.
 */
final class SupersededProposalTest extends TestCase
{
    /** Every proposal in docs/, and whether it is still a plan. */
    private const PROPOSALS = [
        'ENTERPRISE-VOTING-NOMINATION-PROPOSAL.md',
        'PARTNER-DONATIONS-PROPOSAL.md',
    ];

    public function test_every_proposal_in_docs_declares_whether_it_is_still_a_plan(): void
    {
        foreach (self::listProposals() as $file) {
            $head = self::head($file);

            $this->assertMatchesRegularExpression(
                '~\*\*Status[:*]~i', $head,
                "docs/{$file} has no Status line in its first 20 lines. A proposal is read in "
                . 'order to act on it, so whether it is still a plan is the first thing it owes.'
            );
        }
    }

    /**
     * And the two that are built say so, rather than still reading as a plan.
     *
     * Pinned by name because these are the two that were found stale. A third proposal
     * added later is caught by the test above, which asks only that it state its status.
     */
    public function test_the_built_proposals_are_marked_superseded(): void
    {
        foreach (self::PROPOSALS as $file) {
            $head = self::head($file);

            $this->assertMatchesRegularExpression('~SUPERSEDED~', $head,
                "docs/{$file} is implemented and must say so above its first section");

            $this->assertDoesNotMatchRegularExpression('~Status:\**\s*Draft~i', $head,
                "docs/{$file} is built — it is not a draft for review");
        }
    }

    /**
     * The partner proposal's second line was the actively misleading one.
     *
     * "Nothing built yet" on a document whose subject IS built is the sentence that sends
     * somebody to build it twice.
     */
    public function test_no_proposal_claims_nothing_is_built(): void
    {
        foreach (self::listProposals() as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '~^(?!>).*Nothing built yet~mi', self::body($file),
                "docs/{$file} still claims nothing is built. If that became false, say so in "
                . 'the status line rather than leaving the claim standing.'
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function listProposals(): array
    {
        $out = [];
        foreach ((array) glob(self::dir() . '/*PROPOSAL*.md') as $path) {
            if (is_string($path)) $out[] = basename($path);
        }
        sort($out);
        return $out;
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/docs';
    }

    private static function body(string $file): string
    {
        return (string) file_get_contents(self::dir() . '/' . $file);
    }

    /** The first 20 lines — where a reader decides whether to act. */
    private static function head(string $file): string
    {
        return implode("\n", array_slice(explode("\n", self::body($file)), 0, 20));
    }
}
