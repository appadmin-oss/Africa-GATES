<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * A small PDF writer, in millimetres, for documents that have to come out the right size.
 *
 * ── WHY NOT A LIBRARY ────────────────────────────────────────────────────────
 *
 * The usual answer is Dompdf or mPDF: render the existing Twig and let the library lay it
 * out. Both were considered and both are the wrong tool for this particular job.
 *
 * Neither supports flexbox or CSS grid, which is what every template on this site is built
 * from — so "reuse the template" is not what would actually happen. What would happen is a
 * SECOND layout, written in floats and tables, maintained beside the first and drifting from
 * it. And mPDF with its font pack is thirty megabytes on a shared cPanel host that has to be
 * deployed by upload.
 *
 * What is actually needed is narrow: rectangles, rules, text, and a grid of black squares.
 * Written directly it is one file, no dependency, a few kilobytes of output, and — the part
 * that matters for a ticket — EXACT control of physical size. A QR that is 30mm is 30mm.
 *
 * ── WHY THE BROWSER'S PRINT DIALOGUE IS NOT USED ─────────────────────────────
 *
 * `@media print` puts the final artefact in the hands of the browser and its driver. Page
 * size, margins, whether background fills print at all, and the scale factor are all the
 * user's settings, and "Fit to page" is on by default in more than one browser — which
 * silently rescales a QR code below the size a scanner can resolve. Nobody discovers that
 * until a queue is not moving. A PDF is a fixed artefact: the same millimetres on every
 * machine, and the same file can be emailed, archived or sent to a print shop.
 *
 * ── WHAT IS NOT HERE ─────────────────────────────────────────────────────────
 *
 * No transparency, no annotations, no encryption, no vector graphics beyond rectangles and
 * rules. Images are JPEG only, because DCTDecode is the JPEG codec and the bytes pass
 * straight through — PNG would mean a scanline decoder this class has no business containing.
 * Text is drawn, not flowed: there is a word-wrapper because captions wrap, and that is the
 * whole of the layout engine. Anything beyond it belongs in a real library.
 */
final class Pdf
{
    /** PostScript points per millimetre. The only unit conversion in the file. */
    private const PT = 72 / 25.4;

    /** @var array<int,string> object body, indexed from 1 */
    private array $objects = [];

    /** @var array<int,array{content:string,w:float,h:float}> */
    private array $pages = [];

    private string $buffer = '';
    private float  $pageW  = 210.0;
    private float  $pageH  = 297.0;

    /** @var array<string,array{font:TrueType,gids:array<int,int>,alias:string,fallback:?string}> */
    private array $fonts = [];

    /** @var array<int,array{data:string,w:int,h:int,ch:int}> */
    private array $images = [];

    /**
     * Fill opacities in use, as alpha => graphics-state number.
     *
     * PDF has no per-operator alpha. Transparency is a GRAPHICS STATE the content stream
     * switches into, so each distinct value used becomes one small ExtGState object and the
     * stream refers to it by name — which is why these are collected rather than emitted.
     *
     * @var array<string,int>
     */
    private array $alphas = [];
    private string $defaultFont = '';

    public function __construct(float $widthMm = 210.0, float $heightMm = 297.0)
    {
        $this->pageW = $widthMm;
        $this->pageH = $heightMm;
    }

    // ──────────────────────────────── fonts ─────────────────────────────────

    /**
     * Register a face under a short name.
     *
     * `$fallback` names a previously registered face to borrow from for any character this
     * one lacks. It is not a nicety: the site's own DM Sans has no Ọ, ẹ, ṣ or ₦, so a ticket
     * for Ọlásùnkànmí set in the brand face alone would print boxes through the middle of
     * somebody's name. Runs are split per character, so the brand face still sets everything
     * it can.
     */
    public function font(string $alias, string $path, ?string $fallback = null): bool
    {
        $f = TrueType::load($path);
        if (!$f) return false;

        $this->fonts[$alias] = ['font' => $f, 'gids' => [], 'alias' => $alias, 'fallback' => $fallback];
        if ($this->defaultFont === '') $this->defaultFont = $alias;
        return true;
    }

    public function hasFont(string $alias): bool { return isset($this->fonts[$alias]); }

    // ──────────────────────────────── pages ─────────────────────────────────

    public function addPage(): void
    {
        if ($this->buffer !== '') $this->flushPage();
        $this->buffer = '';
    }

    private function flushPage(): void
    {
        $this->pages[] = ['content' => $this->buffer, 'w' => $this->pageW, 'h' => $this->pageH];
        $this->buffer = '';
    }

    public function pageWidth(): float  { return $this->pageW; }
    public function pageHeight(): float { return $this->pageH; }

    // ─────────────────────────────── drawing ────────────────────────────────

    /**
     * PDF's origin is bottom-left and every caller here thinks top-down, so y is flipped
     * once, here, rather than in each of forty call sites.
     */
    private function y(float $mm): float { return ($this->pageH - $mm) * self::PT; }
    private function x(float $mm): float { return $mm * self::PT; }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     * @param float $alpha 1 is opaque. Used for the wash over a ticket's artwork, which is
     *                     what keeps a title legible over a photograph nobody chose.
     */
    public function rect(float $x, float $y, float $w, float $h, array $rgb, float $alpha = 1.0): void
    {
        $g = $this->alphaState($alpha);
        $this->buffer .= sprintf(
            "q %s%s rg %.2F %.2F %.2F %.2F re f Q\n",
            $g, self::colour($rgb), $this->x($x), $this->y($y + $h), $w * self::PT, $h * self::PT
        );
    }

    /**
     * Name a fill opacity, registering it the first time it is asked for.
     *
     * Returns the operator to emit, or an empty string for fully opaque — the common case
     * should not cost a graphics-state switch.
     */
    private function alphaState(float $alpha): string
    {
        $a = max(0.0, min(1.0, $alpha));
        if ($a >= 1.0) return '';

        $key = number_format($a, 3, '.', '');
        if (!isset($this->alphas[$key])) $this->alphas[$key] = count($this->alphas) + 1;
        return '/GS' . $this->alphas[$key] . ' gs ';
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $widthMm = 0.2,
                         ?array $dash = null): void
    {
        $d = $dash === null
            ? "[] 0 d"
            : sprintf('[%.2F %.2F] 0 d', $dash[0] * self::PT, $dash[1] * self::PT);

        $this->buffer .= sprintf(
            "%s RG %.2F w %s %.2F %.2F m %.2F %.2F l S [] 0 d\n",
            self::colourStroke($rgb), $widthMm * self::PT, $d,
            $this->x($x1), $this->y($y1), $this->x($x2), $this->y($y2)
        );
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    public function frame(float $x, float $y, float $w, float $h, array $rgb,
                          float $widthMm = 0.2, ?array $dash = null): void
    {
        $d = $dash === null
            ? "[] 0 d"
            : sprintf('[%.2F %.2F] 0 d', $dash[0] * self::PT, $dash[1] * self::PT);

        $this->buffer .= sprintf(
            "%s RG %.2F w %s %.2F %.2F %.2F %.2F re S [] 0 d\n",
            self::colourStroke($rgb), $widthMm * self::PT, $d,
            $this->x($x), $this->y($y + $h), $w * self::PT, $h * self::PT
        );
    }

    /**
     * A QR symbol drawn as filled squares, at an exact physical size.
     *
     * Takes the module matrix rather than an image, so there is no rasterisation anywhere in
     * the path: the symbol is vector, prints at the printer's own resolution, and every
     * module edge lands exactly where the arithmetic says. That is the difference between a
     * code a cheap scanner reads first time and one it hunts for.
     *
     * The quiet zone is drawn as part of the symbol, because it is part of the symbol —
     * a QR flush against a rule is one a decoder cannot find the edge of.
     *
     * @param array<int,array<int,bool>> $matrix
     */
    public function qr(array $matrix, float $x, float $y, float $sizeMm, int $quiet = 4,
                       array $ground = [255, 255, 255]): void
    {
        $n = count($matrix);
        if ($n === 0) return;

        $span   = $n + $quiet * 2;
        $module = $sizeMm / $span;

        // The ground is DRAWN, not left to the paper — a decoder measures contrast against
        // what is actually there, so a symbol dropped onto a tinted card without its own
        // ground reads the tint as a light module and loses the edge of the quiet zone.
        //
        // It is a parameter because white is not always right: on cream stock a white square
        // is a visible patch on the design, and cream is light enough to carry the symbol.
        // Anything darker than about 60% luminance should stay white.
        $this->rect($x, $y, $sizeMm, $sizeMm, $ground);

        // One path with many subpaths, filled once — a `re f` per module would be forty
        // thousand operators on a six-ticket sheet.
        $ops = '';
        foreach ($matrix as $r => $row) {
            foreach ($row as $c => $on) {
                if (!$on) continue;
                $mx = $x + ($c + $quiet) * $module;
                $my = $y + ($r + $quiet) * $module;
                $ops .= sprintf("%.3F %.3F %.3F %.3F re ",
                    $this->x($mx), $this->y($my + $module), $module * self::PT, $module * self::PT);
            }
        }
        if ($ops !== '') $this->buffer .= "0 0 0 rg " . $ops . "f\n";
    }

    /**
     * Clip everything drawn until popClip() to a rectangle.
     *
     * The ticket's tier watermark is set larger than the panel holding it — deliberately, it
     * is meant to run off the edge — and without a clip it runs over the perforation and into
     * the stub, straight through the address.
     */
    public function pushClip(float $x, float $y, float $w, float $h): void
    {
        $this->buffer .= sprintf("q %.2F %.2F %.2F %.2F re W n\n",
            $this->x($x), $this->y($y + $h), $w * self::PT, $h * self::PT);
    }

    public function popClip(): void
    {
        $this->buffer .= "Q\n";
    }

    /**
     * A vertical wash: opaque at the bottom, nothing at the top.
     *
     * ── WHY IT IS BANDED, AND WHY THE BANDS DO NOT OVERLAP ───────────────────
     *
     * PDF has axial shadings, and one that fades to TRANSPARENT needs a soft mask — a second
     * image in its own colour space, referenced from a graphics state. That is a great deal
     * of machinery for one gradient. Sixty-four bands over forty millimetres is 0.6mm each,
     * which is below the width at which an eye finds an edge on paper.
     *
     * They are drawn EDGE TO EDGE with no overlap. The first cut added two per cent to each
     * band's height "to avoid hairline gaps", and every overlap then took its neighbour's
     * alpha a second time — which printed as a ladder of darker seams straight down the
     * artwork. Alpha does not tile.
     *
     * ── AND THE STOPS ARE THE DESIGN'S, NOT A CURVE ──────────────────────────
     *
     * Interpolated between the four stops the artwork direction specifies rather than fitted
     * to an exponent. A curve that was merely "about right" left the kicker sitting on a part
     * of the wash too weak to carry it, and gold type on a gold panel is not type.
     *
     * @param array{0:int,1:int,2:int}   $rgb
     * @param array<int,array{0:float,1:float}> $stops [distance from the TOP of the wash 0..1,
     *                                                  alpha], ascending
     */
    public function wash(float $x, float $y, float $w, float $h, array $rgb,
                         array $stops = [[0.0, 0.0], [0.26, 0.12], [0.65, 0.72], [1.0, 0.96]],
                         int $bands = 64): void
    {
        if ($h <= 0 || $bands < 1 || count($stops) < 2) return;

        $step = $h / $bands;
        for ($i = 0; $i < $bands; $i++) {
            $t = ($i + 0.5) / $bands;

            $a = $stops[0][1];
            for ($k = 1, $n = count($stops); $k < $n; $k++) {
                [$t0, $a0] = $stops[$k - 1];
                [$t1, $a1] = $stops[$k];
                if ($t <= $t1 || $k === $n - 1) {
                    $span = ($t1 - $t0) ?: 1.0;
                    $a = $a0 + ($a1 - $a0) * max(0.0, min(1.0, ($t - $t0) / $span));
                    break;
                }
            }
            if ($a > 0.002) $this->rect($x, $y + $i * $step, $w, $step, $rgb, $a);
        }
    }

    /**
     * Place a JPEG, cropped to fill the box, with no rescaling of its pixels.
     *
     * ── WHY JPEG AND NOTHING ELSE ────────────────────────────────────────────
     *
     * A JPEG's compressed bytes go into the file untouched, because PDF speaks DCTDecode
     * natively — the same codec. Nothing is decoded, nothing is re-encoded, and a 200KB
     * photograph costs 200KB. PNG would mean decompressing every scanline, handling palettes,
     * interlacing and alpha, and re-deflating the result, which is a decoder this class has no
     * business containing. Callers transcode first; see TicketPdf.
     *
     * ── AND WHY THE CROP IS DONE HERE ────────────────────────────────────────
     *
     * A ticket's artwork panel has a fixed aspect ratio and the poster it comes from does not.
     * Stretching to fit distorts faces, which is the one thing nobody forgives on a printed
     * ticket. So the image is scaled to COVER the box and the overflow is clipped, exactly as
     * `object-fit: cover` would.
     *
     * @param float $focusY 0 = align the top of the image, 1 = the bottom. Posters put their
     *                      subject above the middle and their text below it, so the default
     *                      leans upward.
     */
    public function image(string $jpeg, float $x, float $y, float $w, float $h,
                          float $focusY = 0.35): bool
    {
        $size = self::jpegSize($jpeg);
        if ($size === null || $w <= 0 || $h <= 0) return false;

        [$iw, $ih, $channels] = $size;
        $id = count($this->images) + 1;
        $this->images[$id] = ['data' => $jpeg, 'w' => $iw, 'h' => $ih, 'ch' => $channels];

        // Cover: scale by whichever axis needs the most, then clip.
        $scale = max($w / $iw, $h / $ih);
        $dw    = $iw * $scale;
        $dh    = $ih * $scale;
        $ox    = $x - ($dw - $w) / 2;
        $oy    = $y - ($dh - $h) * $focusY;

        $this->buffer .= sprintf(
            "q %.2F %.2F %.2F %.2F re W n\n"           // clip to the box
            . "%.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n",
            $this->x($x), $this->y($y + $h), $w * self::PT, $h * self::PT,
            $dw * self::PT, $dh * self::PT, $this->x($ox), $this->y($oy + $dh), $id
        );
        return true;
    }

    /**
     * Width, height and colour channels from a JPEG's start-of-frame marker.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function jpegSize(string $s): ?array
    {
        $n = strlen($s);
        if ($n < 4 || substr($s, 0, 2) !== "\xFF\xD8") return null;

        $i = 2;
        while ($i + 9 < $n) {
            if ($s[$i] !== "\xFF") { $i++; continue; }
            $marker = ord($s[$i + 1]);
            // SOF0–SOF15, minus the four that are not frame headers (DHT, JPG, DAC, RST).
            if ($marker >= 0xC0 && $marker <= 0xCF
                && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                $h = (ord($s[$i + 5]) << 8) | ord($s[$i + 6]);
                $w = (ord($s[$i + 7]) << 8) | ord($s[$i + 8]);
                $c = ord($s[$i + 9]);
                return ($w > 0 && $h > 0) ? [$w, $h, $c] : null;
            }
            $len = (ord($s[$i + 2]) << 8) | ord($s[$i + 3]);
            if ($len < 2) return null;
            $i += 2 + $len;
        }
        return null;
    }

    // ──────────────────────────────── text ──────────────────────────────────

    /**
     * Draw a single line, with `$y` as its BASELINE.
     *
     * @param array{0:int,1:int,2:int} $rgb
     * @param float $tracking extra space between glyphs, in 1/1000 em — the letter-spacing
     *                        that makes a ticket code legible at arm's length
     */
    /**
     * @param float $shear a slant, as the tangent of the angle. 0.21 is about 12 degrees,
     *                     which is where a synthesised italic stops reading as a mistake.
     *                     Used for the ticket's tier watermark, because the bundled Playfair
     *                     ships upright only and shipping a second 116KB face to slant one
     *                     word is a poor trade.
     */
    public function text(string $s, float $x, float $y, string $alias, float $sizePt,
                         array $rgb = [0, 0, 0], float $tracking = 0.0, float $alpha = 1.0,
                         float $shear = 0.0): void
    {
        if ($s === '' || !isset($this->fonts[$alias])) return;

        // `$state`, not `$g` — the glyph loop below binds `$g` per glyph, and naming the
        // graphics-state operator the same thing meant it held an integer by the time the
        // closing brace was tested. Every string then emitted an unmatched `Q`, which
        // unbalances the whole content stream: readers recover, so the pages still drew, and
        // the only symptom was a warning nobody would see in a browser.
        $state = $this->alphaState($alpha);
        if ($state !== '') $this->buffer .= 'q ' . $state . "\n";
        $this->buffer .= sprintf("BT %s rg %.2F Tc\n", self::colour($rgb), $tracking / 1000 * $sizePt);
        $cursor = $x;

        foreach ($this->runs($s, $alias) as [$runAlias, $gids]) {
            $hex = '';
            foreach ($gids as $gid) {
                $hex .= sprintf('%04X', $gid);
                $this->fonts[$runAlias]['gids'][$gid] = $gid;
            }
            $this->buffer .= sprintf(
                "/F%s %.2F Tf 1 0 %.3F 1 %.2F %.2F Tm <%s> Tj\n",
                self::objSafe($runAlias), $sizePt, $shear, $this->x($cursor), $this->y($y), $hex
            );
            $cursor += $this->runWidth($runAlias, $gids, $sizePt, $tracking);
        }

        $this->buffer .= "0 Tc ET\n";
        if ($state !== '') $this->buffer .= "Q\n";
    }

    /**
     * The same, turned ninety degrees anticlockwise, reading upward from `$y`.
     *
     * One narrow use — the ticket code down the edge of a stub — but it has to be a real
     * transform rather than a column of single characters, because a rotated string keeps its
     * kerning, its tracking and its selectability, and a stack of glyphs is none of those.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function textUp(string $s, float $x, float $y, string $alias, float $sizePt,
                           array $rgb = [0, 0, 0], float $tracking = 0.0): void
    {
        if ($s === '' || !isset($this->fonts[$alias])) return;

        $this->buffer .= sprintf("BT %s rg %.2F Tc\n", self::colour($rgb), $tracking / 1000 * $sizePt);
        $cursor = 0.0;

        foreach ($this->runs($s, $alias) as [$runAlias, $gids]) {
            $hex = '';
            foreach ($gids as $gid) {
                $hex .= sprintf('%04X', $gid);
                $this->fonts[$runAlias]['gids'][$gid] = $gid;
            }
            // The 0 1 -1 0 matrix is a quarter turn; the translation still names the point the
            // baseline starts from, so callers think in the same coordinates as text().
            $this->buffer .= sprintf(
                "/F%s %.2F Tf 0 1 -1 0 %.2F %.2F Tm <%s> Tj\n",
                self::objSafe($runAlias), $sizePt, $this->x($x), $this->y($y) + $cursor * self::PT, $hex
            );
            $cursor += $this->runWidth($runAlias, $gids, $sizePt, $tracking);
        }

        $this->buffer .= "0 Tc ET\n";
    }

    /** Width of a string at a size, in millimetres. */
    public function width(string $s, string $alias, float $sizePt, float $tracking = 0.0): float
    {
        $w = 0.0;
        foreach ($this->runs($s, $alias) as [$runAlias, $gids]) {
            $w += $this->runWidth($runAlias, $gids, $sizePt, $tracking);
        }
        return $w;
    }

    private function runWidth(string $alias, array $gids, float $sizePt, float $tracking): float
    {
        $f = $this->fonts[$alias]['font'];
        $u = 0;
        foreach ($gids as $g) $u += $f->width($g) + $tracking;
        return $u / 1000 * $sizePt / self::PT;
    }

    /**
     * Split a string into runs of consecutive characters one face can actually set.
     *
     * @return array<int,array{0:string,1:array<int,int>}>
     */
    private function runs(string $s, string $alias): array
    {
        $primary  = $this->fonts[$alias]['font'];
        $fallback = $this->fonts[$alias]['fallback'] ?? null;
        $fbFont   = ($fallback !== null && isset($this->fonts[$fallback]))
            ? $this->fonts[$fallback]['font'] : null;

        $out  = [];
        $cur  = null;
        $gids = [];

        foreach (self::codepoints($s) as $cp) {
            $g    = $primary->gid($cp);
            $use  = $alias;
            if ($g === 0 && $fbFont !== null) {
                $fg = $fbFont->gid($cp);
                if ($fg !== 0) { $g = $fg; $use = (string) $fallback; }
            }
            // Still nothing anywhere: drop the character rather than draw .notdef. An empty
            // box in a name reads as data corruption; a missing accent reads as a font that
            // does not have it, which is the truth.
            if ($g === 0) continue;

            if ($cur !== $use) {
                if ($cur !== null && $gids !== []) $out[] = [$cur, $gids];
                $cur  = $use;
                $gids = [];
            }
            $gids[] = $g;
        }
        if ($cur !== null && $gids !== []) $out[] = [$cur, $gids];
        return $out;
    }

    /** @return array<int,int> */
    private static function codepoints(string $s): array
    {
        $out = [];
        $n   = strlen($s);
        for ($i = 0; $i < $n;) {
            $c = ord($s[$i]);
            if ($c < 0x80)      { $out[] = $c; $i += 1; }
            elseif ($c < 0xE0)  { $out[] = (($c & 0x1F) << 6) | (ord($s[$i + 1] ?? "\0") & 0x3F); $i += 2; }
            elseif ($c < 0xF0)  { $out[] = (($c & 0x0F) << 12) | ((ord($s[$i + 1] ?? "\0") & 0x3F) << 6)
                                          | (ord($s[$i + 2] ?? "\0") & 0x3F); $i += 3; }
            else                { $out[] = (($c & 0x07) << 18) | ((ord($s[$i + 1] ?? "\0") & 0x3F) << 12)
                                          | ((ord($s[$i + 2] ?? "\0") & 0x3F) << 6)
                                          | (ord($s[$i + 3] ?? "\0") & 0x3F); $i += 4; }
        }
        return $out;
    }

    /**
     * How many lines a string will take at a width, capped at `$maxLines`.
     *
     * Exists so a caller can ask BEFORE drawing whether a block fits the space left. The
     * alternative — reserving `$maxLines` worth of room for every field — drops fields that
     * would have fitted comfortably, which on a ticket meant the venue address disappearing
     * to make room for space that then stayed empty.
     */
    public function lines(string $s, float $maxWidth, string $alias, float $sizePt,
                          int $maxLines = 99): int
    {
        $words = preg_split('/\s+/u', trim($s)) ?: [];
        if ($words === [] || $words === ['']) return 0;

        $n    = 1;
        $line = '';
        foreach ($words as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            if ($this->width($try, $alias, $sizePt) <= $maxWidth || $line === '') {
                $line = $try;
            } else {
                if (++$n >= $maxLines) return $maxLines;
                $line = $w;
            }
        }
        return min($n, $maxLines);
    }

    /**
     * Break a string into lines that fit a width, and draw at most `$maxLines` of them.
     *
     * Returns the baseline y after the last line, so a caller can stack blocks without
     * knowing how many lines a venue address happened to need.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public function paragraph(string $s, float $x, float $y, float $maxWidth, string $alias,
                              float $sizePt, float $leadingMm, array $rgb = [0, 0, 0],
                              int $maxLines = 99): float
    {
        $words = preg_split('/\s+/u', trim($s)) ?: [];
        if ($words === []) return $y;

        $lines = [];
        $line  = '';
        foreach ($words as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            if ($this->width($try, $alias, $sizePt) <= $maxWidth || $line === '') {
                $line = $try;
            } else {
                $lines[] = $line;
                $line = $w;
            }
            if (count($lines) >= $maxLines) break;
        }
        if ($line !== '' && count($lines) < $maxLines) $lines[] = $line;

        // An address cut short is the one field on a ticket that cannot be guessed from the
        // others, so the truncation is marked rather than silent.
        if (count($lines) === $maxLines && $line !== '' && end($lines) !== $line) {
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' ,') . '…';
        }

        foreach ($lines as $l) {
            $this->text($l, $x, $y, $alias, $sizePt, $rgb);
            $y += $leadingMm;
        }
        return $y;
    }

    // ─────────────────────────────── output ─────────────────────────────────

    public function output(): string
    {
        if ($this->buffer !== '') $this->flushPage();
        if ($this->pages === []) $this->pages[] = ['content' => '', 'w' => $this->pageW, 'h' => $this->pageH];

        $this->objects = [];
        $catalog = $this->reserve();
        $pagesId = $this->reserve();

        // Fonts first, so every page can reference them and the ids are known.
        $fontIds = [];
        foreach ($this->fonts as $alias => $f) {
            if ($f['gids'] === []) continue;                    // never used: never embedded
            $fontIds[$alias] = $this->writeFont($alias);
        }

        $resources = '/Font <<';
        foreach ($fontIds as $alias => $id) {
            $resources .= '/F' . self::objSafe($alias) . ' ' . $id . ' 0 R ';
        }
        $resources .= '>>';

        if ($this->alphas !== []) {
            $resources .= ' /ExtGState <<';
            foreach ($this->alphas as $value => $n) {
                $id = $this->reserve();
                // /ca is the FILL alpha and /CA the stroke one. Both are set because a wash
                // that dimmed its fill and not its rule would show a hairline at full strength
                // through a panel that is supposed to be behind it.
                $this->objects[$id] = sprintf('<< /Type /ExtGState /ca %s /CA %s >>', $value, $value);
                $resources .= '/GS' . $n . ' ' . $id . ' 0 R ';
            }
            $resources .= '>>';
        }

        if ($this->images !== []) {
            $resources .= ' /XObject <<';
            foreach ($this->images as $n => $im) {
                $id = $this->reserve();
                // The JPEG's own bytes, straight through. DCTDecode IS the JPEG codec, so
                // there is nothing to transcode and a 200KB photograph costs 200KB.
                $this->objects[$id] = sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                    . "/ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\n"
                    . "stream\n%s\nendstream",
                    $im['w'], $im['h'],
                    $im['ch'] === 1 ? 'DeviceGray' : ($im['ch'] === 4 ? 'DeviceCMYK' : 'DeviceRGB'),
                    strlen($im['data']), $im['data']
                );
                $resources .= '/Im' . $n . ' ' . $id . ' 0 R ';
            }
            $resources .= '>>';
        }

        $pageIds = [];
        foreach ($this->pages as $p) {
            $streamId = $this->reserve();
            $this->objects[$streamId] = $this->stream($p['content']);

            $pageId = $this->reserve();
            $this->objects[$pageId] = sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << %s >> "
                . "/Contents %d 0 R >>",
                $pagesId, $p['w'] * self::PT, $p['h'] * self::PT, $resources, $streamId
            );
            $pageIds[] = $pageId;
        }

        $kids = implode(' ', array_map(static fn($i) => $i . ' 0 R', $pageIds));
        $this->objects[$pagesId] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>',
            $kids, count($pageIds));
        $this->objects[$catalog] = sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesId);

        return $this->serialise($catalog);
    }

    /**
     * Claim the next object number.
     *
     * Keyed EXPLICITLY from 1, not appended. `$objects[] =` starts at key 0 while the
     * returned count starts at 1, so every reference in the file pointed one object past the
     * one it meant and the cross-reference table addressed an object that was not there.
     * Readers recover by rebuilding the xref, which is why it still rendered — and why it
     * would have shipped.
     */
    private function reserve(): int
    {
        $id = count($this->objects) + 1;                        // objects are 1-indexed in PDF
        $this->objects[$id] = '';
        return $id;
    }

    /**
     * A stream, compressed when zlib is available and plainly when it is not.
     *
     * The pairing matters: declaring `/FlateDecode` over bytes that were never deflated
     * produces a file every reader rejects, and a shared cPanel host without the zlib
     * extension is a real deployment rather than a hypothetical one. So the filter is
     * written only when the compression actually happened.
     */
    private function stream(string $content, string $extra = ''): string
    {
        $z      = function_exists('gzcompress') ? (string) gzcompress($content, 6) : $content;
        $filter = function_exists('gzcompress') ? '/Filter /FlateDecode ' : '';
        return "<< /Length " . strlen($z) . " " . $filter . $extra . ">>\n"
             . "stream\n" . $z . "\nendstream";
    }

    private function writeFont(string $alias): int
    {
        $f    = $this->fonts[$alias]['font'];
        $gids = array_keys($this->fonts[$alias]['gids']);
        sort($gids);

        $file = $f->subset($gids);
        $hasZlib = function_exists('gzcompress');
        $bytes   = $hasZlib ? (string) gzcompress($file, 6) : $file;

        $fileId = $this->reserve();
        $this->objects[$fileId] = "<< /Length " . strlen($bytes) . " /Length1 " . strlen($file)
            . ($hasZlib ? " /Filter /FlateDecode" : '') . " >>\nstream\n" . $bytes . "\nendstream";

        [$x0, $y0, $x1, $y1] = $f->bboxThousandths();
        $descId = $this->reserve();
        $this->objects[$descId] = sprintf(
            "<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] "
            . "/ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>",
            $f->name(), $x0, $y0, $x1, $y1,
            $f->ascenderThousandths(), $f->descenderThousandths(),
            max(1, $f->ascenderThousandths()), $fileId
        );

        // /W, the widths PDF actually measures with. Written as individual pairs rather than
        // ranges: a subset's glyph ids are scattered, so ranges would mostly be one long.
        $w = '';
        foreach ($gids as $g) $w .= $g . ' [' . $f->width($g) . '] ';

        $cidId = $this->reserve();
        $this->objects[$cidId] = sprintf(
            "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s "
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> "
            . "/FontDescriptor %d 0 R /DW 1000 /W [%s] /CIDToGIDMap /Identity >>",
            $f->name(), $descId, trim($w)
        );

        $toUniId = $this->reserve();
        $this->objects[$toUniId] = $this->stream($this->toUnicode($alias, $gids));

        $fontId = $this->reserve();
        $this->objects[$fontId] = sprintf(
            "<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H "
            . "/DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>",
            $f->name(), $cidId, $toUniId
        );
        return $fontId;
    }

    /**
     * The glyph-to-character map that makes a PDF's text selectable and searchable.
     *
     * Without it a ticket's code cannot be copied out of the file, and neither can a name —
     * which turns a support request into somebody retyping a reference from a screenshot.
     *
     * @param array<int,int> $gids
     */
    private function toUnicode(string $alias, array $gids): string
    {
        $f    = $this->fonts[$alias]['font'];
        $seen = [];
        // The font maps character → glyph; this needs the inverse, and only for what was
        // actually drawn. Walking the ranges a ticket can contain is cheaper and more
        // predictable than inverting a whole cmap.
        foreach (self::UNICODE_SWEEP as [$from, $to]) {
            for ($cp = $from; $cp <= $to; $cp++) {
                $g = $f->gid($cp);
                if ($g !== 0 && !isset($seen[$g]) && in_array($g, $gids, true)) $seen[$g] = $cp;
            }
        }

        $pairs = '';
        $n     = 0;
        $body  = '';
        foreach ($seen as $g => $cp) {
            $pairs .= sprintf("<%04X> <%s>\n", $g, self::utf16Hex($cp));
            if (++$n % 100 === 0) {
                $body .= "100 beginbfchar\n" . $pairs . "endbfchar\n";
                $pairs = '';
            }
        }
        if ($pairs !== '') $body .= ($n % 100) . " beginbfchar\n" . $pairs . "endbfchar\n";

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
             . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
             . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
             . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
             . $body
             . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    /** The ranges a ticket's text can occupy — ASCII, Latin-1/Extended, African Latin, punctuation, currency. */
    private const UNICODE_SWEEP = [
        [0x0020, 0x007E], [0x00A0, 0x024F], [0x1E00, 0x1EFF],
        [0x2010, 0x203A], [0x20A0, 0x20BF],
    ];

    private static function utf16Hex(int $cp): string
    {
        if ($cp <= 0xFFFF) return sprintf('%04X', $cp);
        $cp -= 0x10000;
        return sprintf('%04X%04X', 0xD800 + ($cp >> 10), 0xDC00 + ($cp & 0x3FF));
    }

    private function serialise(int $catalog): string
    {
        $out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($this->objects as $i => $body) {
            $offsets[$i] = strlen($out);
            $out .= $i . " 0 obj\n" . $body . "\nendobj\n";
        }

        $start = strlen($out);
        $count = count($this->objects) + 1;
        $out  .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        foreach ($this->objects as $i => $_) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . $count . " /Root " . $catalog . " 0 R >>\n"
              . "startxref\n" . $start . "\n%%EOF";
        return $out;
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function colour(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function colourStroke(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    private static function objSafe(string $alias): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', $alias);
    }
}
