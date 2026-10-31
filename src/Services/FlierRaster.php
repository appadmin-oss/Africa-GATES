<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The raster primitives every generated graphic on this platform draws with.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE ARE SHARED AND NOT COPIED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * They were private to {@see FlierService}, which was correct while it was the only thing
 * rendering an image. The event flier draws the same five things — text with letter-spacing
 * GD has no concept of, wrapping measured against the actual face rather than counted in
 * characters, a vertical gradient, a cover-crop, and a photo fetched from wherever the
 * platform keeps it — and every one of them carries a lesson in its comments that was paid
 * for once already.
 *
 * `cover()` is the clearest case: two renderers with their own crop maths is how one graphic
 * puts a face in the middle and another cuts the chin off, and neither is obviously the wrong
 * one when you are looking at them side by side. `wrapMeasured()` is the same shape — a
 * character count breaks a name in a wide display face at a completely different place than
 * in a narrow one, and getting it wrong is invisible until somebody with a long name shares
 * a flier.
 *
 * Nothing here knows about a nominee, an event, a layout or a palette. It is GD with the
 * sharp edges filed off, and every method is one this codebase has already had to fix.
 */
trait FlierRaster
{
    /**
     * The vertical anchor when a photo is taller than the box it must fill.
     *
     * 0 keeps the top, 0.5 the middle, 1 the bottom. 0.22 is a deliberate upper bias:
     * across submitted portraits — a phone photo, a headshot, a stage shot — the face
     * sits in the upper third, and 0.5 crops to the chest. Photographic composition puts
     * the eyes near the upper third line, so anchoring a little above centre keeps the
     * head in frame on a portrait while still including the shoulders.
     *
     * It is a heuristic and it is not face detection. A photo that is already the box's
     * aspect ratio is unaffected (there is nothing to crop), and a Cloudinary-hosted
     * photo never reaches this code path because it arrives pre-cropped on the detected
     * face — see {@see photoUrl()}. This is the honest best available for a local file
     * on a host with GD and nothing else.
     */
    protected const PHOTO_ANCHOR_Y = 0.22;

    // ── GD helpers. Small, named, and shared by png() only. ──────────────────

    /**
     * A rounded-end capsule: the shape every chip, badge and rank marker on this platform
     * is drawn with.
     *
     * Here rather than in one renderer because the geometry has a trap in it. GD's
     * `imagefilledrectangle()` is INCLUSIVE of both corners, and `imagefilledellipse()`
     * takes a CENTRE and full width/height rather than a bounding box — so a pill written
     * from the measurements you laid out is a pixel taller than the box beside it, and the
     * two only look wrong next to each other. Getting that right once is the point.
     *
     * `$r` is capped at half the WIDTH as well as half the height, or a chip narrower than
     * it is tall draws its two end caps overlapping past each other and comes out as a
     * lozenge pointing the wrong way.
     */
    protected function pill($im, float $x, float $y, float $w, float $h, int $colour): void
    {
        $r = min($h / 2, $w / 2);
        imagefilledrectangle($im, (int) ($x + $r), (int) $y, (int) ($x + $w - $r), (int) ($y + $h), $colour);
        imagefilledellipse($im, (int) ($x + $r), (int) ($y + $h / 2), (int) ($r * 2), (int) $h, $colour);
        imagefilledellipse($im, (int) ($x + $w - $r), (int) ($y + $h / 2), (int) ($r * 2), (int) $h, $colour);
    }

    /**
     * A rectangle with a chosen corner radius — a card, not a pill.
     *
     * `pill()` caps the radius at half the height, which is right for a chip and wrong
     * for a 240px card with 24px corners: it would come out as a lozenge. Both live
     * here for the reason given above — the inclusive-rectangle and centre-ellipse
     * traps are the same ones, and getting them right twice is how two graphics come to
     * differ by a pixel that only shows when they sit side by side.
     *
     * The four corner discs are drawn at `2r` diameter centred `r` in from each edge,
     * and the two cross bars overlap them, so nothing is drawn twice at a different
     * rounding.
     */
    protected function roundRect($im, float $x, float $y, float $w, float $h, float $r, int $colour): void
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));

        if ($r < 1) {
            // `imagefilledrectangle` is INCLUSIVE of both corners, so the far edge is
            // one less than the width — the same trap the pill comment names.
            imagefilledrectangle($im, (int) $x, (int) $y, (int) ($x + $w - 1), (int) ($y + $h - 1), $colour);

            return;
        }

        $x0 = (int) $x; $y0 = (int) $y;
        $x1 = (int) ($x + $w - 1); $y1 = (int) ($y + $h - 1);
        $ri = (int) round($r);

        imagefilledrectangle($im, $x0 + $ri, $y0, $x1 - $ri, $y1, $colour);
        imagefilledrectangle($im, $x0, $y0 + $ri, $x1, $y1 - $ri, $colour);

        $d = $ri * 2;
        imagefilledellipse($im, $x0 + $ri, $y0 + $ri, $d, $d, $colour);
        imagefilledellipse($im, $x1 - $ri, $y0 + $ri, $d, $d, $colour);
        imagefilledellipse($im, $x0 + $ri, $y1 - $ri, $d, $d, $colour);
        imagefilledellipse($im, $x1 - $ri, $y1 - $ri, $d, $d, $colour);
    }

    /**
     * Can this face actually put ink down for this character?
     *
     * ── A MISSING GLYPH IS SILENT, AND `imagettfbbox` LIES ABOUT IT ─────────
     *
     * DM Sans — `bold`, `semibold` and `regular` here — has no ₦. Asked to draw one,
     * GD draws NOTHING: no warning, no exception, and `imagettfbbox` still returns a
     * width, so a renderer that measures before drawing is told the character fits and
     * then silently omits it.
     *
     * Measured: "₦" at 80pt in DMSans-Bold leaves 0 ink pixels; in AGText-Bold it
     * leaves 4,179. A challenge flier advertising "6k" with no currency at all is the
     * shape of failure that reaches a timeline before anybody notices.
     *
     * This matters for every operator-typed symbol, not just the naira: GH₵, KSh and
     * the rest arrive from a form. So the check is by RENDERING, which is the only
     * thing that answers the question, memoised per face and character because it
     * costs an image each time.
     */
    protected function canDraw(string $font, string $ch): bool
    {
        static $memo = [];

        $key = $font . '|' . $ch;
        if (isset($memo[$key])) return $memo[$key];

        // Whitespace draws no ink by definition and must not be reported as missing.
        if (trim($ch) === '') return $memo[$key] = true;

        $im = @imagecreatetruecolor(96, 96);
        if ($im === false) return $memo[$key] = true;

        imagefilledrectangle($im, 0, 0, 95, 95, (int) imagecolorallocate($im, 255, 255, 255));
        @imagettftext($im, 48, 0, 10, 70, (int) imagecolorallocate($im, 0, 0, 0), $font, $ch);

        $ink = 0;
        for ($x = 0; $x < 96 && $ink === 0; $x += 2) {
            for ($y = 0; $y < 96; $y += 2) {
                if ((imagecolorat($im, $x, $y) & 0xFF) < 200) { $ink = 1; break; }
            }
        }
        imagedestroy($im);

        return $memo[$key] = $ink > 0;
    }

    /**
     * The face to draw one character with: the asked-for one, or the first fallback
     * that can.
     *
     * The fallbacks are this repo's own `AGText`/`AGMono` faces, which carry the
     * currency symbols DM Sans does not. A mixed-face run is how typesetting has always
     * handled this; a dropped symbol is not.
     */
    protected function faceFor(string $font, string $ch): string
    {
        if ($this->canDraw($font, $ch)) return $font;

        $dir = dirname(__DIR__, 2) . '/resources/fonts/';
        foreach (['AGText-Bold.ttf', 'AGMono-Bold.ttf', 'AGText-Regular.ttf'] as $alt) {
            $path = $dir . $alt;
            if (is_file($path) && $this->canDraw($path, $ch)) return $path;
        }

        return $font;   // nothing can draw it; let the caller's own face try
    }

    /** Draw text with an optional letter-spacing, which imagettftext has no concept of. */
    protected function text($im, string $s, float $size, string $font, int $colour, float $x, float $y, float $tracking = 0): void
    {
        if ($tracking <= 0) {
            // ── THE FAST PATH STAYS FAST ────────────────────────────────────
            // Only a string carrying a character this face cannot draw takes the
            // per-character route; ordinary Latin copy is one call as it always was.
            if ($this->drawable($s, $font)) {
                imagettftext($im, $size, 0, (int) round($x), (int) round($y), $colour, $font, $s);

                return;
            }

            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $face = $this->faceFor($font, $ch);
                imagettftext($im, $size, 0, (int) round($x), (int) round($y), $colour, $face, $ch);
                $x += $this->width($ch, $size, $face);
            }

            return;
        }
        // Per-character, because the kicker's letter-spacing is a real part of the design
        // and GD cannot express it. Only used on two short strings.
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            imagettftext($im, $size, 0, (int) round($x), (int) round($y), $colour, $font, $ch);
            $x += $this->width($ch, $size, $font) + $tracking;
        }
    }

    /** Is every character in this string one the face can actually draw? */
    protected function drawable(string $s, string $font): bool
    {
        // ASCII is in every face here, so the common case never renders a probe.
        if (preg_match('/^[\x20-\x7E]*$/', $s) === 1) return true;

        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (!$this->canDraw($font, $ch)) return false;
        }

        return true;
    }

    /**
     * Advance width of a string at a size, from FreeType's own metrics.
     *
     * ── AND IT HAS TO KNOW ABOUT TRACKING ────────────────────────────────────
     *
     * `text()` draws a tracked string CHARACTER BY CHARACTER, advancing by each glyph's own
     * width plus the tracking, because GD has no letter-spacing. So the width of a tracked
     * line is not the width of the untracked one, and it is not the untracked width plus
     * n×tracking either — the per-character advance FreeType reports for an isolated glyph is
     * not the advance it uses inside a run.
     *
     * Measuring one way and drawing another is how a line that measured as fitting ran off
     * the right edge of a flier. So when tracking is asked for, this sums exactly what
     * `text()` will advance by. Untracked callers take the same path they always did.
     */
    protected function width(string $s, float $size, string $font, float $tracking = 0.0): float
    {
        if ($tracking <= 0) {
            $b = imagettfbbox($size, 0, $font, $s);
            return $b === false ? 0.0 : (float) ($b[2] - $b[0]);
        }

        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($chars === []) return 0.0;

        $w = 0.0;
        foreach ($chars as $ch) $w += $this->width($ch, $size, $font) + $tracking;

        // One tracking too many: the gap after the last glyph is not part of the line.
        return $w - $tracking;
    }

    /**
     * Wrap a string that may contain no spaces at all — a URL — across at most $maxLines.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY wrapMeasured() CANNOT DO THIS
     * ══════════════════════════════════════════════════════════════════════════
     *
     * It splits on whitespace, and where a single token is wider than the line it keeps it
     * anyway (`|| $cur === ''`) — correct for a name, which must not be cut mid-word if
     * anything can be done about it, and wrong for an address, which has no spaces in it at
     * all. A printed URL therefore ran straight off the right edge of the flier, and
     * off-canvas text does not throw: it is simply not there.
     *
     * That shipped the moment the printed address grew a `?ref=` on it, which is the moment
     * it became the thing that pays the sharer. It looked correct in every render before that
     * because the short form happened to fit.
     *
     * ── AND IT BREAKS WHERE A URL WANTS TO BREAK ─────────────────────────────
     *
     * After a `/`, `?`, `&`, `.`, `-` or `_` first — the same places a browser's
     * `overflow-wrap` prefers, and the places a reader's eye already expects a seam. A hard
     * mid-token break is the last resort, not the first move: `afg.afrovanguard.o` / `rg.ng`
     * is readable but it reads as damage.
     *
     * The last line is elided if what remains still will not fit, so nothing ever crosses the
     * margin.
     *
     * @return list<string>
     */
    protected function wrapUrl(string $text, float $maxW, int $size, string $font,
                               int $maxLines = 2, float $tracking = 0.0): array
    {
        $text = trim($text);
        if ($text === '' || $maxW <= 0) return [];

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $cur   = '';
        // Where in $cur the last natural seam is, so a break can be rewound to it rather
        // than taken wherever the width happened to run out.
        $seam  = 0;

        foreach ($chars as $ch) {
            $try = $cur . $ch;

            if ($this->width($try, $size, $font, $tracking) <= $maxW) {
                $cur = $try;
                // The seam sits AFTER the separator, so the line keeps the slash it broke on
                // — a URL split before its slash reads as a missing character.
                if (str_contains('/?&.-_ ', $ch)) $seam = mb_strlen($cur);
                continue;
            }

            if (count($lines) + 1 >= $maxLines) {
                // Last line: elide, measured, so the ellipsis itself fits too.
                while ($cur !== '' && $this->width($cur . '…', $size, $font, $tracking) > $maxW) {
                    $cur = mb_substr($cur, 0, -1);
                }
                $lines[] = rtrim($cur) . '…';
                return $lines;
            }

            // Rewind to the seam when there is one and it is not so far back that the line
            // becomes a stub — half an empty line is worse than a break one character early.
            if ($seam > 0 && $seam >= (int) (mb_strlen($cur) * 0.55)) {
                $lines[] = mb_substr($cur, 0, $seam);
                $cur = mb_substr($cur, $seam) . $ch;
            } else {
                $lines[] = $cur;
                $cur = $ch;
            }
            $seam = 0;
        }

        if ($cur !== '') $lines[] = $cur;

        return $lines;
    }

    /**
     * Draw a string with its INK BOX centred inside a vertical band.
     *
     * For the monogram, and it exists because a baseline computed from the point size
     * is wrong at display sizes. At 400px the ladder said the glyph top would land near
     * y 190; measured, `Ọ` in Playfair Bold reached y 90 and collided with the VOTE NOW
     * lockup — the ratio between point size and ink extent is a property of the face
     * and the specific characters, not of the number passed to imagettftext().
     *
     * So the ink is measured and the band is filled, which also keeps a two-letter
     * monogram with a descender (`Ọ`) optically level with one without.
     */
    protected function centredInBand($im, string $s, int $size, string $font, int $colour, float $cx, float $bandTop, float $bandH): void
    {
        $box = imagettfbbox($size, 0, $font, $s);
        if ($box === false) {
            $this->centred($im, $s, $size, $font, $colour, $cx, $bandTop + $bandH * 0.8);
            return;
        }
        // imagettfbbox y values are measured UP from the baseline, so they are negative
        // above it. Top ink = min, bottom ink = max.
        $inkTop    = min($box[5], $box[7]);
        $inkBottom = max($box[1], $box[3]);
        $inkH      = $inkBottom - $inkTop;
        $baseline  = $bandTop + ($bandH - $inkH) / 2 - $inkTop;
        $this->centred($im, $s, $size, $font, $colour, $cx, $baseline);
    }

    /**
     * Word wrap by MEASURED width, not character count.
     *
     * The SVG wraps by character count because it cannot measure; here the real metrics
     * are available, so a name in a wide face and a rally line in a narrow one each break
     * where they actually run out of room.
     *
     * @return list<string>
     */
    protected function wrapMeasured(string $text, float $maxW, float $size, string $font, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = []; $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($this->width($try, $size, $font) <= $maxW || $cur === '') { $cur = $try; continue; }
            if (count($lines) + 1 >= $maxLines) {
                while ($cur !== '' && $this->width($cur . '…', $size, $font) > $maxW) $cur = mb_substr($cur, 0, -1);
                // Trim before appending: dropping characters can land on a space, and
                // "Adébáyọ …" reads as a rendering fault where "Adébáyọ…" reads as
                // deliberate truncation.
                $cur = rtrim($cur) . '…';
                break;
            }
            $lines[] = $cur; $cur = $w;
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }

    /** Vertical linear gradient, drawn a scanline at a time. */
    protected function vGradient($im, int $x, int $y, int $w, int $h, array $from, array $to): void
    {
        for ($i = 0; $i < $h; $i++) {
            $t = $h > 1 ? $i / ($h - 1) : 0;
            $c = imagecolorallocate($im,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t));
            imageline($im, $x, $y + $i, $x + $w - 1, $y + $i, $c);
        }
    }

    /** Draw $src to cover the box, cropping the overflow — never stretching it. */
    protected function cover($im, $src, int $dx, int $dy, int $dw, int $dh,
                            ?float $focusX = null, ?float $focusY = null): void
    {
        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) return;
        // A squashed face is the most obvious tell that a graphic was generated.
        $scale = max($dw / $sw, $dh / $sh);
        $cw = (int) round($dw / $scale);
        $ch = (int) round($dh / $scale);

        // The focal point, 0..1 in each axis, or the defaults.
        //
        // Horizontally centred (a subject is reliably centred left-to-right); vertically
        // biased upward, because they are reliably NOT centred top-to-bottom. A caller that
        // passes a point is a person who has dragged the frame themselves, and their answer
        // beats both defaults — which is why the event flier's reframe step exists at all.
        $fx = $focusX === null ? 0.5 : max(0.0, min(1.0, $focusX));
        $fy = $focusY === null ? self::PHOTO_ANCHOR_Y : max(0.0, min(1.0, $focusY));

        $sx = (int) round(($sw - $cw) * $fx);
        $sy = (int) round(($sh - $ch) * $fy);
        imagecopyresampled($im, $src, $dx, $dy, max(0, $sx), max(0, $sy), $dw, $dh, $cw, $ch);
    }

    /**
     * Read the nominee photo from disk or over HTTP.
     *
     * Local first: the photo is almost always an upload under public/, and reading it
     * from the filesystem avoids a request the server makes to itself — which on a
     * single-worker PHP server is a deadlock, not a slow path.
     */
    protected function loadPhoto(string $url): mixed
    {
        // ── THE SITE'S OWN FILES FIRST ──────────────────────────────────────────
        // A public asset is written `/assets/...`, which also starts with a slash — so
        // checked after the disk-path branch below it was taken for an absolute path,
        // found outside the upload directories, refused, and the challenge flier drew no
        // artwork at all. A path that names a file under public/ is that file.
        $pub = dirname(__DIR__, 2) . '/public';
        $p0  = parse_url($url, PHP_URL_PATH);
        if (is_string($p0) && str_starts_with($p0, '/') && !str_contains($p0, '..')) {
            $local = $pub . rawurldecode($p0);
            if (is_file($local)) {
                $im = @imagecreatefromstring((string) @file_get_contents($local));
                if ($im !== false) return $im;
            }
        }

        // An absolute path on this disk, which is what the event flier hands over: the
        // uploaded photo is cropped to the slot and DISCARDED, so it never gets a URL. Bounded
        // to the two directories a temporary upload can legitimately be in, because this
        // string reaches the filesystem and a caller that let a visitor choose it would be
        // handing over a file reader.
        if (str_starts_with($url, '/')) {
            $real = realpath($url);
            $ok = false;
            foreach ([realpath(sys_get_temp_dir()), realpath(dirname(__DIR__, 2) . '/var')] as $dir) {
                if ($real !== false && $dir !== false && str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
                    $ok = true; break;
                }
            }
            if ($ok && is_file($real)) {
                $im = @imagecreatefromstring((string) @file_get_contents($real));
                if ($im !== false) return $im;
            }
            if (!$ok) {
                error_log('[flier] refused a photo path outside the upload directories: ' . $url);
                return null;
            }
        }

        // Then the public root — no network at all when the file is served from this disk.
        $root = dirname(__DIR__, 2) . '/public';
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && !str_contains($path, '..')) {
            $local = $root . rawurldecode($path);
            if (is_file($local)) {
                $im = @imagecreatefromstring((string) @file_get_contents($local));
                if ($im !== false) return $im;
            }
        }

        $data = $this->fetch($url);
        if ($data === null) {
            // NOT silent. A failed photo fetch is indistinguishable in the output from
            // "this nominee has no photo" — both render the monogram — so without this
            // line the only symptom is a face missing from a share card and nothing
            // anywhere saying why. {@see fontsPresent()} and app:doctor exist for
            // exactly this class of invisible degradation.
            error_log('[flier] could not load nominee photo: ' . $url
                . (ini_get('allow_url_fopen') ? '' : ' (allow_url_fopen is OFF)'));
            return null;
        }
        $im = @imagecreatefromstring($data);
        if ($im === false) {
            error_log('[flier] nominee photo is not a decodable image: ' . $url);
            return null;
        }
        return $im;
    }

    /**
     * Fetch a remote photo, by whichever transport this host actually allows.
     *
     * ── WHY BOTH ─────────────────────────────────────────────────────────────
     *
     * This used `file_get_contents($url)` alone, which requires `allow_url_fopen` — and
     * shared cPanel hosting very commonly ships with it OFF. On such a host EVERY
     * Cloudinary-hosted nominee photo failed to load, and because a null photo falls
     * back to the monogram, the result was a share card and og:image with a letterform
     * where the person's face should be. No error, no log, nothing to search for.
     *
     * cURL is tried first: it is present on effectively every PHP host, is unaffected
     * by allow_url_fopen, and gives real control over redirects and timeouts. The
     * stream wrapper stays as the fallback for the rare build without ext-curl.
     *
     * ── THE TIMEOUTS ─────────────────────────────────────────────────────────
     *
     * A crawler will not wait. But 4s flat was too tight for the FIRST request to a
     * Cloudinary derivative that has not been generated yet — the transform is built on
     * demand, and the cold request is exactly the one a nominee triggers by sharing a
     * new photo for the first time. 3s to connect, 8s overall.
     */
    protected function fetch(string $url): ?string
    {
        // ── HTTP AND HTTPS, AND NOTHING ELSE ─────────────────────────────────
        //
        // A photo URL is a value somebody stored, and both transports below speak far
        // more than the web: cURL does `file://`, `gopher://`, `dict://`, and the stream
        // wrapper does `file://`, `php://filter` and `phar://`. So `file:///…/.env` was a
        // "photo" that failed to decode as an image AFTER being read off this disk — and
        // a redirect from an allowed host could carry cURL to any of those as well. The
        // scheme is checked here for the request and pinned on the handle for every hop.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            error_log('[flier] refused a photo URL that is not http(s): ' . $url);
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 8,
                // Cloudinary and several CDNs 302 to a regional edge. Bounded, so a
                // redirect loop cannot hold the request open.
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'AfricaGates-Flier/1.0',
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'http,https');
            } else {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            $out  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if (is_string($out) && $out !== '' && $code >= 200 && $code < 300) return $out;
            error_log('[flier] photo fetch failed (curl): HTTP ' . $code . ' ' . $err . ' — ' . $url);
            // Fall through: a proxy quirk that breaks cURL may not break the wrapper.
        }

        if (!ini_get('allow_url_fopen')) return null;

        $ctx  = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'max_redirects' => 3]]);
        $data = @file_get_contents($url, false, $ctx);
        return is_string($data) && $data !== '' ? $data : null;
    }
}
