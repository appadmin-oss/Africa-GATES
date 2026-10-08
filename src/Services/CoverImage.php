<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\CoverKind;

/**
 * The default cover OFF the site — the image a link unfurls into (DEFAULT-GRAPHICS §7).
 *
 * WhatsApp, a link preview and Google's event results carry no page around the image, so
 * the image carries the facts itself: the type, the day and month, the title, the place and
 * the host, and the Africa GATES lockup — `DefaultCover.dc.html`'s `variant=full`, at the
 * four sizes the spec names (og:image 1200×630 and Google's Event set 1200×675, 1200×900,
 * 1200×1200). Drawn by GD from the bundled faces, because there is no headless browser on
 * this host and there cannot be.
 *
 * Only the FALLBACK is generated: an event with an uploaded photo still shares its photo.
 * The label, tone and pattern come from Support\CoverKind — the resolver the on-page cover
 * uses — so a link and the card it came from are the same kind of thing. Every colour is
 * Accent's (`coverHex`), never typed here.
 *
 * Geometry is the DC's: every length is `clamp(min, n × cqmin, max)` against the shorter
 * side, which in a fixed-size image is simply arithmetic — the TypeScaleTest rule about
 * stepped type is about screens and the reader's text setting, neither of which reaches a PNG.
 *
 * Two things GD cannot do, said rather than faked: it does not shape Arabic or reorder
 * right-to-left text, so an RTL title is not drawn mirrored (PHASE-DEFAULT-GRAPHICS DG-6); and
 * the challenge `games` tile is not drawn here — no shareable image is a challenge yet.
 */
final class CoverImage
{
    use FlierRaster;

    /** The sizes §7 names, as "WxH" — the route accepts nothing else. */
    public const RATIOS = ['1200x630', '1200x675', '1200x900', '1200x1200'];

    /** GD alpha for the pattern's two strengths: the tone's line at 0x38 and 0x14 (§4). */
    private const ALPHA_A = 99;   // 127 × (1 − 0x38/255)
    private const ALPHA_B = 117;  // 127 × (1 − 0x14/255)

    /**
     * PNG bytes, or null when GD or the faces are missing (the caller then serves no image
     * rather than a broken one).
     *
     * @param array{subject?:string, kind?:?string, award_linked?:bool, title?:string, date?:string,
     *              time?:string, place?:string, host?:string} $c
     */
    public function png(array $c, string $ratio): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !in_array($ratio, self::RATIOS, true)) return null;
        if (!FlierService::fontsPresent()['ok']) return null;

        [$W, $H] = array_map('intval', explode('x', $ratio));
        $r     = CoverKind::resolve($c['subject'] ?? 'event', $c['kind'] ?? null, (bool) ($c['award_linked'] ?? false));
        $tone  = $r['tone'];
        $min   = min($W, $H);
        $q     = $min / 100;                                   // one cqmin
        $cl    = static fn (float $lo, float $n, float $hi): float => max($lo, min($hi, $n * $q));
        $ar    = $W / $H;
        $tall  = $ar < 1.1;

        $im  = imagecreatetruecolor($W, $H);
        $pat = imagecreatetruecolor($W, $H);
        imagealphablending($im, true); imagealphablending($pat, true);
        $bg = $this->hex($im, Accent::coverHex($tone, 'bg'));
        imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, $bg);
        imagefilledrectangle($pat, 0, 0, $W - 1, $H - 1, $this->hex($pat, Accent::coverHex($tone, 'bg')));
        $this->pattern($pat, $r['pattern'], $W, $H, $q, Accent::coverHex($tone, 'line'));
        $this->fade($im, $pat, $W, $H, $tall);
        imagedestroy($pat);

        $ink   = $this->hex($im, Accent::coverHex($tone, 'ink'));
        $line  = $this->hex($im, Accent::coverHex($tone, 'line'));
        $text  = $this->hex($im, Accent::hex('ink'));
        $soft  = $this->hex($im, Accent::hex('ink-2'));
        $white = $this->hex($im, Accent::hex('surface'));
        $green = $this->hex($im, Accent::hex('green'));
        $disp  = FlierService::fontPath('display');
        $bold  = FlierService::fontPath('bold');
        $reg   = FlierService::fontPath('regular');
        $pt    = static fn (float $px): float => $px * 0.75;   // CSS px → GD points

        $pad = $cl(12, 7, 72);

        // ── the type pill, top start ─────────────────────────────────────────────
        $pillH = $cl(22, 8, 52); $pillPx = $cl(8, 2.8, 22); $pillFs = $cl(11, 3.4, 22); $dot = $cl(5, 1.5, 11);
        $label = (string) $r['label'];
        $lw = $this->width($label, $pt($pillFs), $bold);
        $pw = $pillPx * 2 + $dot + 7 + $lw;
        $this->pill($im, $pad, $pad, $pw, $pillH, $white);
        imagefilledellipse($im, (int) round($pad + $pillPx + $dot / 2), (int) round($pad + $pillH / 2), (int) round($dot), (int) round($dot), $line);
        $this->text($im, $label, $pt($pillFs), $bold, $ink, $pad + $pillPx + $dot + 7, $pad + $pillH / 2 + $pillFs * 0.36);

        // ── the bottom block, laid out from the bottom up ────────────────────────
        $gap   = $cl(6, 2.4, 20);
        $innerW = $W - 2 * $pad;
        $maxW  = $tall ? $innerW : $innerW * 0.8;
        $metaFs = $cl(10.5, 3.8, 28);
        $y = $H - $pad;                                          // the block's bottom edge

        // meta row: place · host, and the lockup at the end
        $markS = $cl(16, 6, 46);
        $afFs  = $cl(10, 3.4, 26); $gtFs = $cl(6.5, 1.9, 14);
        $lockW = $markS + $cl(4, 1.4, 12) + max($this->width('Africa', $pt($afFs), $disp), $this->width('GATES', $pt($gtFs), $bold, $gtFs * 0.26));
        $this->lockup($im, $W - $pad - $lockW, $y - $markS, $markS, $afFs, $gtFs, $text, $green, $disp, $bold, $cl(4, 1.4, 12));
        $meta = implode(' · ', array_values(array_filter([trim((string) ($c['place'] ?? '')), trim((string) ($c['host'] ?? ''))])));
        if ($meta !== '') {
            $room = $innerW - $lockW - 12;
            $m = $this->wrapMeasured($meta, $room, $pt($metaFs), $reg, 1)[0] ?? '';
            $this->text($im, $m, $pt($metaFs), $reg, $soft, $pad, $y - ($markS - $metaFs) / 2 - $metaFs * 0.22);
        }
        $y -= $markS + $cl(2, 1, 10) + $gap;

        // the title: Playfair 700, size by length and shape (the DC's `tk`), 3 lines (4 tall)
        $title = trim((string) ($c['title'] ?? ''));
        $date  = trim((string) ($c['date'] ?? ''));
        $hasDate = $date !== '' && strtotime($date) !== false;
        $shape = $ar <= .85 ? 'tall' : 'normal';
        $tFs = $cl(15, CoverKind::titleScale($title, $shape, $hasDate), 96);
        if ($title !== '') {
            $lines = $this->wrapMeasured($title, $maxW, $pt($tFs), $disp, $tall ? 4 : 3);
            $lh = $tFs * 1.08;
            $base = $y - $tFs * 0.22;                            // the last line's baseline
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $this->text($im, $lines[$i], $pt($tFs), $disp, $text, $pad, $base);
                $base -= $lh;
            }
            $y -= $lh * count($lines) + $gap;
        }

        // the date row: day numeral │ Month Year / Weekday · time — never a faked date
        if ($hasDate) {
            $ts  = (int) strtotime($date . ' 12:00:00');
            $day = date('j', $ts);
            $dFs = $cl(26, $tall ? 18 : 16, 160);
            $my  = date('F Y', $ts);
            $wt  = date('l', $ts) . (trim((string) ($c['time'] ?? '')) !== '' ? ' · ' . trim((string) $c['time']) : '');
            $myFs = $cl(12, 4.6, 36); $wtFs = $cl(10.5, 3.5, 26);
            // Playfair's figures are OLD-STYLE: a 4, 7 or 9 descends below the baseline, so the
            // row sits a quarter of its size above the title or its digits run into the caps.
            $dayBase = $y - $dFs * 0.26;
            $this->text($im, $day, $pt($dFs), $disp, $ink, $pad, $dayBase);
            $dx = $pad + $this->width($day, $pt($dFs), $disp) + $cl(8, 3, 24);
            $rule = max(1, (int) round($cl(1, .45, 3)));
            $colH = $myFs * 1.1 + 2 + $wtFs * 1.25;
            $colTop = $dayBase - $dFs * 0.45 - $colH / 2;
            imagefilledrectangle($im, (int) round($dx), (int) round($colTop), (int) round($dx) + $rule - 1, (int) round($colTop + $colH), $line);
            $tx = $dx + $rule + $cl(8, 3, 24);
            $this->text($im, $my, $pt($myFs), $bold, $text, $tx, $colTop + $myFs);
            $this->text($im, $wt, $pt($wtFs), $reg, $soft, $tx, $colTop + $myFs * 1.1 + 2 + $wtFs);
        }

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** `og:image:alt`, §7: "{title}, {weekday} {day} {month}, {place}". */
    public static function alt(string $title, string $date, string $place): string
    {
        $ts = $date !== '' ? strtotime($date . ' 12:00:00') : false;
        $when = $ts !== false ? date('l j F', $ts) : '';

        return implode(', ', array_values(array_filter([trim($title), $when, trim($place)])));
    }

    // ── drawing ─────────────────────────────────────────────────────────────────

    private function hex($im, string $hex, int $alpha = 0): int
    {
        $h = ltrim($hex, '#');

        return imagecolorallocatealpha($im, hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), $alpha);
    }

    /** The pattern, at the DC's geometry, in the tone's line colour at its two strengths. */
    private function pattern($im, string $p, int $W, int $H, float $q, string $line): void
    {
        $a = $this->hex($im, $line, self::ALPHA_A);
        $b = $this->hex($im, $line, self::ALPHA_B);
        $solid = $this->hex($im, $line);
        $disc = function (float $cx, float $cy, float $r) use ($im, $b): void {
            imagefilledellipse($im, (int) round($cx), (int) round($cy), (int) round($r * 2), (int) round($r * 2), $b);
        };
        $rings = function (float $cx, float $cy, float $start, float $period, float $width) use ($im, $a, $W, $H): void {
            imagesetthickness($im, max(1, (int) round($width)));
            $reach = sqrt(($W + abs($cx)) ** 2 + ($H + abs($cy)) ** 2);
            for ($r = $start; $r < $reach; $r += $period) {
                imageellipse($im, (int) round($cx), (int) round($cy), (int) round($r * 2), (int) round($r * 2), $a);
            }
            imagesetthickness($im, 1);
        };
        switch ($p) {
            case 'arches':
                $disc(.8 * $W, 1.22 * $H, 46 * $q);
                $rings(.8 * $W, 1.22 * $H, 9.4 * $q, 9.8 * $q, .8 * $q);
                break;
            case 'signal':
                $rings(.84 * $W, .46 * $H, 7.5 * $q, 8 * $q, 1 * $q);
                imagefilledellipse($im, (int) round(.84 * $W), (int) round(.46 * $H), (int) round(5.2 * $q), (int) round(5.2 * $q), $solid);
                break;
            case 'rings':
                $rings(.96 * $W, 1.04 * $H, 8 * $q, 8.4 * $q, .8 * $q);
                $disc(.7 * $W, .1 * $H, 26 * $q);
                break;
            case 'dots':
                $disc($W, 0, 56 * $q);
                $s = 5.6 * $q; $d = max(2, (int) round(1.3 * $q));
                for ($y = $s / 2; $y < $H; $y += $s) for ($x = $s / 2; $x < $W; $x += $s) imagefilledellipse($im, (int) round($x), (int) round($y), $d, $d, $a);
                break;
            case 'grid':
                $disc(.86 * $W, .2 * $H, 36 * $q);
                $s = 10.5 * $q; $t = max(1, (int) round(.28 * $q));
                for ($x = 0; $x < $W; $x += $s) imagefilledrectangle($im, (int) round($x), 0, (int) round($x) + $t - 1, $H - 1, $a);
                for ($y = 0; $y < $H; $y += $s) imagefilledrectangle($im, 0, (int) round($y), $W - 1, (int) round($y) + $t - 1, $a);
                break;
            case 'weave':
                $s = 10.8 * $q; $band = 5.4 * $q; $t = max(1, (int) round(.8 * $q));
                for ($x = 0; $x < $W; $x += $s) imagefilledrectangle($im, (int) round($x), 0, (int) round($x + $band) - 1, $H - 1, $b);
                for ($y = 0; $y < $H; $y += $s) {
                    imagefilledrectangle($im, 0, (int) round($y), $W - 1, (int) round($y + $band) - 1, $b);
                    imagefilledrectangle($im, 0, (int) round($y), $W - 1, (int) round($y) + $t - 1, $a);
                }
                break;
            default: // stripes (and games, whose tile is not drawn here)
                $disc(.92 * $W, .08 * $H, 42 * $q);
                $s = 6 * $q; $t = max(1, (int) round(1.5 * $q * M_SQRT2));
                imagesetthickness($im, $t);
                for ($k = -$H; $k < $W + $H; $k += $s * M_SQRT2) imageline($im, (int) round($k), 0, (int) round($k + $H), $H, $a);
                imagesetthickness($im, 1);
        }
    }

    /**
     * The pattern fades out under the text (§6): for a landscape image a 100° ramp, clear to
     * 36% across and full by 74%; for a square or taller one a vertical ramp, full at the top
     * and clear from two thirds down. Merged in strips — a per-pixel loop over 1.4M pixels
     * would be seconds a share request does not have.
     */
    private function fade($dst, $pat, int $W, int $H, bool $vertical): void
    {
        $step = 6;
        if ($vertical) {
            for ($y = 0; $y < $H; $y += $step) {
                $t = $y / $H;
                $m = $t <= .26 ? 1.0 : ($t >= .66 ? 0.0 : 1 - ($t - .26) / .40);
                if ($m > 0) imagecopymerge($dst, $pat, 0, $y, 0, $y, $W, min($step, $H - $y), (int) round($m * 100));
            }
            return;
        }
        for ($x = 0; $x < $W; $x += $step) {
            $t = $x / $W;
            $m = $t <= .36 ? 0.0 : ($t >= .74 ? 1.0 : ($t - .36) / .38);
            if ($m > 0) imagecopymerge($dst, $pat, $x, 0, $x, 0, min($step, $W - $x), $H, (int) round($m * 100));
        }
    }

    /** The Africa GATES lockup, bottom end: the mark on a white tile, "Africa" over "GATES". */
    private function lockup($im, float $x, float $y, float $s, float $afFs, float $gtFs, int $ink, int $green,
                            string $disp, string $bold, float $gap): void
    {
        $white = $this->hex($im, Accent::hex('surface'));
        $this->roundRect($im, $x, $y, $s, $s, max(4, $s * .23), $white);
        $mark = dirname(__DIR__, 2) . '/public/assets/img/logo-africa-gates-alpha.png';
        if (is_readable($mark) && ($src = @imagecreatefrompng($mark))) {
            $in = (int) round($s - 4);
            $sw = imagesx($src); $sh = imagesy($src); $k = min($in / $sw, $in / $sh);
            imagecopyresampled($im, $src, (int) round($x + ($s - $sw * $k) / 2), (int) round($y + ($s - $sh * $k) / 2), 0, 0,
                (int) round($sw * $k), (int) round($sh * $k), $sw, $sh);
            imagedestroy($src);
        }
        $tx = $x + $s + $gap;
        $this->text($im, 'Africa', $afFs * .75, $disp, $ink, $tx, $y + $s / 2 + $afFs * .1);
        $this->text($im, 'GATES', $gtFs * .75, $bold, $green, $tx, $y + $s / 2 + $afFs * .1 + $gtFs * 1.2, $gtFs * .26);
    }
}
