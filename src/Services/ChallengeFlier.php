<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Brand;
use AfricaGates\Support\ChallengeEnum as E;

/**
 * The 1080×1080 share flier — rebuilt on the October campaign artwork.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE LAYOUT IS THE ARTWORK'S. EVERY WORD AND NUMBER ON IT IS THE ROW'S.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The artwork reads "Know 10 people who make Alimosho proud?", "First 11 Winners",
 * "₦6,000 each", "ends 15 Oct". Those are one challenge's figures; typed into a renderer
 * they would be every challenge's, on an image that is downloaded, posted and then
 * impossible to correct. So the geometry below is measured off the artwork — positions,
 * sizes and line pitches in its own pixels — and the strings come from
 * {@see ChallengeCopy::flier()}: the campaign's own lines from its row (with the numbers
 * as placeholders), everything else generated exactly as the page generates it.
 *
 * ── WHAT IS DRAWN AND WHAT IS PLACED ────────────────────────────────────────
 *
 * Drawn: the ground, the logo disc, every word, the prize card and its tab, the footer
 * band and its button. Placed, as files: the two logos, the challenge's portrait (a
 * cut-out on a clear background, from `portrait_url`, else its `art_url`), and two
 * stickers the artwork carries — the waving flag when the challenge flies one (`flag`),
 * the coupon when the prize is cash. A sticker is decoration; neither carries a fact.
 *
 * ── COLOUR ──────────────────────────────────────────────────────────────────
 *
 * Text and the button are the house ramp ({@see Accent}): ink, ink-2, the action green.
 * The ground and the card's lower tint are DERIVED from the challenge's theme — 12% and
 * 5% of its fill over white — so a blue or gold challenge gets its own flier rather than
 * a green one; for the green theme that lands within three levels of the artwork's
 * `#e6f2e8`/`#f4f9f3`. Two values are the artwork's own and nobody else's: the headline's
 * pure black, and Nigeria's flag green for the prize on a challenge that flies the flag.
 *
 * GD, because there is no headless browser on this host. The primitives are
 * {@see FlierRaster}'s, shared with the ticket and the event flier.
 */
final class ChallengeFlier
{
    use FlierRaster;

    public const W = 1080;
    public const H = 1080;

    /** The artwork's own two colours. Everything else comes from the ramp or the theme. */
    private const OWN = [
        'display' => '#000000',   // the headline
        'flag'    => '#008751',   // Nigeria's flag green — the prize, on a flag challenge
    ];

    /**
     * The four theme presets, as `challenge.css` declares them from the tokens:
     * fill (the identity), edge (the kicker), solid (a fill carrying white text).
     * {@see \Tests\Unit\ChallengeFlierTest} holds these to tokens.css, so the flier and
     * the page cannot drift apart.
     */
    public const THEMES = [
        E::THEME_GREEN => ['fill' => '#237b22', 'edge' => '#1a6118', 'solid' => '#237b22'],
        E::THEME_BLUE  => ['fill' => '#1f6fa3', 'edge' => '#1f6fa3', 'solid' => '#1f6fa3'],
        E::THEME_GOLD  => ['fill' => '#f3b416', 'edge' => '#7a5600', 'solid' => '#7a5600'],
        E::THEME_ROSE  => ['fill' => '#e0245e', 'edge' => '#b0224f', 'solid' => '#b0224f'],
    ];

    /** The ground and the card tint: this share of the theme's fill, over white. */
    public const GROUND_MIX = 0.12;
    public const TINT_MIX   = 0.05;

    /** The type, in the artwork's pixels, per face. */
    private const TYPE = [
        'kicker'   => 15.8,  // DM Sans Bold
        'headline' => 55,    // Playfair Display Bold
        'stand'    => 20.5,  // DM Sans Regular
        'tab'      => 13.5,  // DM Sans SemiBold
        'prize'    => 48.1,  // Playfair Display Bold
        'unit'     => 20,    // DM Sans Regular
        'card'     => 20,    // DM Sans SemiBold
        'tagline'  => 32.5,  // DM Sans SemiBold
        'url'      => 18.6,  // DM Sans Regular
        'cta'      => 23,    // DM Sans Bold, tracked tight
    ];

    /** The cut-out stickers the artwork carries, as files under public/. */
    public const STICKER_FLAG   = '/assets/img/flier/sticker-flag.png';
    public const STICKER_COUPON = '/assets/img/flier/sticker-coupon.png';

    /**
     * CSS pixels → GD points: imagettftext() takes points, and 1pt is 4/3 px.
     *
     * NOT rounded. A whole point is 1.33px, so rounding moved every size here by up to
     * two-thirds of a pixel — the tagline came out 9px narrower than the artwork's, the
     * prize 3px wider — and FreeType takes a fractional size as readily as a whole one.
     */
    private static function pt(float $cssPx): float
    {
        return $cssPx * 0.75;
    }

    /**
     * Render one challenge's flier as PNG bytes, or null when GD cannot.
     *
     * @param array<string,mixed> $c   the challenge row
     * @param array<string,mixed> $ctx ChallengeCopy context, plus `host_logo` (a public path)
     */
    public static function png(array $c, array $ctx = []): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            // No GD, or no FreeType. Reported rather than a blank square: a flier that
            // renders as an empty image is worse than a missing download.
            return null;
        }
        return (new self())->draw($c, $ctx);
    }

    /** @return array{ground:string, tint:string, edge:string, solid:string} */
    public static function palette(string $theme): array
    {
        $t = self::THEMES[$theme] ?? self::THEMES[E::THEME_GREEN];
        $mix = static function (string $hex, float $share): string {
            [$r, $g, $b] = FlierLayout::rgb($hex);
            $m = static fn (int $c): int => (int) round(255 - $share * (255 - $c));
            return sprintf('#%02x%02x%02x', $m($r), $m($g), $m($b));
        };
        return ['ground' => $mix($t['fill'], self::GROUND_MIX), 'tint' => $mix($t['fill'], self::TINT_MIX),
                'edge' => $t['edge'], 'solid' => $t['solid']];
    }

    private function draw(array $c, array $ctx): ?string
    {
        $copy  = ChallengeCopy::flier($c, $ctx);
        $theme = self::palette((string) ($c['theme'] ?? E::THEME_GREEN));
        $flag  = !empty($c['flag']);

        $im = imagecreatetruecolor(self::W, self::H);
        imagealphablending($im, true);
        imagesavealpha($im, false);

        $hex = [
            'ground' => $theme['ground'], 'tint' => $theme['tint'], 'edge' => $theme['edge'],
            'white'  => Accent::hex('surface'),
            'ink'    => Accent::hex('ink'), 'ink2' => Accent::hex('ink-2'),
            'action' => Accent::hex('green'),
            'display'=> self::OWN['display'],
            'prize'  => $flag ? self::OWN['flag'] : $theme['solid'],
        ];
        $col = [];
        foreach ($hex as $k => $h) {
            [$r, $g, $b] = FlierLayout::rgb($h);
            $col[$k] = (int) imagecolorallocate($im, $r, $g, $b);
        }

        imagefilledrectangle($im, 0, 0, self::W - 1, self::H - 1, $col['ground']);

        $f = [
            'display' => FlierService::fontPath('display'),
            'bold'    => FlierService::fontPath('bold'),
            'semi'    => FlierService::fontPath('semibold'),
            'regular' => FlierService::fontPath('regular'),
        ];

        $this->portrait($im, $col, $f, $c);
        $this->header($im, $col, $f, $copy, $ctx, $flag);
        $bottom = $this->headline($im, $col, $f, $copy);
        $this->standfirst($im, $col, $f, $copy, $bottom);
        if (in_array((string) ($c['prize_type'] ?? ''), [E::PRIZE_CASH_EACH, E::PRIZE_CASH_POOL], true)) {
            $this->place($im, self::STICKER_COUPON, 42, 598);
        }
        $this->card($im, $col, $f, $copy);
        $this->footer($im, $col, $f, $copy);

        ob_start();
        imagepng($im, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes !== '' ? $bytes : null;
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** The Africa GATES disc, the host's logo beside it, the flag sticker opposite. */
    private function header($im, array $col, array $f, array $copy, array $ctx, bool $flag): void
    {
        // The disc: 77px, centred at (131, 134), on a soft shadow.
        $this->softShadow($im, 131 - 38, 134 - 38 + 6, 77, 77, 38);
        imagefilledellipse($im, 131, 134, 77, 77, $col['white']);
        $this->placeContain($im, '/' . Brand::LOGO_ON_TINT, 131 - 26, 134 - 30, 52, 60);

        // The host's mark, 104px wide, top-aligned with the disc.
        $host = trim((string) ($ctx['host_logo'] ?? ''));
        if ($host !== '') $this->placeContain($im, $host, 198, 91, 104, 76, 'left');

        if ($flag) $this->place($im, self::STICKER_FLAG, 772, 146);

        if ($copy['kicker'] !== '') {
            $this->text($im, $copy['kicker'], self::pt(self::TYPE['kicker']), $f['bold'], $col['edge'], 196, 276);
        }
    }

    /**
     * The headline: Playfair, three lines at most, 62px apart, in a 470px column.
     *
     * @return int the last line's baseline
     */
    private function headline($im, array $col, array $f, array $copy): int
    {
        $size  = self::pt(self::TYPE['headline']);
        $lines = $this->wrapMeasured($copy['headline'], 470, $size, $f['display'], 3);
        $y = 345;
        foreach ($lines as $i => $line) {
            $this->text($im, $line, $size, $f['display'], $col['display'], 197, $y);
            if ($i < count($lines) - 1) $y += 62;
        }
        return $y;
    }

    /** The line under it: 42px below the headline's last baseline, 23px apart. */
    private function standfirst($im, array $col, array $f, array $copy, int $after): void
    {
        $size = self::pt(self::TYPE['stand']);
        $y = $after + 42;
        foreach ($this->wrapMeasured($copy['standfirst'], 445, $size, $f['regular'], 3) as $line) {
            $this->text($im, $line, $size, $f['regular'], $col['ink2'], 197, $y);
            $y += 23;
        }
    }

    /**
     * The prize card: a 200×199 white card at (197, 700), a tinted lower third, and the
     * dark tab over its top edge naming who wins.
     */
    private function card($im, array $col, array $f, array $copy): void
    {
        $x = 197; $y = 700; $w = 200; $h = 199; $r = 28; $cx = $x + $w / 2;

        $this->softShadow($im, $x, $y + 6, $w, $h, $r);
        $this->roundRect($im, $x, $y, $w, $h, $r, $col['white']);
        // The tint: rounded at the bottom, square where it meets the white at 818.
        $this->roundRect($im, $x, 790, $w, $y + $h - 790, $r, $col['tint']);
        imagefilledrectangle($im, (int) $x, 790, (int) ($x + $w - 1), 817, $col['white']);

        // The tab, sized from its text, centred on the card's top edge.
        $ts = self::pt(self::TYPE['tab']);
        $tw = $this->width($copy['tab'], $ts, $f['semi']);
        $pw = (int) round($tw + 24);
        $this->pill($im, $cx - $pw / 2, 693, $pw, 28, $col['ink']);
        $this->text($im, $copy['tab'], $ts, $f['semi'], $col['white'], $cx - $tw / 2, 712);

        // The figure, shrunk only if a long amount would leave the card.
        $ps = self::pt(self::TYPE['prize']);
        while ($ps > 18 && $this->width($copy['prize_big'], $ps, $f['display']) > $w - 24) $ps -= 0.5;
        $pw2 = $this->width($copy['prize_big'], $ps, $f['display']);
        $this->text($im, $copy['prize_big'], $ps, $f['display'], $col['prize'], $cx - $pw2 / 2, 777);

        if ($copy['prize_unit'] !== '') {
            $us = self::pt(self::TYPE['unit']);
            $uw = $this->width($copy['prize_unit'], $us, $f['regular']);
            $this->text($im, $copy['prize_unit'], $us, $f['regular'], $col['ink2'], $cx - $uw / 2, 798);
        }

        $cs = self::pt(self::TYPE['card']);
        $y2 = 848;
        foreach ($this->wrapMeasured($copy['card_line'], $w - 30, $cs, $f['semi'], 2) as $line) {
            $lw = $this->width($line, $cs, $f['semi']);
            $this->text($im, $line, $cs, $f['semi'], $col['ink'], $cx - $lw / 2, $y2);
            $y2 += 21;
        }
    }

    /**
     * The portrait, bottom-right, standing on the footer band.
     *
     * A challenge with no picture — or one whose file is gone — gets the answer the
     * challenge page already gives for the same absence: its initial, large, on a white
     * disc. Half the flier as bare ground reads as a broken image, not as a design, and a
     * second invented answer here would make the page and the flier disagree about what a
     * challenge with no art looks like.
     */
    private function portrait($im, array $col, array $f, array $c): void
    {
        $url = trim((string) ($c['portrait_url'] ?? ''));
        if ($url === '') $url = trim((string) ($c['art_url'] ?? ''));
        // A 484×526 box whose bottom edge is the band's top and whose right edge is the
        // flier's: the artwork's subject stands on the band.
        if ($url !== '' && $this->placeContain($im, $url, 596, 422, 484, 526, 'bottom-right')) return;

        $cx = 838; $cy = 685;
        $this->softShadow($im, $cx - 180, $cy - 180 + 8, 360, 360, 180);
        imagefilledellipse($im, $cx, $cy, 360, 360, $col['white']);
        $glyph = mb_strtoupper(mb_substr(trim((string) ($c['title'] ?? '')) ?: 'A', 0, 1));
        $size  = self::pt(190);
        $w     = $this->width($glyph, $size, $f['display']);
        $this->text($im, $glyph, $size, $f['display'], $col['edge'], $cx - $w / 2, $cy + $size * 0.48);
    }

    /** The white band: the tagline, the address and its date, and the button. */
    private function footer($im, array $col, array $f, array $copy): void
    {
        imagefilledrectangle($im, 0, 949, self::W - 1, self::H - 1, $col['white']);

        // The button's label is set tight, -0.06em, which `text()` cannot express (its
        // tracking only advances forward) — so it is drawn and measured per character here.
        $cta = $copy['cta'];
        $bs  = self::pt(self::TYPE['cta']);
        $tr  = -0.06 * self::TYPE['cta'];
        $bw  = $this->tightWidth($cta, $bs, $f['bold'], $tr);
        $pw  = (int) round($bw + 88);
        $px  = 999 - $pw;
        $this->pill($im, $px, 986, $pw, 57, $col['action']);
        $this->tight($im, $cta, $bs, $f['bold'], $col['white'], $px + ($pw - $bw) / 2, 1021, $tr);

        // The tagline gives way to the button: shrunk rather than run under it.
        $maxW = $px - 81 - 28;
        $ts = self::pt(self::TYPE['tagline']);
        while ($ts > 14 && $this->width($copy['tagline'], $ts, $f['semi']) > $maxW) $ts -= 0.5;
        // x is the pen, not the ink: DM Sans carries a 2px side bearing at this size, and
        // the artwork's first stroke is at 81.
        $this->text($im, $copy['tagline'], $ts, $f['semi'], $col['ink2'], 79, 1005);

        $us = self::pt(self::TYPE['url']);
        $this->text($im, $copy['url_line'], $us, $f['regular'], $col['ink'], 81, 1034);
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Text with NEGATIVE letter-spacing. Each character is placed at the measured width of
     * everything before it plus the tracking so far — measuring the PREFIX, not summing
     * per-glyph boxes, because a glyph's box drops its side bearings and a space has none,
     * so a per-glyph sum closes every word gap ("JOINNOW").
     */
    private function tight($im, string $s, float $size, string $font, int $colour, float $x, float $y, float $track): void
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $prefix = '';
        foreach ($chars as $i => $ch) {
            $at = $x + ($prefix === '' ? 0.0 : $this->advance($prefix, $size, $font)) + $i * $track;
            if ($ch !== ' ') imagettftext($im, $size, 0, (int) round($at), (int) round($y), $colour, $font, $ch);
            $prefix .= $ch;
        }
    }

    private function tightWidth(string $s, float $size, string $font, float $track): float
    {
        $n = mb_strlen($s);
        return $n > 0 ? $this->width($s, $size, $font) + ($n - 1) * $track : 0.0;
    }

    /** Where the pen ends after `$s`: the inked width of `$s` plus a following glyph, minus that glyph. */
    private function advance(string $s, float $size, string $font): float
    {
        return $this->width($s . 'H', $size, $font) - $this->width('H', $size, $font);
    }

    /** A file under public/ at its own size. */
    private function place($im, string $path, int $x, int $y): void
    {
        $src = $this->loadPhoto($path);
        if ($src === null || $src === false) return;
        imagecopy($im, $src, $x, $y, 0, 0, imagesx($src), imagesy($src));
        imagedestroy($src);
    }

    /**
     * A file scaled to fit a box (`contain`), anchored as the artwork anchors it.
     *
     * @param string $anchor centre | left | bottom-right
     */
    private function placeContain($im, string $path, int $x, int $y, int $bw, int $bh, string $anchor = 'centre'): bool
    {
        $src = $this->loadPhoto($path);
        if ($src === null || $src === false) return false;
        $sw = imagesx($src); $sh = imagesy($src);
        $sc = min($bw / max(1, $sw), $bh / max(1, $sh));
        $dw = (int) round($sw * $sc); $dh = (int) round($sh * $sc);
        [$dx, $dy] = match ($anchor) {
            'left'         => [$x, $y + intdiv($bh - $dh, 2)],
            'bottom-right' => [$x + $bw - $dw, $y + $bh - $dh],
            default        => [$x + intdiv($bw - $dw, 2), $y + intdiv($bh - $dh, 2)],
        };
        imagealphablending($im, true);
        imagecopyresampled($im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);
        return true;
    }

    /**
     * A soft shadow: the shape drawn OPAQUE on its own small canvas, blurred, and the result
     * used as a coverage mask for the ink. Translucent shapes drawn straight onto the flier
     * cannot do this — `roundRect()` is two rectangles and four ellipses, and where they
     * overlap a translucent colour is laid down twice, which drew hard grey bars along every
     * edge of the card instead of a glow.
     *
     * The blur is three box passes each way over FLOATS (a close gaussian). Not
     * `IMG_FILTER_GAUSSIAN_BLUR` repeated: that rounds to 8 bits on every pass, the error
     * creeps outward, and the shadow ends in a visible square at the canvas edge.
     *
     * `$opacity` is the density beneath the shape; `$blur` its spread in px. The defaults
     * are measured off the artwork: 8% darker than the ground at the card's edge, gone by
     * about 18px below it.
     */
    private function softShadow($im, float $x, float $y, float $w, float $h, float $r,
                                float $opacity = 0.13, int $blur = 14): void
    {
        $pad = $blur * 3;
        $cw = (int) ceil($w) + 2 * $pad; $ch = (int) ceil($h) + 2 * $pad;
        $m = imagecreatetruecolor($cw, $ch);
        imagefill($m, 0, 0, (int) imagecolorallocate($m, 0, 0, 0));
        $this->roundRect($m, $pad, $pad, $w, $h, $r, (int) imagecolorallocate($m, 255, 255, 255));
        $a = [];
        for ($py = 0; $py < $ch; $py++) {
            for ($px = 0; $px < $cw; $px++) $a[$py * $cw + $px] = (imagecolorat($m, $px, $py) & 0xFF) / 255;
        }
        imagedestroy($m);
        $rad = max(1, intdiv($blur, 2));
        for ($pass = 0; $pass < 3; $pass++) {
            $a = self::boxBlur($a, $cw, $ch, $rad, true);
            $a = self::boxBlur($a, $cw, $ch, $rad, false);
        }

        [$sr, $sg, $sb] = FlierLayout::rgb(Accent::hex('ink'));
        $ox = (int) round($x) - $pad; $oy = (int) round($y) - $pad;
        $iw = imagesx($im); $ih = imagesy($im);
        imagealphablending($im, true);
        for ($py = 0; $py < $ch; $py++) {
            $ty = $oy + $py;
            if ($ty < 0 || $ty >= $ih) continue;
            for ($px = 0; $px < $cw; $px++) {
                $tx = $ox + $px;
                if ($tx < 0 || $tx >= $iw) continue;
                $alpha = (int) round(127 * (1 - $a[$py * $cw + $px] * $opacity));
                if ($alpha >= 127) continue;
                imagesetpixel($im, $tx, $ty, (int) imagecolorallocatealpha($im, $sr, $sg, $sb, $alpha));
            }
        }
    }

    /**
     * One box-blur pass along rows or columns, with a running sum.
     *
     * @param array<int,float> $a
     * @return array<int,float>
     */
    private static function boxBlur(array $a, int $w, int $h, int $r, bool $rows): array
    {
        $out = $a;
        $len = $rows ? $w : $h; $lines = $rows ? $h : $w;
        $n = 2 * $r + 1;
        for ($l = 0; $l < $lines; $l++) {
            $at = $rows ? static fn (int $i): int => $l * $w + $i : static fn (int $i): int => $i * $w + $l;
            $sum = 0.0;
            for ($i = -$r; $i <= $r; $i++) $sum += ($i >= 0 && $i < $len) ? $a[$at($i)] : 0.0;
            for ($i = 0; $i < $len; $i++) {
                $out[$at($i)] = $sum / $n;
                $in = $i + $r + 1; $outI = $i - $r;
                if ($in < $len) $sum += $a[$at($in)];
                if ($outI >= 0) $sum -= $a[$at($outI)];
            }
        }
        return $out;
    }
}
