<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * A live countdown, as an animated GIF, rendered per request.
 *
 * ── WHY A GIF AND NOT A SCRIPT ────────────────────────────────────────────────
 * Inboxes do not execute JavaScript, and the ones that allow CSS animation are a
 * minority. The only countdown that is actually live in an email is an IMAGE the
 * server draws at the moment the client fetches it. Every open re-requests it, so
 * every open gets the true remaining time; the animation then ticks for the length
 * of the loop while somebody is looking at it.
 *
 * ── WHY WE DRAW IT AND NOT SENDTRIC/MOTIONMAIL ────────────────────────────────
 * The obvious alternative is a hosted timer service, and the attached design was
 * written against one. It is one line of markup — and it also means a third party
 * receives a request carrying the recipient's IP and User-Agent every time any of
 * these emails is opened. For a platform that publishes a privacy page promising
 * nominee contact details are never passed on, that is a disclosure we would have
 * to make and would rather not need. This costs ~40ms of CPU and leaves the data
 * here.
 *
 * ── PHP CANNOT WRITE ANIMATED GIFS ────────────────────────────────────────────
 * `imagegif()` writes exactly one frame; GD has no animation API. So each frame is
 * rendered to a single-frame GIF and the GIF89a stream is assembled by hand below
 * ({@see encode}).
 *
 * Frames do NOT share a palette. An earlier version required that, which meant
 * turning antialiasing off — FreeType blends extra shades in as it renders, so the
 * table came out different for every frame — and 40px digits with no antialiasing
 * looked like a screenshot of a fax. Each frame now carries its own LOCAL colour
 * table, which is what the format has them for.
 *
 * ── THE FIRST FRAME IS A CORRECT STILL ────────────────────────────────────────
 * Outlook on Windows renders frame 1 of an animated GIF and never advances. So
 * frame 1 is not a splash or a zero state — it is the true remaining time at the
 * moment of the request, which means a frozen render is still accurate rather
 * than merely decorative. Everything after it is the tick.
 */
final class CountdownGif
{
    /** Frames drawn per request. At 1s each this is a 30-second tick before the loop. */
    public const FRAMES = 30;

    /** Hundredths of a second between frames — GIF's own unit. */
    private const DELAY = 100;

    private const W = 320;
    private const H = 104;

    /** Palette, allocated in this order for every frame. See the class note. */
    private const INK    = [0x14, 0x17, 0x1a];
    private const BG     = [0xff, 0xff, 0xff];
    private const MUTED  = [0x5b, 0x6b, 0x60];
    private const RULE   = [0xe4, 0xe6, 0xe1];
    private const ACCENT = [0x23, 0x7b, 0x22];

    /**
     * The URL to put in an email's <img src>.
     *
     * One builder, so a sender cannot forget the cycle. It matters: cycles are per
     * PROGRAMME, several can be in voting at once, and the endpoint's no-cycle fallback
     * is "whichever closes soonest" — which is the right answer for a Choral nominee
     * only by coincidence. Pass the nominee's own cycle.
     *
     * Deliberately NOT parameterised by recipient. See CountdownController for why an
     * image URL that identifies who opened it is a tracking pixel whatever it is called.
     */
    public static function urlFor(string $siteUrl, ?int $cycleId = null): string
    {
        $url = \rtrim($siteUrl, '/') . '/email/countdown.gif';

        return ($cycleId !== null && $cycleId > 0) ? $url . '?cycle=' . $cycleId : $url;
    }

    /**
     * @param int $secondsLeft seconds until the deadline; <= 0 renders the closed state
     * @return string a complete image/gif payload
     */
    public static function render(int $secondsLeft): string
    {
        $frames = [];
        for ($i = 0; $i < self::FRAMES; $i++) {
            // Each frame is one second further on. Never below zero: a countdown that
            // goes negative reads as a bug at exactly the moment it matters most.
            $frames[] = self::frame(max(0, $secondsLeft - $i));
        }

        return self::encode($frames, self::DELAY);
    }

    /** One frame, as a single-frame GIF payload. */
    private static function frame(int $left): string
    {
        // imagecreate(), not imagecreatetruecolor(): a palette image gives imagegif() a
        // small deterministic colour table, which is the whole basis of the assembly below.
        $im = \imagecreate(self::W, self::H);

        // BG first, because imagecreate() fills with whatever is allocated first. FreeType
        // blends further shades into the palette as it antialiases; each frame therefore
        // ends up with its own colour table, which encode() carries as a LOCAL table.
        $bg     = \imagecolorallocate($im, ...self::BG);      // index 0 = background
        $ink    = \imagecolorallocate($im, ...self::INK);
        $muted  = \imagecolorallocate($im, ...self::MUTED);
        $rule   = \imagecolorallocate($im, ...self::RULE);
        $accent = \imagecolorallocate($im, ...self::ACCENT);
        \imagefilledrectangle($im, 0, 0, self::W - 1, self::H - 1, $bg);

        if ($left <= 0) {
            self::closed($im, $ink, $muted);
            return self::capture($im);
        }

        $d = intdiv($left, 86400);
        $h = intdiv($left % 86400, 3600);
        $m = intdiv($left % 3600, 60);
        $s = $left % 60;

        // Days are dropped once there are none left, so the final day reads
        // HRS · MIN · SEC rather than a permanent "00" the reader has to skip.
        $cells = $d > 0
            ? [[$d, 'DAYS'], [$h, 'HRS'], [$m, 'MIN'], [$s, 'SEC']]
            : [[$h, 'HRS'], [$m, 'MIN'], [$s, 'SEC']];

        $n     = count($cells);
        $slot  = intdiv(self::W, $n);
        $mono  = self::font('AGMono-Bold.ttf');
        $sans  = self::font('DMSans-SemiBold.ttf');

        foreach ($cells as $i => [$value, $label]) {
            $cx = ($i * $slot) + intdiv($slot, 2);

            // Seconds carry the accent, because it is the digit that proves the image is
            // live rather than a picture of a number.
            $colour = ($label === 'SEC') ? $accent : $ink;
            self::centred($im, $mono, 40, $cx, 58, sprintf('%02d', $value), $colour, 'mono');
            self::centred($im, $sans, 11, $cx, 82, $label, $muted, 'sans');

            if ($i < $n - 1) {
                \imagefilledrectangle($im, ($i + 1) * $slot - 1, 30, ($i + 1) * $slot, 66, $rule);
            }
        }

        return self::capture($im);
    }

    private static function closed(\GdImage $im, int $ink, int $muted): void
    {
        self::centred($im, self::font('DMSans-Bold.ttf'), 26, intdiv(self::W, 2), 54, 'Voting has closed', $ink, 'sans');
        self::centred($im, self::font('DMSans-Regular.ttf'), 12, intdiv(self::W, 2), 78, 'Results are on their way', $muted, 'sans');
    }

    /**
     * Text centred on $cx with its baseline at $y.
     *
     * Falls back to GD's bitmap font when the bundled TrueType faces are missing —
     * the same failure mode FlierService documents, where a shared host has no
     * FreeType. An ugly countdown beats a broken image in somebody's inbox.
     */
    private static function centred(\GdImage $im, ?string $font, int $size, int $cx, int $y, string $text, int $colour, string $kind): void
    {
        if ($font !== null && \function_exists('imagettfbbox')) {
            $box = @\imagettfbbox($size, 0, $font, $text);
            if (\is_array($box)) {
                $w = (int) round(abs($box[4] - $box[0]));
                @\imagettftext($im, $size, 0, $cx - intdiv($w, 2), $y, $colour, $font, $text);
                return;
            }
        }
        // GD's built-in font 5 is 9x15; font 3 is 7x13. Close enough to keep the
        // columns aligned without FreeType.
        $builtin = $kind === 'mono' ? 5 : 3;
        $w       = \imagefontwidth($builtin) * \strlen($text);
        \imagestring($im, $builtin, $cx - intdiv($w, 2), $y - \imagefontheight($builtin), $text, $colour);
    }

    /** @return string|null absolute path to a bundled face, or null when absent */
    private static function font(string $file): ?string
    {
        $p = \dirname(__DIR__, 2) . '/resources/fonts/' . $file;
        return \is_file($p) ? $p : null;
    }

    private static function capture(\GdImage $im): string
    {
        \ob_start();
        \imagegif($im);
        $out = (string) \ob_get_clean();
        \imagedestroy($im);
        return $out;
    }

    // ── GIF89a assembly ──────────────────────────────────────────────────────

    /**
     * Splice single-frame GIFs into one looping animation.
     *
     * @param list<string> $frames each a complete single-frame GIF payload
     */
    private static function encode(array $frames, int $delay): string
    {
        if ($frames === []) return '';

        [$lsd, $gct, $_] = self::parse($frames[0]);

        $out = 'GIF89a' . $lsd . $gct
             // Netscape application extension: loop forever. Without it the animation
             // plays once and every later open shows a frozen final frame.
             . "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01\x00\x00\x00";

        foreach ($frames as $raw) {
            [, $table, $image] = self::parse($raw);
            $out .= "\x21\xF9\x04\x04" . \pack('v', $delay) . "\x00\x00"
                  . ($table === $gct ? $image : self::withLocalTable($image, $table));
        }

        return $out . "\x3B";
    }

    /**
     * Re-flag an image block to carry its own colour table.
     *
     * GD writes the frame's palette as a GLOBAL table and leaves the image descriptor's
     * local-table flag clear. Splicing that frame in after a different frame's global
     * table would render it through the wrong palette, so the table moves into the block:
     * set bit 0x80, write the size in the low three bits, and insert the bytes after the
     * ten-byte descriptor.
     *
     * @param string $image a complete image block: 0x2C + 9 bytes + LZW data
     * @param string $table the frame's colour table, 3 bytes per entry
     */
    private static function withLocalTable(string $image, string $table): string
    {
        $entries = intdiv(\strlen($table), 3);
        if ($entries < 2) {
            throw new \RuntimeException('CountdownGif: frame colour table is too small to be valid');
        }
        // Low three bits hold n where the table holds 2^(n+1) entries, so a table must be
        // a power of two. GD's always is; round up rather than emit a table the size bits
        // cannot describe.
        $n = 0;
        while ((1 << ($n + 1)) < $entries) $n++;
        $want = 3 * (1 << ($n + 1));
        if (\strlen($table) < $want) {
            $table = \str_pad($table, $want, "\x00");
        }

        $packed = \ord($image[9]) & 0xF8;        // clear any existing size bits
        $packed |= 0x80 | $n;                     // local table present, and its size

        return \substr($image, 0, 9) . \chr($packed) . $table . \substr($image, 10);
    }

    /**
     * Split a single-frame GIF into its screen descriptor, colour table and image block.
     *
     * @return array{0:string,1:string,2:string} [logical screen descriptor, global colour table, image block]
     */
    private static function parse(string $gif): array
    {
        if (\strlen($gif) < 14 || \substr($gif, 0, 3) !== 'GIF') {
            throw new \RuntimeException('CountdownGif: imagegif() did not return a GIF');
        }

        $lsd    = \substr($gif, 6, 7);
        $packed = \ord($lsd[4]);
        $pos    = 13;
        $gct    = '';
        if ($packed & 0x80) {
            $bytes = 3 * (1 << (($packed & 0x07) + 1));
            $gct   = \substr($gif, $pos, $bytes);
            $pos  += $bytes;
        }

        // Walk to the image descriptor, stepping over any extension GD emitted.
        while ($pos < \strlen($gif)) {
            $sep = \ord($gif[$pos]);
            if ($sep === 0x2C) {
                $start = $pos;
                $pos  += 10;                       // 0x2C + left,top,w,h (8) + packed (1)
                $ip    = \ord($gif[$start + 9]);
                if ($ip & 0x80) {                  // a local table, if GD ever writes one
                    $pos += 3 * (1 << (($ip & 0x07) + 1));
                }
                $pos++;                            // LZW minimum code size
                $pos = self::skipSubBlocks($gif, $pos);
                return [$lsd, $gct, \substr($gif, $start, $pos - $start)];
            }
            if ($sep === 0x21) {                   // extension: skip label + sub-blocks
                $pos = self::skipSubBlocks($gif, $pos + 2);
                continue;
            }
            break;                                 // trailer, or something we do not expect
        }

        throw new \RuntimeException('CountdownGif: no image block in the frame GD produced');
    }

    /** Advance past a run of length-prefixed sub-blocks, returning the position after the 0x00. */
    private static function skipSubBlocks(string $s, int $pos): int
    {
        while ($pos < \strlen($s)) {
            $len = \ord($s[$pos]);
            $pos++;
            if ($len === 0) break;
            $pos += $len;
        }
        return $pos;
    }
}
