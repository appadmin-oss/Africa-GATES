<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\TicketArtwork;
use Tests\TestCase;

/**
 * The ticket's crop: what the numbers are allowed to be, and what comes out the other end.
 *
 * The recipe arrives from a browser, so the first half of this file is about refusing to
 * believe it. The second half renders real pixels through GD and reads them back, because the
 * whole feature is a promise that the frame an organiser set is the frame that prints, and
 * that promise is only kept if the geometry is right — a test that asserted `render()` did not
 * throw would pass on an image cropped from the wrong corner.
 */
class TicketArtworkTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ag-artwork-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * A source image made of four solid quadrants, so a crop can be identified by its colour.
     *
     *   red   | green
     *   ------+------
     *   blue  | white
     */
    private function quadrants(int $w = 400, int $h = 400): string
    {
        $im = imagecreatetruecolor($w, $h);
        $half = static fn (int $n): int => (int) ($n / 2);
        imagefilledrectangle($im, 0, 0, $half($w) - 1, $half($h) - 1, imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, $half($w), 0, $w - 1, $half($h) - 1, imagecolorallocate($im, 0, 255, 0));
        imagefilledrectangle($im, 0, $half($h), $half($w) - 1, $h - 1, imagecolorallocate($im, 0, 0, 255));
        imagefilledrectangle($im, $half($w), $half($h), $w - 1, $h - 1, imagecolorallocate($im, 255, 255, 255));
        $path = $this->dir . '/src-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    private function flat(int $w, int $h, int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, $r, $g, $b));
        $path = $this->dir . '/flat-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    /** @return array{0:int,1:int,2:int} the pixel at a fraction across and down the file */
    private function pixelAt(string $path, float $fx, float $fy): array
    {
        $im = imagecreatefromjpeg($path);
        $x  = (int) min(imagesx($im) - 1, max(0, round(imagesx($im) * $fx)));
        $y  = (int) min(imagesy($im) - 1, max(0, round(imagesy($im) * $fy)));
        $c  = imagecolorat($im, $x, $y);
        imagedestroy($im);
        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    }

    private function assertNear(array $expected, array $got, int $tolerance, string $why): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->assertLessThanOrEqual(
                $tolerance, abs($expected[$i] - $got[$i]),
                $why . ' — channel ' . $i . ': wanted ~' . $expected[$i] . ', got ' . $got[$i]
            );
        }
    }

    // ── what the recipe is allowed to be ────────────────────────────────────

    public function test_nothing_at_all_yields_the_whole_picture_untouched(): void
    {
        foreach ([null, '', 'not json', '[]', '{"crop":"yes"}', 42] as $junk) {
            $r = TicketArtwork::recipe($junk);
            $this->assertSame(['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0], $r['crop']);
            $this->assertTrue(TicketArtwork::isDefault($r), 'junk should read as unconfigured');
        }
    }

    public function test_a_rectangle_that_hangs_off_the_edge_slides_back_rather_than_shrinking(): void
    {
        // The size is the organiser's choice; the position is a drag. So a frame pushed past
        // the right edge comes back the same size, it does not get narrower.
        $r = TicketArtwork::recipe(['crop' => ['x' => 0.9, 'y' => 0.95, 'w' => 0.5, 'h' => 0.4]]);
        $this->assertSame(0.5, $r['crop']['w']);
        $this->assertSame(0.4, $r['crop']['h']);
        $this->assertSame(0.5, $r['crop']['x']);
        $this->assertSame(0.6, $r['crop']['y']);
    }

    public function test_a_degenerate_or_impossible_rectangle_falls_back_to_the_whole_picture(): void
    {
        foreach ([
            ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0.5],
            ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => -1],
            ['x' => 0, 'y' => 0, 'w' => 0.001, 'h' => 0.5],
            ['x' => 'a', 'y' => 0, 'w' => 0.5, 'h' => 0.5],
            ['x' => 0, 'y' => 0, 'w' => INF, 'h' => 0.5],
        ] as $bad) {
            $this->assertSame(
                ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0],
                TicketArtwork::recipe(['crop' => $bad])['crop'],
                'a rectangle with no area is not a smaller crop, it is a division by zero'
            );
        }
    }

    public function test_only_quarter_turns_survive_and_everything_else_is_none(): void
    {
        $this->assertSame(90, TicketArtwork::recipe(['rotate' => 90])['rotate']);
        $this->assertSame(270, TicketArtwork::recipe(['rotate' => -90])['rotate']);
        $this->assertSame(0, TicketArtwork::recipe(['rotate' => 360])['rotate']);
        foreach ([45, 17, 'ninety', null, 1e9] as $bad) {
            $this->assertSame(0, TicketArtwork::recipe(['rotate' => $bad])['rotate']);
        }
    }

    public function test_the_flip_is_one_of_three_named_values(): void
    {
        $this->assertSame('h', TicketArtwork::recipe(['flip' => 'H'])['flip']);
        $this->assertSame('v', TicketArtwork::recipe(['flip' => ' v '])['flip']);
        foreach (['both', 'diagonal', '<script>', ['h'], 1] as $bad) {
            $this->assertSame('none', TicketArtwork::recipe(['flip' => $bad])['flip']);
        }
    }

    public function test_the_sliders_are_clamped_to_their_own_range(): void
    {
        $this->assertSame(100, TicketArtwork::recipe(['brightness' => 5000])['brightness']);
        $this->assertSame(-100, TicketArtwork::recipe(['contrast' => -5000])['contrast']);
        $this->assertSame(12, TicketArtwork::recipe(['brightness' => 12.4])['brightness']);
        $this->assertSame(0, TicketArtwork::recipe(['contrast' => 'loud'])['contrast']);
    }

    public function test_the_packed_recipe_survives_a_round_trip(): void
    {
        $in  = ['crop' => ['x' => .25, 'y' => .1, 'w' => .5, 'h' => .333333],
                'rotate' => 270, 'flip' => 'v', 'brightness' => -20, 'contrast' => 40, 'greyscale' => true];
        $out = TicketArtwork::recipe(TicketArtwork::pack($in));
        $this->assertSame(TicketArtwork::recipe($in), $out);
        $this->assertFalse(TicketArtwork::isDefault($out));
    }

    public function test_an_absent_field_is_not_the_same_as_an_empty_one(): void
    {
        // Absent means the editor never ran, and the caller must leave the stored crop alone.
        $this->assertNull(TicketArtwork::fromForm([]));
        $this->assertNull(TicketArtwork::fromForm(['ticket_image_edit' => '  ']));
        $this->assertIsArray(TicketArtwork::fromForm(['ticket_image_edit' => '{"rotate":90}']));
    }

    // ── and what actually comes out ─────────────────────────────────────────

    public function test_the_render_is_always_the_baked_size_whatever_went_in(): void
    {
        foreach ([[400, 400], [1200, 300], [200, 900]] as [$w, $h]) {
            $dest = $this->dir . "/out-{$w}x{$h}.jpg";
            $out  = TicketArtwork::render($this->flat($w, $h, 20, 120, 60), [], $dest);
            $this->assertSame(TicketArtwork::W, $out['width']);
            $this->assertSame(TicketArtwork::H, $out['height']);
            $this->assertSame([TicketArtwork::W, TicketArtwork::H], array_slice(getimagesize($dest), 0, 2));
        }
    }

    public function test_the_crop_takes_the_quadrant_it_was_pointed_at(): void
    {
        // The top-left quadrant, exactly. It is red, and nothing else in the source is.
        $dest = $this->dir . '/tl.jpg';
        TicketArtwork::render($this->quadrants(), ['crop' => ['x' => 0, 'y' => 0, 'w' => .5, 'h' => .5]], $dest);
        $this->assertNear([255, 0, 0], $this->pixelAt($dest, .5, .5), 12, 'the top-left quadrant is red');

        // And the bottom-right, which is white — proving the offset is used and not just the size.
        $dest = $this->dir . '/br.jpg';
        TicketArtwork::render($this->quadrants(), ['crop' => ['x' => .5, 'y' => .5, 'w' => .5, 'h' => .5]], $dest);
        $this->assertNear([255, 255, 255], $this->pixelAt($dest, .5, .5), 12, 'the bottom-right quadrant is white');
    }

    public function test_a_square_crop_is_covered_rather_than_squashed_into_the_frame(): void
    {
        // A 1:1 rectangle asked to become 3:2 must LOSE its top and bottom, not stretch. The
        // source's left half is red above and blue below; covering a square into 3:2 keeps the
        // middle band, so the centre row stays on the red/blue seam and the corners do not.
        $dest = $this->dir . '/cover.jpg';
        TicketArtwork::render($this->quadrants(), ['crop' => ['x' => 0, 'y' => 0, 'w' => .5, 'h' => .5]], $dest);
        // A 200×200 crop covered into 1200×800 keeps the central 200×133 band — still all red.
        $this->assertNear([255, 0, 0], $this->pixelAt($dest, .05, .05), 12, 'the crop is one flat colour, so cover cannot introduce another');
    }

    public function test_a_quarter_turn_clockwise_matches_what_css_rotate_shows(): void
    {
        // Turned clockwise, the RED top-left quadrant lands top-RIGHT. If the sign were wrong
        // it would land bottom-left and the ticket would not be the picture the organiser saw.
        $dest = $this->dir . '/turn.jpg';
        TicketArtwork::render($this->quadrants(), ['rotate' => 90], $dest);
        $this->assertNear([255, 0, 0], $this->pixelAt($dest, .78, .12), 20, 'red moves to the top right');
        $this->assertNear([0, 0, 255], $this->pixelAt($dest, .22, .12), 20, 'blue moves to the top left');
    }

    public function test_the_crop_is_read_against_the_turned_picture_and_not_the_file(): void
    {
        // Same rectangle, same file, one turn: the top-left of the TURNED picture is blue.
        // This is the whole reason the order in render() is rotate-then-crop.
        $dest = $this->dir . '/turn-crop.jpg';
        TicketArtwork::render(
            $this->quadrants(),
            ['rotate' => 90, 'crop' => ['x' => 0, 'y' => 0, 'w' => .5, 'h' => .5]],
            $dest
        );
        $this->assertNear([0, 0, 255], $this->pixelAt($dest, .5, .5), 12, 'after a clockwise turn the top-left quadrant is the old bottom-left');
    }

    public function test_mirroring_swaps_the_sides_it_says_it_swaps(): void
    {
        $h = $this->dir . '/flip-h.jpg';
        TicketArtwork::render($this->quadrants(), ['flip' => 'h', 'crop' => ['x' => 0, 'y' => 0, 'w' => .5, 'h' => .5]], $h);
        $this->assertNear([0, 255, 0], $this->pixelAt($h, .5, .5), 12, 'mirrored left-to-right, the top-left is the old top-right');

        $v = $this->dir . '/flip-v.jpg';
        TicketArtwork::render($this->quadrants(), ['flip' => 'v', 'crop' => ['x' => 0, 'y' => 0, 'w' => .5, 'h' => .5]], $v);
        $this->assertNear([0, 0, 255], $this->pixelAt($v, .5, .5), 12, 'mirrored top-to-bottom, the top-left is the old bottom-left');
    }

    public function test_brightness_adds_a_constant_which_is_what_the_preview_promises(): void
    {
        // GD adds level×2.55 to every channel. The editor previews it with an SVG
        // feComponentTransfer intercept for exactly that reason — CSS brightness() multiplies,
        // and on a mid grey the two differ by about 25 levels, which is visible.
        $dest = $this->dir . '/bright.jpg';
        TicketArtwork::render($this->flat(300, 300, 100, 100, 100), ['brightness' => 20], $dest);
        $this->assertNear([151, 151, 151], $this->pixelAt($dest, .5, .5), 6, '100 + 20×2.55 ≈ 151');
    }

    public function test_contrast_pivots_around_the_middle(): void
    {
        // Positive contrast pushes a light tone lighter and a dark tone darker, around 0.5.
        $light = $this->dir . '/c-light.jpg';
        $dark  = $this->dir . '/c-dark.jpg';
        TicketArtwork::render($this->flat(300, 300, 180, 180, 180), ['contrast' => 30], $light);
        TicketArtwork::render($this->flat(300, 300, 70, 70, 70), ['contrast' => 30], $dark);
        $this->assertGreaterThan(180, $this->pixelAt($light, .5, .5)[0]);
        $this->assertLessThan(70, $this->pixelAt($dark, .5, .5)[0]);
    }

    public function test_black_and_white_uses_the_weights_the_preview_uses(): void
    {
        // .299/.587/.114 — GD's, not CSS grayscale()'s Rec.709 weights. On a saturated red the
        // two answers are 76 and 54, and the editor's SVG matrix is set to produce the first.
        $dest = $this->dir . '/grey.jpg';
        TicketArtwork::render($this->flat(300, 300, 255, 0, 0), ['greyscale' => true], $dest);
        $this->assertNear([76, 76, 76], $this->pixelAt($dest, .5, .5), 8, 'luma weights, not Rec.709');
    }

    public function test_a_missing_or_unreadable_source_is_a_clean_refusal(): void
    {
        $this->expectException(\RuntimeException::class);
        TicketArtwork::render($this->dir . '/does-not-exist.png', [], $this->dir . '/x.jpg');
    }

    public function test_a_file_that_is_not_an_image_is_refused_rather_than_half_rendered(): void
    {
        $notAnImage = $this->dir . '/notes.txt';
        file_put_contents($notAnImage, 'this is not a poster');
        $dest = $this->dir . '/y.jpg';
        try {
            TicketArtwork::render($notAnImage, [], $dest);
            $this->fail('a text file should not render as a ticket');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be read', $e->getMessage());
            $this->assertFileDoesNotExist($dest, 'a refused render must not leave a file behind');
        }
    }
}
