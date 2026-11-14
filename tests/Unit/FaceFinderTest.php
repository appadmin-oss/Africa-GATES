<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\FaceFinder;
use Tests\TestCase;

/**
 * The face finder, and the thing it must never do.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS CAN AND CANNOT PROVE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * They do not prove it finds faces. Nothing here has a face in it — the fixtures are
 * synthetic, drawn with GD, because a repository is the wrong place for photographs of
 * people and a detector tuned against three checked-in portraits is tuned against three
 * portraits. Whether it lands on real faces is a question for real photos and a pair of
 * eyes, and the handoff's verify list already carries that line.
 *
 * What they prove is the property the feature actually rests on: **it degrades to the old
 * behaviour rather than to a wrong answer.** `focus()` returns null for anything that does
 * not look like a head, and null is what the layout used before this class existed. That is
 * what makes running it by default safe — the worst case is the crop we already shipped.
 *
 * So: geometry (a skin-coloured oval high in the frame yields a point above its own centre),
 * refusals (flat colours, cool colours, noise, a wall-sized region), and the guarantee that
 * a dragged frame is never overruled.
 */
final class FaceFinderTest extends TestCase
{
    /** A blank canvas in one colour. */
    private function canvas(int $w, int $h, array $rgb): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
        return $im;
    }

    /**
     * A filled ellipse of one colour on a ground of another.
     *
     * $cx/$cy are fractions of the canvas, so a test reads as "an oval a quarter of the way
     * down" rather than as two pixel numbers nobody can check against the assertion.
     */
    private function blob(int $w, int $h, array $ground, array $fill,
                          float $cx, float $cy, float $rw, float $rh): \GdImage
    {
        $im = $this->canvas($w, $h, $ground);
        imagefilledellipse($im, (int) round($w * $cx), (int) round($h * $cy),
                           (int) round($w * $rw), (int) round($h * $rh),
                           (int) imagecolorallocate($im, $fill[0], $fill[1], $fill[2]));
        return $im;
    }

    /** Mid-brown, well inside the chrominance window and nowhere near its luma edges. */
    private const SKIN = [156, 108, 82];
    /** Deep brown. Same chrominance family, far lower luma — the case an RGB rule fails. */
    private const DEEP = [74, 48, 36];
    /** The platform's own dark green. Cool, so it must never read as skin. */
    private const GREEN = [13, 47, 38];

    // ══ the geometry ═════════════════════════════════════════════════════════

    public function test_it_finds_a_head_shaped_region_and_aims_above_its_centre(): void
    {
        // An oval centred a quarter of the way down. The returned point must sit ABOVE that
        // centre, because the crop is centred on it and centring a head-and-neck blob's
        // middle puts the mouth in the middle of the slot with the forehead cut off.
        $im = $this->blob(300, 400, self::GREEN, self::SKIN, 0.5, 0.25, 0.34, 0.28);
        $f  = FaceFinder::focus($im);
        imagedestroy($im);

        $this->assertNotNull($f, 'a skin-coloured oval on a cool ground is the easy case');
        $this->assertGreaterThan(0.40, $f['x']);
        $this->assertLessThan(0.60, $f['x']);
        // The oval spans 0.11–0.39 of the height; the eye line at 0.42 of it is ~0.23.
        $this->assertGreaterThan(0.14, $f['y']);
        $this->assertLessThan(0.25, $f['y'], 'the point must be above the blob centre at 0.25');
    }

    public function test_it_works_the_same_on_deep_skin_as_on_mid_skin(): void
    {
        // ── THE TEST THIS FILE EXISTS FOR ────────────────────────────────────
        //
        // Skin varies enormously in luma and very little in chrominance, which is why the
        // detector tests chrominance. An RGB threshold is a brightness rule wearing a colour
        // rule's clothes and it fails on dark skin first. On a platform whose audience is
        // African, a detector that works on pale faces and shrugs at everyone else is not a
        // partial feature.
        //
        // Same geometry, two lumas 80 apart. The answers must agree to within a pixel or two.
        $a = $this->blob(300, 400, self::GREEN, self::SKIN, 0.5, 0.25, 0.34, 0.28);
        $b = $this->blob(300, 400, self::GREEN, self::DEEP, 0.5, 0.25, 0.34, 0.28);

        $fa = FaceFinder::focus($a);
        $fb = FaceFinder::focus($b);
        imagedestroy($a); imagedestroy($b);

        $this->assertNotNull($fa);
        $this->assertNotNull($fb, 'deep skin must be found, not shrugged at');
        $this->assertEqualsWithDelta($fa['x'], $fb['x'], 0.02);
        $this->assertEqualsWithDelta($fa['y'], $fb['y'], 0.02);
    }

    public function test_it_follows_the_head_when_the_head_moves(): void
    {
        // The point of the whole class: a different photo gets a different answer. A detector
        // that returns the same coordinates whatever it is shown is a constant with extra
        // steps, and it would pass every refusal test in this file.
        $hi = $this->blob(300, 400, self::GREEN, self::SKIN, 0.30, 0.18, 0.30, 0.22);
        $lo = $this->blob(300, 400, self::GREEN, self::SKIN, 0.70, 0.62, 0.30, 0.22);

        $a = FaceFinder::focus($hi);
        $b = FaceFinder::focus($lo);
        imagedestroy($hi); imagedestroy($lo);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertLessThan($b['x'], $a['x'], 'the left-hand head must give a smaller x');
        $this->assertLessThan($b['y'], $a['y'], 'the higher head must give a smaller y');
    }

    public function test_the_point_never_reaches_an_edge(): void
    {
        // A focal point AT an edge leaves the cover crop nothing to trim on that side, so the
        // frame stops tracking the face — the guard is what stops a head at the very top of a
        // photo from pinning the crop against the ceiling.
        $im = $this->blob(300, 400, self::GREEN, self::SKIN, 0.5, 0.03, 0.30, 0.10);
        $f  = FaceFinder::focus($im);
        imagedestroy($im);

        if ($f !== null) {
            $this->assertGreaterThanOrEqual(0.06, $f['y']);
            $this->assertLessThanOrEqual(0.94, $f['y']);
            $this->assertGreaterThanOrEqual(0.06, $f['x']);
            $this->assertLessThanOrEqual(0.94, $f['x']);
        } else {
            $this->assertNull($f, 'refusing is also correct here');
        }
    }

    // ══ the refusals, which are the safety property ══════════════════════════

    public function test_a_flat_cool_image_finds_nothing(): void
    {
        $im = $this->canvas(240, 320, self::GREEN);
        $this->assertNull(FaceFinder::focus($im));
        imagedestroy($im);
    }

    public function test_a_sky_finds_nothing(): void
    {
        // Blue has Cb above Cr. That one comparison in the mask is what rejects the cool half
        // of the colour wheel, and it costs a subtraction.
        $im = $this->canvas(240, 320, [96, 148, 210]);
        $this->assertNull(FaceFinder::focus($im));
        imagedestroy($im);
    }

    public function test_a_wall_of_skin_colour_finds_nothing(): void
    {
        // A terracotta wall, a wooden door, a warm-lit room. The region IS the photo, so its
        // centroid is the centre of the frame — which is exactly what the fixed anchor
        // already gives, and with more honesty than calling it a face.
        $im = $this->canvas(240, 320, self::SKIN);
        $this->assertNull(FaceFinder::focus($im));
        imagedestroy($im);
    }

    public function test_a_thin_skin_coloured_bar_finds_nothing(): void
    {
        // A forearm, a skirting board, a strip of sand along the bottom. Rejected by SHAPE,
        // which is where every false positive from the deliberately wide colour window has to
        // be caught — narrowing the colour window is what excludes people.
        $im = $this->canvas(240, 320, self::GREEN);
        imagefilledrectangle($im, 0, 250, 239, 268,
            (int) imagecolorallocate($im, self::SKIN[0], self::SKIN[1], self::SKIN[2]));
        $this->assertNull(FaceFinder::focus($im));
        imagedestroy($im);
    }

    public function test_speckle_finds_nothing(): void
    {
        // Sub-threshold blobs, of which there are many. Each is under MIN_AREA, and the
        // scoring must not add them up: they are not connected, so they cannot be.
        $im = $this->canvas(240, 320, self::GREEN);
        $c  = (int) imagecolorallocate($im, self::SKIN[0], self::SKIN[1], self::SKIN[2]);
        for ($i = 0; $i < 40; $i++) {
            imagefilledrectangle($im, ($i * 17) % 230, ($i * 29) % 310,
                                 ((($i * 17) % 230)) + 2, ((($i * 29) % 310)) + 2, $c);
        }
        $this->assertNull(FaceFinder::focus($im));
        imagedestroy($im);
    }

    public function test_nothing_and_a_tiny_image_are_refused_not_crashed(): void
    {
        $this->assertNull(FaceFinder::focus(null));

        $im = $this->canvas(4, 4, self::SKIN);
        $this->assertNull(FaceFinder::focus($im), 'below the size floor, so refused');
        imagedestroy($im);
    }

    public function test_a_photo_smaller_than_the_working_width_is_still_read(): void
    {
        // The downscale is skipped rather than upscaled, and skipping a branch is how a code
        // path stops being exercised. A 60px-wide photo is a real thing somebody uploads.
        $im = $this->blob(60, 80, self::GREEN, self::SKIN, 0.5, 0.28, 0.5, 0.36);
        $f  = FaceFinder::focus($im);
        imagedestroy($im);

        $this->assertNotNull($f);
        $this->assertLessThan(0.5, $f['y']);
    }

    // ══ and it never overrules a person ══════════════════════════════════════

    public function test_a_dragged_frame_wins_over_the_detector(): void
    {
        // Somebody who has moved the frame has said where they want it. A detector that
        // overrules them is a control that does not work — and this is the one behaviour in
        // the whole feature that a user can see us getting wrong.
        $im = $this->blob(300, 400, self::GREEN, self::SKIN, 0.5, 0.25, 0.34, 0.28);

        $f = \AfricaGates\Services\EventFlier::focus($im, 0.8, 0.9);
        $this->assertSame(0.8, $f['x']);
        $this->assertSame(0.9, $f['y']);

        // And with nothing supplied, the detector's answer is used.
        $auto = \AfricaGates\Services\EventFlier::focus($im, null, null);
        $this->assertNotNull($auto['x']);
        $this->assertLessThan(0.5, $auto['y']);
        imagedestroy($im);
    }

    public function test_no_photo_and_no_drag_leaves_the_layouts_own_anchor(): void
    {
        // Null on both axes, which is what FlierRaster reads as "use PHOTO_ANCHOR_Y" — the
        // behaviour that shipped before FaceFinder existed.
        $f = \AfricaGates\Services\EventFlier::focus(null, null, null);
        $this->assertNull($f['x']);
        $this->assertNull($f['y']);
    }

    public function test_a_supplied_focus_is_clamped(): void
    {
        // A hand-built request can post anything. 4.2 as an object-position fraction is a crop
        // origin off the canvas, and GD draws nothing there rather than complaining.
        $f = \AfricaGates\Services\EventFlier::focus(null, 4.2, -3.0);
        $this->assertSame(1.0, $f['x']);
        $this->assertSame(0.0, $f['y']);
    }
}
