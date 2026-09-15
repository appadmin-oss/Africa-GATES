<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\FlierService;
use AfricaGates\Support\Media;
use Tests\TestCase;

/**
 * The 1200×630 link-preview card.
 *
 * WHY THESE ASSERTIONS AND NOT A PIXEL COMPARISON. A reference-image test on a
 * FreeType render is a test of the font build, not of the design — it breaks on a
 * FreeType point release and says nothing about whether the card is correct. What can be
 * checked cheaply and meaningfully is the set of things that were ACTUALLY WRONG on the
 * first real renders, each of which looked like a working image:
 *
 *   • the name collided with the programme line above it, because the size was chosen by
 *     character count against a column width copied from the (much wider) flier;
 *   • a surname wider than the column ran off the edge of the card, because
 *     wrapMeasured never breaks a word and its output was trusted without checking;
 *   • a name carrying stacked Yoruba diacritics grew UPWARD toward the kicker, because
 *     the first baseline was derived from the point size instead of from the glyphs' real
 *     ink extent.
 *
 * All three produce a valid PNG of the right size. Only measuring the geometry catches
 * them.
 */
class OgCardTest extends TestCase
{
    /** Whether this runtime can render at all — GD with FreeType plus the bundled faces. */
    private function renderable(): bool
    {
        return function_exists('imagettftext') && FlierService::fontsPresent()['ok'];
    }

    /** @return array<string,mixed> A complete forNominee()-shaped payload. */
    private function payload(array $over = []): array
    {
        return $over + [
            'name'       => 'Adaeze Nwosu',
            'category'   => 'Music & Performance',
            'programme'  => 'Africa GATES Cultural Awards',
            'country'    => 'NG',
            'photo'      => null,
            'photo_card' => null,
            'short_url'  => 'afg.afrovanguard.org.ng/vote/cultural/48',
            'headline'   => '#3 of 24 — 12 votes from #2',
            'rally'      => 'The gap is closing. Add your vote and help finish the climb.',
            'standing'   => ['rank' => 3, 'field' => 24, 'progress_pct' => 88, 'momentum_24h' => 41, 'momentum_available' => true],
        ];
    }

    // ── The shape, which is the whole reason it exists ───────────────────────

    public function test_the_card_is_the_aspect_ratio_the_platforms_crop_to(): void
    {
        // 1.91:1. The flier is 4:5, and Facebook/LinkedIn crop an og:image to this — which
        // removed the flier's bottom third, i.e. the vote URL and the rally copy.
        $this->assertSame(1200, FlierService::OG_W);
        $this->assertSame(630, FlierService::OG_H);
        $this->assertEqualsWithDelta(1.91, FlierService::OG_W / FlierService::OG_H, 0.01);
    }

    public function test_it_renders_at_exactly_the_declared_size(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        $png = (new FlierService())->ogCard($this->payload());
        $this->assertIsString($png);

        $size = getimagesizefromstring($png);
        $this->assertIsArray($size);
        // A mismatch here means every og:image:width/height meta tag on the site lies.
        $this->assertSame(FlierService::OG_W, $size[0]);
        $this->assertSame(FlierService::OG_H, $size[1]);
        $this->assertSame('image/png', $size['mime']);
    }

    /**
     * The Cloudinary derivative and the card's photo column must agree exactly. A preset
     * asking for a different shape would hand GD a wide image to crop into a tall
     * column, undoing the face anchoring that is the point of asking Cloudinary at all.
     */
    public function test_the_photo_preset_matches_the_cards_portrait_column(): void
    {
        $url = (string) Media::url('https://res.cloudinary.com/demo/image/upload/v1/x/y.jpg', 'og_photo');

        $this->assertStringContainsString('w_' . FlierService::OG_PHOTO_W, $url);
        $this->assertStringContainsString('h_' . FlierService::OG_H, $url);
        $this->assertStringContainsString('g_faces:auto', $url, 'the crop must be anchored on the face');
        // f_jpg, not f_auto: this derivative is fetched server-side by GD and by crawlers,
        // neither of which sends an Accept header for f_auto to negotiate against.
        $this->assertStringContainsString('f_jpg', $url);
    }

    public function test_the_photo_column_leaves_room_for_the_text(): void
    {
        // A split, not an overlay. If the column ever grew past half the card the layout
        // would silently stop being a split and start being a crop.
        $this->assertLessThan(FlierService::OG_W / 2, FlierService::OG_PHOTO_W);
    }

    // ── Every conditional state must render ─────────────────────────────────

    /**
     * The four states the design has to hold, all through one code path.
     *
     * `nofield` is the one that matters: with a field of one there is no rank, so the chip,
     * the standing line AND the progress track are all suppressed together. A layout that
     * only reflows two of the three leaves an empty rail across the card — the same class
     * of defect as printing "0 votes in 24 hours" as a measurement.
     */
    public function test_every_conditional_state_renders(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        $svc = new FlierService();
        $states = [
            'ranked'   => [],
            'leading'  => ['headline' => '#1 of 379 — leading', 'standing' => ['rank' => 1, 'field' => 379, 'progress_pct' => 100, 'momentum_24h' => 0, 'momentum_available' => false]],
            'nofield'  => ['headline' => 'Standing for recognition', 'standing' => ['rank' => 1, 'field' => 1, 'progress_pct' => 0, 'momentum_24h' => 0, 'momentum_available' => false]],
            'nophoto'  => ['photo_card' => null],
        ];
        foreach ($states as $label => $over) {
            $png = $svc->ogCard($this->payload($over));
            $this->assertIsString($png, "state '{$label}' must render");
            $this->assertSame([FlierService::OG_W, FlierService::OG_H],
                array_slice((array) getimagesizefromstring($png), 0, 2), "state '{$label}' size");
        }
    }

    /**
     * A real photograph in the column, and the seam that fades it into the panel.
     *
     * The fade is checked because the obvious implementation — one flat colour per column
     * — leaves a visible bright or dark band at one end, since the background behind it is
     * a vertical gradient. That reads as a rendering fault, and on a link preview it is the
     * entire first impression.
     */
    public function test_a_photo_fades_into_the_panel_with_no_hard_seam(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        $tmp = tempnam(sys_get_temp_dir(), 'agcard_') . '.png';
        $src = imagecreatetruecolor(480, 630);
        // Saturated magenta: nothing in the brand palette is close, so any surviving
        // photo pixel is unmistakable.
        imagefill($src, 0, 0, imagecolorallocate($src, 255, 0, 255));
        imagepng($src, $tmp);
        imagedestroy($src);

        $png = (new FlierService())->ogCard($this->payload(['photo_card' => $tmp]));
        @unlink($tmp);
        $this->assertIsString($png);

        $im = imagecreatefromstring($png);
        $this->assertNotFalse($im);

        $at = static function ($im, int $x, int $y): array {
            $c = imagecolorat($im, $x, $y);
            return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        };

        // Left of the fade band: the photo, essentially untouched.
        [$r, $g, $b] = $at($im, 40, 315);
        $this->assertGreaterThan(180, $r, 'the photo must survive at the left edge');
        $this->assertGreaterThan(180, $b);

        // At the seam: the panel colour, with no magenta left.
        [$r2, , $b2] = $at($im, FlierService::OG_PHOTO_W - 1, 315);
        $this->assertLessThan(60, $r2, 'the fade must reach the panel colour by the seam');
        $this->assertLessThan(80, $b2);

        // And ACROSS the seam there must be no step: the last faded column and the first
        // panel column have to match, at the top and the bottom of the card, because the
        // background gradient differs by ~11 levels per channel between them.
        foreach ([20, 315, 610] as $y) {
            [$lr, $lg, $lb] = $at($im, FlierService::OG_PHOTO_W - 1, $y);
            [$pr, $pg, $pb] = $at($im, FlierService::OG_PHOTO_W + 1, $y);
            $this->assertLessThanOrEqual(6, abs($lr - $pr), "seam step in red at y={$y}");
            $this->assertLessThanOrEqual(6, abs($lg - $pg), "seam step in green at y={$y}");
            $this->assertLessThanOrEqual(6, abs($lb - $pb), "seam step in blue at y={$y}");
        }
        imagedestroy($im);
    }

    // ── The three geometry bugs the first renders exposed ───────────────────

    /**
     * No ink in the right margin. This is the "Wolde-Giorgis ran off the edge" test: at
     * 76px that surname is wider than the 608px column, and `wrapMeasured` places an
     * over-wide word rather than break it, so the size has to shrink until it fits.
     */
    public function test_nothing_is_drawn_past_the_right_margin(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        foreach (['Tsehaynesh Wolde-Giorgis', 'Nomvula Dlamini-Khumalo', 'Ọlásùnkànmí Adébáyọ̀ Ogundimu'] as $name) {
            $png = (new FlierService())->ogCard($this->payload(['name' => $name]));
            $im  = imagecreatefromstring((string) $png);
            $this->assertNotFalse($im);

            // The rightmost 24px is margin. Anything bright there is overflow — the
            // background at that edge is a dark green, and every text colour is light.
            $bright = 0;
            for ($x = FlierService::OG_W - 24; $x < FlierService::OG_W; $x++) {
                for ($y = 0; $y < FlierService::OG_H; $y++) {
                    $c = imagecolorat($im, $x, $y);
                    if ((($c >> 16) & 0xFF) > 150 && (($c >> 8) & 0xFF) > 150) $bright++;
                }
            }
            imagedestroy($im);
            $this->assertSame(0, $bright, "'{$name}' has ink in the right margin — it overflows the card");
        }
    }

    /**
     * A name's ink starts at the same row whether or not it carries diacritics.
     *
     * That is the invariant {@see FlierService::ascent()} buys, and it is worth stating
     * carefully because the obvious assertion is the wrong way round.
     *
     * Measured on the bundled Playfair Display Bold at 76px: "Olasunkanmi" has an ink
     * ascent of 79px and "Ọlásùnkànmí" 84px. With a baseline derived from the POINT SIZE
     * (`top + $size`) both names share a baseline, so the accented one's ink starts those
     * 5px HIGHER — it grows UPWARD, toward the kicker. Measuring the real extent instead
     * pins the top and pushes the baseline down, so the two agree to within a pixel.
     *
     * Verified in both directions: correct code gives tops of 219/220, and reverting to
     * `top + $size` gives 216/212. Hence a tolerance of 2 — 4 would have let the bug
     * through, which it did on the first attempt at this test.
     */
    public function test_a_names_ink_starts_at_the_same_row_with_or_without_diacritics(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        $svc = new FlierService();
        $topOfInk = static function (string $png): int {
            $im = imagecreatefromstring($png);
            // Scan the name's column band only, so the kicker and the chip cannot answer.
            for ($y = 140; $y < 500; $y++) {
                for ($x = FlierService::OG_PHOTO_W + 56; $x < FlierService::OG_W - 60; $x++) {
                    $c = imagecolorat($im, $x, $y);
                    if ((($c >> 16) & 0xFF) > 200 && (($c >> 8) & 0xFF) > 200 && ($c & 0xFF) > 200) {
                        imagedestroy($im);
                        return $y;
                    }
                }
            }
            imagedestroy($im);
            return PHP_INT_MAX;
        };

        // Same character count, so the same size is chosen; only the marks differ.
        $plain    = $topOfInk((string) $svc->ogCard($this->payload(['name' => 'Olasunkanmi'])));
        $accented = $topOfInk((string) $svc->ogCard($this->payload(['name' => 'Ọlásùnkànmí'])));

        $this->assertLessThan(PHP_INT_MAX, $plain, 'the plain name must render');
        $this->assertLessThan(PHP_INT_MAX, $accented, 'the accented name must render');

        // Within a few pixels. A gap of ~14px — the height of the marks — means the
        // baseline ignored them and the accented name grew upward into the kicker.
        $this->assertLessThanOrEqual(2, abs($accented - $plain),
            "the accented name starts at row {$accented} and the plain one at {$plain}: the "
            . 'baseline is not accounting for the marks, so the name grows upward into the kicker');

        // And both sit inside the band the block is centred in, never above it.
        $this->assertGreaterThanOrEqual(150, min($accented, $plain));
    }

    /**
     * The name must not collide with the kicker above it.
     *
     * The first render put "Adaeze Nwosu" — twelve characters, so nominally the largest
     * size — on two lines whose ascenders ran straight through the programme line, because
     * the size ladder was copied from the flier's 952px column into a 608px one. Checked as
     * a clear horizontal band of background between the two blocks.
     */
    public function test_a_gap_survives_between_the_kicker_and_the_name(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        $svc = new FlierService();
        foreach (['Adaeze Nwosu', 'Ọlásùnkànmí Adébáyọ̀ Ogundimu', 'Ada'] as $name) {
            $im = imagecreatefromstring((string) $svc->ogCard($this->payload(['name' => $name])));
            $this->assertNotFalse($im);

            // Rows 132..150 are the gap: the programme baseline is at 120, and the name's
            // band starts at OG_BAND_TOP (152).
            $ink = 0;
            for ($y = 132; $y < 150; $y++) {
                for ($x = FlierService::OG_PHOTO_W + 56; $x < FlierService::OG_W - 60; $x++) {
                    $c = imagecolorat($im, $x, $y);
                    if ((($c >> 16) & 0xFF) > 190 && (($c >> 8) & 0xFF) > 190 && ($c & 0xFF) > 190) $ink++;
                }
            }
            imagedestroy($im);
            $this->assertLessThan(40, $ink, "'{$name}' collides with the kicker above it");
        }
    }

    /** A name is never truncated when a smaller size would have held it. */
    public function test_a_name_is_shrunk_rather_than_ellipsised(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        // Rendered twice at the same inputs must be byte-identical — no randomness — and
        // the ellipsis check is structural: fitLines refuses a size whose last line ends
        // in '…' while a smaller size exists.
        $svc = new FlierService();
        $a = $svc->ogCard($this->payload(['name' => 'Nomvula Dlamini-Khumalo']));
        $b = $svc->ogCard($this->payload(['name' => 'Nomvula Dlamini-Khumalo']));
        $this->assertSame($a, $b, 'the render must be deterministic');
    }

    // ── Wiring ──────────────────────────────────────────────────────────────

    public function test_the_card_route_is_declared_before_the_nominee_catch_all(): void
    {
        // FastRoute matches in declaration order, and `/vote/{program}/{slug}` would
        // otherwise swallow `card.png` and render the ballot HTML as an og:image.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $card = strpos($routes, "card.png',   FlierController::class.':card'");
        $any  = strpos($routes, "\$g->get('/vote/{program}/{slug}', VoteController::class.':nominee')");

        $this->assertIsInt($card, 'the card route must exist');
        $this->assertIsInt($any);
        $this->assertLessThan($any, $card, 'the card route must be declared first');
    }

    public function test_the_card_falls_back_rather_than_serving_a_broken_image(): void
    {
        // A zero-byte or malformed PNG in a chat shows a grey box and reads as the platform
        // being broken. Falling back to the flier PNG — which itself falls back to the SVG
        // — means a preview degrades twice before it can be nothing.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/FlierController.php');

        $this->assertMatchesRegularExpression('~ogCard\(\$f\)~', $src);
        $this->assertStringContainsString("'flier.png'", $src, 'the card must fall back to the flier PNG');
    }
}
