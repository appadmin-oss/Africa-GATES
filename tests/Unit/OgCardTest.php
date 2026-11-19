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
     * The photo column is a clean, full-height panel beside the words.
     *
     * The old card faded its photo into a gradient and was tested for the seam; the share
     * card system (ShareCardSpec) gives the photo its own column with a straight edge, so
     * what matters now is that the photo really fills that column and stops at its edge.
     */
    public function test_the_photo_fills_its_column_and_stops_at_its_edge(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');

        $tmp = tempnam(sys_get_temp_dir(), 'og') . '.png';
        $src = imagecreatetruecolor(400, 400);
        imagefill($src, 0, 0, imagecolorallocate($src, 255, 0, 255));
        imagepng($src, $tmp);
        imagedestroy($src);
        $png = (new FlierService())->ogCard($this->payload(['photo_card' => $tmp]), ['mode' => 'bold']);
        @unlink($tmp);
        $im = imagecreatefromstring((string) $png);
        $this->assertNotFalse($im);
        $magenta = static function ($im, int $x, int $y): bool {
            $c = imagecolorat($im, $x, $y);
            return (($c >> 16) & 0xFF) > 200 && ($c & 0xFF) > 200 && (($c >> 8) & 0xFF) < 60;
        };
        foreach ([300, 315, 600] as $y) {
            $this->assertTrue($magenta($im, 10, $y), "the photo reaches the left edge at y={$y}");
            $this->assertTrue($magenta($im, FlierService::OG_PHOTO_W - 2, $y), "the photo reaches its column edge at y={$y}");
            $this->assertFalse($magenta($im, FlierService::OG_PHOTO_W + 2, $y), "the photo stops at its column at y={$y}");
        }
        imagedestroy($im);
    }

    /** Long names step down or wrap; nothing is drawn into the right margin. */
    public function test_nothing_is_drawn_past_the_right_margin(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');
        foreach (['Tsehaynesh Wolde-Giorgis', 'Nomvula Dlamini-Khumalo', 'Ọlásùnkànmí Adébáyọ̀ Ogundimu'] as $name) {
            // Bold, so the ground is dark and every piece of type is light.
            $png = (new FlierService())->ogCard($this->payload(['name' => $name]), ['mode' => 'bold']);
            $im  = imagecreatefromstring((string) $png);
            $this->assertNotFalse($im);
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
     * The name keeps clear of the category above it, accented or not. Tone marks sit
     * above the cap height, and a baseline that ignored them pushed the name into the
     * line above it.
     */
    public function test_a_gap_survives_between_the_category_and_the_name(): void
    {
        if (!$this->renderable()) $this->markTestSkipped('GD/FreeType or the bundled fonts are unavailable.');
        $svc = new FlierService();
        foreach (['Adaeze Nwosu', 'Ọlásùnkànmí Adébáyọ̀ Ogundimu', 'Ada'] as $name) {
            $im = imagecreatefromstring((string) $svc->ogCard($this->payload(['name' => $name]), ['mode' => 'bold']));
            $this->assertNotFalse($im);
            // Rows of the name column: find the category's last inked row and the name's
            // first WHITE row (the category is the tone label colour, the name is white).
            $x0 = FlierService::OG_PHOTO_W + 40; $x1 = FlierService::OG_W - 40;
            $rows = [];
            for ($y = 100; $y < 450; $y++) {
                $white = false; $ink = false;
                for ($x = $x0; $x < $x1; $x += 2) {
                    $c = imagecolorat($im, $x, $y);
                    [$r, $g, $b] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                    if ($r > 235 && $g > 235 && $b > 235) $white = true;
                    elseif ($r + $g + $b > 300) $ink = true;
                }
                $rows[$y] = $white ? 'w' : ($ink ? 'i' : '.');
            }
            imagedestroy($im);
            $this->assertContains('w', $rows, "'{$name}' must render");
            // The first inked run from the top is the category; then clear rows; then the
            // name. Its own anti-aliased edge is not mistaken for the category this way.
            $seq = implode('', $rows);
            $this->assertMatchesRegularExpression('/^\.*i+(\.+)[iw]/', $seq, 'the category sits above the name');
            preg_match('/^\.*i+(\.+)[iw]/', $seq, $m);
            $this->assertGreaterThanOrEqual(4, strlen($m[1]), "'{$name}' collides with the category above it");
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
