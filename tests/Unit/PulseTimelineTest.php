<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * THE PULSE IS A TIMELINE, NOT A GALLERY.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT WAS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every post — text, media, or a released award — was a 316px dark canvas with the
 * words set at 1.85rem display type, the byline laid over the picture beneath them, and
 * a vertical action rail down the right-hand edge. On a phone the whole feed was taken
 * out of flow: `position:fixed`, each post exactly `100dvh`, `scroll-snap-stop:always`,
 * the page forced to black, and the summary strip, the reply thread, the composer and
 * the sign-in prompt all `display:none`.
 *
 * It was built to an Instagram redesign mockup and it was faithful to it. It is the
 * wrong shape for this platform, and two consequences say so better than any argument:
 *
 *   · A RESULT could not be read. The standing, the two halves and the denominator do
 *     not fit on a screen that holds one post and scrolls past it — so the most
 *     significant thing this platform produces arrived as a paragraph of poster type
 *     with the index as a clause in a sentence.
 *   · THE COMMENT BUTTON OPENED NOTHING. `.pf__cm` was hidden on phones, so tapping
 *     reply on the device most of this audience reads on did nothing at all.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE PINS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The card is Alpine-driven, so it cannot be rendered here — Twig emits the template and
 * the browser builds the card. What CAN be pinned is the structural contract, and it is
 * the part a later edit would silently undo: three shapes chosen by what a post IS, one
 * rail rather than two, a result panel that reads the typed payload rather than parsing
 * prose, and a phone that gets the same column as everything else.
 */
final class PulseTimelineTest extends TestCase
{
    private const TWIG   = __DIR__ . '/../../templates/pages/pulse.twig';
    private const MOBILE = __DIR__ . '/../../public/assets/css/components/pulse-immersive.css';

    private static function twig(): string
    {
        return (string) file_get_contents(self::TWIG);
    }

    /**
     * The template with its Twig comments blanked.
     *
     * Every comment here quotes the thing it replaced — that is the house style, and it
     * is also how a scanner comes to report a fix as the fault. Five times in this
     * repository, in five files.
     */
    private static function code(): string
    {
        return (string) preg_replace('~\{#.*?#\}~s', ' ', self::twig());
    }

    // ══ three shapes, chosen by what the post is ═════════════════════════════

    public function test_a_card_is_shaped_by_what_the_post_actually_is(): void
    {
        $src = self::code();

        $this->assertStringContainsString("it.kind === 'result' ? 'pf--result' : 'pf--text'", $src,
            'the card no longer chooses a shape — every post is drawn the same way again');

        foreach (['pf__head' => 'the byline above a light post',
                  'pf__text' => 'a post that is words',
                  'pf__res'  => 'a post that is a released award',
                  'pf__stage--media' => 'a post that is a photograph'] as $cls => $what) {
            $this->assertStringContainsString($cls, $src, $what . ' has no markup');
        }
    }

    /**
     * ONE RAIL.
     *
     * The same markup and the same handlers sit over the picture on a media card and in
     * flow beneath a light one; only the CSS moves it. Two rails would be two optimistic
     * rollbacks to keep in step, which is exactly the fault `ag-social.js` was extracted
     * to prevent — one surface quietly stops putting a failed like back and shows a
     * reader a reaction the server never recorded.
     */
    public function test_there_is_one_action_rail_and_not_one_per_card_shape(): void
    {
        $this->assertSame(1, substr_count(self::code(), '<div class="pf__rail">'),
            'the action rail has been copied per card shape');

        // And it is a child of the card rather than of the media stage, which is what
        // lets one piece of markup sit in both places.
        $this->assertStringContainsString('.pf{ position:relative;', self::twig(),
            'the card is no longer the positioned ancestor, so the rail cannot be '
            . 'absolutely placed over a photograph without being placed over the page');
    }

    /**
     * THE RESULT PANEL READS THE PAYLOAD, NEVER THE PROSE.
     *
     * `PulseFeedService` hands the feed the award, the winner, the index and the split
     * already separated. A panel that pulled any of it back out of `it.body` would be a
     * second parser of the same fact, and the first thing to disagree with the award's
     * own page.
     */
    public function test_the_result_panel_draws_the_typed_payload(): void
    {
        $src = self::code();

        $panel = substr($src, (int) strpos($src, 'class="pf__res"'));
        $panel = substr($panel, 0, (int) strpos($panel, '</a>'));

        foreach (['it.result.programme', 'it.result.award', 'it.result.winner',
                  'it.result.cpi', 'it.result.community', 'it.result.judges',
                  'it.result.runner_up'] as $field) {
            $this->assertStringContainsString($field, $panel,
                'the result panel does not draw ' . $field);
        }

        $this->assertStringNotContainsString('it.body', $panel,
            'the result panel reads the post body — the figures are typed for it, and a '
            . 'second reading of the same fact is the first thing to disagree with the '
            . 'award\'s own page');
    }

    /**
     * THE BAR IS NEVER THE ONLY CARRIER OF A NUMBER.
     *
     * It is `aria-hidden`, and every figure it draws is written out beside it. A bar says
     * nothing to a screen reader and nothing to a reader who cannot separate two greens —
     * and this one is the platform's whole argument about itself, so it has to survive
     * being read rather than looked at.
     */
    public function test_the_split_is_written_out_and_not_only_drawn(): void
    {
        $src = self::code();

        $this->assertMatchesRegularExpression('~class="pf__resbar"[^>]*aria-hidden~', $src,
            'the index bar is exposed to a screen reader as content');
        $this->assertStringContainsString("' community'", $src);
        $this->assertStringContainsString("' judges'", $src);
        $this->assertStringContainsString("' / 1000'", $src);
    }

    // ══ the column ═══════════════════════════════════════════════════════════

    /**
     * HAIRLINES, NOT A MARCH OF BOXES.
     *
     * A timeline is one continuous surface with a rule between entries; the alternative
     * spends most of the reader's attention on box edges. It also roughly doubles how
     * much of the feed fits on a laptop screen.
     *
     * The journal entries are held to the same rule. Below a run of hairline-divided
     * posts a rounded bordered card does not read as an article — it reads as an ad,
     * because "a bordered box among plain entries" is what an ad unit looks like
     * everywhere else on the web.
     */
    public function test_the_feed_is_one_divided_column(): void
    {
        $src = self::twig();

        $this->assertStringContainsString('.pl-feed{ display:flex; flex-direction:column; gap:0;', $src,
            'the feed has a gap again, which puts a stripe of page between every rule '
            . 'and its neighbour');

        foreach (['.pf{' => 'a member post', '.pl-post{' => 'a journal entry',
                  '.pl-compose{' => 'the composer'] as $rule => $what) {
            $at   = (int) strpos($src, $rule);
            $decl = substr($src, $at, (int) strpos($src, '}', $at) - $at);
            $this->assertStringContainsString('border-bottom', $decl,
                $what . ' has no divider, so it floats instead of belonging to the column');
            $this->assertStringNotContainsString('border-radius:22px', $decl,
                $what . ' is a rounded card again');
        }
    }

    // ══ and a phone gets the same thing ══════════════════════════════════════

    /**
     * THE PHONE IS NOT A DIFFERENT PRODUCT.
     *
     * The mobile stylesheet used to take the feed out of flow entirely — fixed, one post
     * per `100dvh`, snapping — which is why a result could not be read on it and why the
     * reply thread had to be hidden. It fits the column to a small screen now; it does
     * not replace it.
     */
    public function test_a_phone_gets_the_timeline_rather_than_a_snap_feed(): void
    {
        $css = (string) file_get_contents(self::MOBILE);
        // Comments stripped, for the same reason as above: this file's own header quotes
        // every rule it removed.
        $code = (string) preg_replace('~/\*.*?\*/~s', ' ', $css);

        foreach (['position:fixed'      => 'the feed is out of flow again',
                  'scroll-snap-type'    => 'the feed snaps again',
                  'scroll-snap-align'   => 'posts snap again',
                  '100dvh'              => 'a post is a screen tall again'] as $needle => $what) {
            $this->assertStringNotContainsString($needle, self::feedRules($code),
                $what . ' — a released award cannot be read one screen at a time');
        }

        // The conversation and the composer are back. Each was `display:none !important`
        // here, so on a phone the reply button opened a block the stylesheet had hidden
        // and the composer this page is built around did not exist at all.
        foreach (['.pf__cm', '.pl-compose', '.pf__sum'] as $sel) {
            $this->assertStringNotContainsString($sel . '{ display:none', $code);
            $this->assertDoesNotMatchRegularExpression(
                '~' . preg_quote($sel, '~') . '[^{}]*\{[^}]*display:none~', $code,
                $sel . ' is hidden on phones again — the reply button opens nothing');
        }
    }

    /** Only the rules that govern the feed column, so `position:fixed` on a floating
     *  toast or a sticky tab strip is not mistaken for the feed being taken out of flow. */
    private static function feedRules(string $code): string
    {
        $out = '';
        foreach (['.pl-feed', '.pf{', '.pf ', '.pf__stage'] as $sel) {
            $at = 0;
            while (($at = strpos($code, $sel, $at)) !== false) {
                $open  = strpos($code, '{', $at);
                $close = $open === false ? false : strpos($code, '}', $open);
                if ($close === false) break;
                $out .= substr($code, $at, $close - $at + 1) . "\n";
                $at = $close;
            }
        }

        return $out;
    }
}
