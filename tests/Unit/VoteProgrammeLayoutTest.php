<?php
declare(strict_types=1);
namespace Tests\Unit;

use Tests\TestCase;

/**
 * The programme ballot on a narrow screen.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE ARE ASSERTED IN CSS RATHER THAN IN A BROWSER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every fault below was found by measuring a real render at six widths, and each
 * one has a single line of CSS as its cause. A headless browser in the suite
 * would catch them again but would also be the slowest and flakiest thing in it,
 * and would fail for reasons — a font that did not load, a 1px rounding
 * difference — that have nothing to do with the bug.
 *
 * So the measurement stays a manual step and the CAUSE is pinned here. If
 * somebody deletes one of these rules, this fails and names the fault, which is
 * the part that would otherwise be rediscovered by a nominee on a phone.
 */
final class VoteProgrammeLayoutTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote-program.twig');
    }

    public function test_the_headline_banner_honours_hidden(): void
    {
        // `hidden` is a UA rule of the lowest possible specificity, so the class
        // rule's `display:inline-flex` beat it — and a category with no contest
        // to describe rendered an empty gold pill containing an icon and no
        // words, at every width, on every such category.
        $this->assertMatchesRegularExpression(
            '/\.vp-close\[hidden\]\s*\{[^}]*display\s*:\s*none/',
            $this->css(),
            'restore this and the ghost banner comes back'
        );
    }

    public function test_the_headline_banner_is_not_a_capsule(): void
    {
        // A 999px radius is only a capsule while the text fits on one line.
        // "Mrs Chinelo Eze leads Mr Tunde Adeyemi by 136 votes" wraps below about
        // 420px and became a lozenge with the words bulging out of the curve.
        $this->assertDoesNotMatchRegularExpression(
            '/\.vp-close\{[^}]*border-radius\s*:\s*999px/',
            $this->css()
        );
        $this->assertMatchesRegularExpression(
            '/\.vp-close\{[^}]*align-items\s*:\s*flex-start/',
            $this->css(),
            'the trend icon belongs on the first line, not the middle of a two-line block'
        );
    }

    public function test_the_rank_is_centred_rather_than_nudged_by_a_magic_number(): void
    {
        // `align-self:flex-start; padding-top:20px` lined the rank up with the
        // avatar only at the exact card height the desktop layout produced. On a
        // narrow screen the name, the school and the footer each wrap or not
        // depending on length, so the rank drifted between 3px and 37px from the
        // avatar — a different offset in every card of the same list.
        $this->assertDoesNotMatchRegularExpression(
            '/\.vp-rank\{[^}]*padding-top\s*:\s*20px/',
            $this->css()
        );
        $this->assertMatchesRegularExpression(
            '/\.vp-rank\{[^}]*align-self\s*:\s*center/',
            $this->css(),
            'the card is already align-items:center, so this needs nothing tuned'
        );
    }

    public function test_the_vote_link_wraps_for_every_card_or_for_none(): void
    {
        // It wrapped for 8 of 12 cards at 360px — whether "Vote →" fitted came
        // down to how many digits the vote count had. A list where the same
        // control sits on a different line in each row reads as broken layout
        // rather than as a responsive one, so below 420px it wraps for all of
        // them, deliberately.
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*419px\)\s*\{[^}]*\.vp-card__cta\{[^}]*flex-basis\s*:\s*100%/s',
            $this->css()
        );
    }
}
