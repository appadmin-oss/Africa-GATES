<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * The ticket's artwork: one stored crop per event, baked by the server.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * `templates/pages/events/ticket.twig` puts the event's picture in a 390×268 band on screen
 * and a ~96mm panel on paper, and until now it fitted it with `object-fit:cover` and a
 * hard-coded `object-position:50% 32%`. That figure was arrived at honestly — event covers
 * are overwhelmingly portrait posters and 32% keeps faces above the fold on most of them —
 * but "most of them" is the whole problem. It is one number applied to every event's artwork
 * for ever, and on the ones it is wrong for it cuts somebody's head off on the document they
 * hold up at a door. The template's own comment named the fix; this is the fix.
 *
 * ── WHY THE SERVER DOES THE CUTTING ──────────────────────────────────────────
 *
 * The editor in the admin form is a viewport with a draggable, zoomable image in it, so the
 * browser already knows exactly what the organiser chose. It would be less code to have the
 * browser draw that to a canvas and post the PNG. It is not done, for two reasons:
 *
 *   1. Those are attacker-supplied bytes wearing a filename. Everything else that reaches
 *      `public/uploads` goes through {@see \AfricaGates\Admin\Services\UploadService}, which
 *      sniffs the magic, refuses anything that is not an image and RE-ENCODES it — a canvas
 *      export posted as a finished file walks around all three.
 *   2. A canvas export is final. The original is gone, so the second edit crops a crop, and
 *      by the third the artwork is mush. Keeping the source and re-cutting from it every time
 *      means an organiser can nudge the frame a year later at no cost in quality.
 *
 * So the browser posts NUMBERS — a rectangle and a few slider values — and this class does
 * the work with Intervention. The numbers are validated on the way in and again on the way
 * out ({@see recipe()}), for the same reason {@see EventTicketDesign::colour()} is: a row can
 * arrive from a restored backup or a hand-written UPDATE, and these particular numbers are
 * fed to an image library and turned into memory allocations.
 *
 * ── THE TARGET IS 3:2, AND IT IS BAKED, NOT DERIVED ─────────────────────────
 *
 * 1200×800 covers both surfaces from one file: it is ~3× the 390px screen band and ~300dpi
 * across the printed panel's 96mm. The screen band is 390:268 ≈ 1.46:1 and the printed one
 * is close to it, so a 3:2 render is trimmed by about 3% at the sides by the `cover` that is
 * still in the stylesheet — which is what `cover` should be doing: absorbing the last few
 * per cent, not choosing the subject.
 */
final class TicketArtwork
{
    /** The baked crop. 3:2 — see the class note on why this one number serves both surfaces. */
    public const W = 1200;
    public const H = 800;

    /** Quarter turns only. A free angle needs a straightening UI to be usable and this is not one. */
    public const ROTATIONS = [0, 90, 180, 270];

    /** `h` mirrors left-to-right, `v` top-to-bottom. Named rather than two booleans so an
     *  impossible "both" cannot be stored — the pair of them is a 180° rotation, which is
     *  already a rotation. */
    public const FLIPS = ['none', 'h', 'v'];

    /** How far the three sliders travel. Intervention's own range for brightness/contrast. */
    public const ADJUST_MIN = -100;
    public const ADJUST_MAX = 100;

    /**
     * A complete, safe recipe — from JSON, from an array, or from nothing.
     *
     * ALWAYS returns every key, so no caller has to coalesce and no template has to guard.
     * Anything unparseable yields the identity recipe: the whole picture, centred, untouched.
     * That is the same thing the ticket did before this class existed, which is the correct
     * answer to "this value is damaged" on a document somebody is about to print.
     *
     * @return array{crop: array{x: float, y: float, w: float, h: float}, rotate: int,
     *               flip: string, brightness: int, contrast: int, greyscale: bool}
     */
    public static function recipe(mixed $stored): array
    {
        $r = $stored;
        if (is_string($r)) {
            $r = json_decode($r, true);
        }
        if ($r instanceof \stdClass) {
            $r = (array) $r;
        }
        if (!is_array($r)) {
            $r = [];
        }

        return [
            'crop'       => self::rect($r['crop'] ?? null),
            'rotate'     => self::rotation($r['rotate'] ?? null),
            'flip'       => self::flip($r['flip'] ?? null),
            'brightness' => self::adjust($r['brightness'] ?? null),
            'contrast'   => self::adjust($r['contrast'] ?? null),
            'greyscale'  => !empty($r['greyscale']),
        ];
    }

    /**
     * The crop rectangle, in fractions of the (already rotated and flipped) picture.
     *
     * Fractions and not pixels, deliberately. The editor knows the image's natural size and
     * so does the renderer, but they are not always looking at the same file: an upload is
     * scaled down to 2400px on the way in, so a rectangle in pixels of what the browser held
     * would be a rectangle of a picture that no longer exists by the time it is cut.
     *
     * Every value is clamped rather than refused. A rectangle is a drag, not a typed field —
     * an off-by-a-pixel from a fast pointer is not a decision to reject the whole edit, and
     * clamping produces exactly the frame the organiser saw.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function rect(mixed $raw): array
    {
        $whole = ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];

        if ($raw instanceof \stdClass) {
            $raw = (array) $raw;
        }
        if (!is_array($raw)) {
            return $whole;
        }
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (!isset($raw[$k]) || !is_numeric($raw[$k])) {
                return $whole;
            }
        }

        $w = self::clamp01((float) $raw['w']);
        $h = self::clamp01((float) $raw['h']);
        // A degenerate rectangle is not a smaller crop, it is a division by zero two methods
        // down. There is no sensible "very slightly" here, so it is the whole picture.
        if ($w < 0.01 || $h < 0.01) {
            return $whole;
        }

        $x = self::clamp01((float) $raw['x']);
        $y = self::clamp01((float) $raw['y']);
        // Slide the rectangle back inside the picture rather than shrinking it: the organiser
        // chose a size, and a frame that silently got smaller is a different photograph.
        $x = min($x, 1.0 - $w);
        $y = min($y, 1.0 - $h);

        return ['x' => round($x, 6), 'y' => round($y, 6), 'w' => round($w, 6), 'h' => round($h, 6)];
    }

    private static function clamp01(float $v): float
    {
        if (!is_finite($v)) {
            return 0.0;
        }
        return max(0.0, min(1.0, $v));
    }

    /** A quarter turn, or none. Anything else is none — never a raw number to imagerotate(). */
    private static function rotation(mixed $raw): int
    {
        $v = is_numeric($raw) ? ((int) $raw % 360 + 360) % 360 : 0;
        return in_array($v, self::ROTATIONS, true) ? $v : 0;
    }

    private static function flip(mixed $raw): string
    {
        $v = strtolower(trim((string) (is_scalar($raw) ? $raw : '')));
        return in_array($v, self::FLIPS, true) ? $v : 'none';
    }

    private static function adjust(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return 0;
        }
        return max(self::ADJUST_MIN, min(self::ADJUST_MAX, (int) round((float) $raw)));
    }

    /** Is this the recipe of somebody who has not touched anything? */
    public static function isDefault(array $recipe): bool
    {
        $r = self::recipe($recipe);
        return $r['crop'] === ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0]
            && $r['rotate'] === 0 && $r['flip'] === 'none'
            && $r['brightness'] === 0 && $r['contrast'] === 0 && !$r['greyscale'];
    }

    /** What goes in `ticket_image_edit`. Validated first, so the column never holds junk. */
    public static function pack(array $recipe): string
    {
        return (string) json_encode(self::recipe($recipe), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Read the editor's hidden field.
     *
     * Returns NULL when the field was not posted at all — which is how an older cached form,
     * or a save from a browser where the editor failed to boot, leaves an existing crop alone
     * instead of resetting it to the whole picture.
     */
    public static function fromForm(array $post, string $key = 'ticket_image_edit'): ?array
    {
        $raw = $post[$key] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        return self::recipe($raw);
    }

    /**
     * Cut the artwork and write it.
     *
     * Order is rotate → flip → crop → cover → adjust, and it is the same order the editor
     * applies in the browser. That is not a detail: the rectangle is expressed against the
     * picture AS THE ORGANISER SAW IT, so if the server cropped before it rotated, every edit
     * involving a turn would land somewhere else entirely.
     *
     * `cover()` after the crop is the safety net. The rectangle is 3:2 whenever it came from
     * the editor, but it is not required to be — the identity recipe is the whole picture, and
     * a portrait poster cropped to 3:2 by a `resize()` would be a portrait poster squashed. So
     * the rectangle chooses the SUBJECT and `cover` settles the last few per cent of the ratio.
     *
     * @param string $srcAbs  absolute path to the original on disk
     * @param string $destAbs absolute path to write; its directory must exist
     * @return array{width: int, height: int, bytes: int}
     * @throws \RuntimeException when the source cannot be read as an image
     */
    public static function render(
        string $srcAbs,
        array $recipe,
        string $destAbs,
        int $quality = 84,
        ?ImageManager $manager = null,
    ): array {
        if (!is_file($srcAbs)) {
            throw new \RuntimeException('Artwork source is missing.');
        }
        $r = self::recipe($recipe);

        try {
            $image = ($manager ?? new ImageManager(new Driver()))->read($srcAbs);
        } catch (\Throwable $e) {
            throw new \RuntimeException('That file could not be read as an image.', 0, $e);
        }

        // Negated: imagerotate() turns anti-clockwise for a positive angle and CSS `rotate()`
        // turns clockwise. The editor's preview is the CSS one, so this is the sign that makes
        // the saved ticket match the picture the organiser was looking at.
        if ($r['rotate'] !== 0) {
            $image->rotate(-$r['rotate'], '000000');
        }
        if ($r['flip'] === 'h') {
            $image->flop();
        } elseif ($r['flip'] === 'v') {
            $image->flip();
        }

        self::cut($image, $r['crop']);
        $image->cover(self::W, self::H);

        // After the resize, not before: these are per-pixel passes and there are eight times
        // fewer pixels on this side of it. Greyscale first so the sliders act on the tones
        // that will actually be printed.
        if ($r['greyscale']) {
            $image->greyscale();
        }
        if ($r['brightness'] !== 0) {
            $image->brightness($r['brightness']);
        }
        if ($r['contrast'] !== 0) {
            $image->contrast($r['contrast']);
        }

        // JPEG, always, whatever came in. The ticket band is a photograph behind a gradient
        // wash; a PNG of it is three times the bytes for no visible difference, and this file
        // is the largest paint on a page somebody opens on a phone in a queue.
        $image->toJpeg($quality)->save($destAbs);

        return [
            'width'  => self::W,
            'height' => self::H,
            'bytes'  => (int) (filesize($destAbs) ?: 0),
        ];
    }

    /**
     * Apply the fractional rectangle to a loaded image.
     *
     * Split out because turning fractions into whole pixels is where the off-by-ones live:
     * `round()` on both edges can produce a width of zero on a very small source, and GD
     * throws on that rather than returning a blank. Each side is floored to at least 1px and
     * then pinned back inside the canvas.
     */
    private static function cut(ImageInterface $image, array $crop): void
    {
        $nw = $image->width();
        $nh = $image->height();

        $w = max(1, (int) round($nw * $crop['w']));
        $h = max(1, (int) round($nh * $crop['h']));
        $x = max(0, (int) round($nw * $crop['x']));
        $y = max(0, (int) round($nh * $crop['y']));

        $w = min($w, $nw);
        $h = min($h, $nh);
        $x = min($x, $nw - $w);
        $y = min($y, $nh - $h);

        if ($x === 0 && $y === 0 && $w === $nw && $h === $nh) {
            return;                              // the whole picture — nothing to cut
        }
        $image->crop($w, $h, $x, $y);
    }
}
