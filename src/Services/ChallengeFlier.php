<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ChallengeEnum as E;

/**
 * The 1080×1080 share graphic — a port of `designs/Celebrate Nigeria Flier.dc.html`.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY NUMBER ON IT IS DERIVED. THE DESIGN'S ARE THE EXAMPLE, NOT THE VALUES.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The comp reads "₦6k", "11 people", "10 unique nominees", "Choral, Business or
 * Impact". Those are one challenge's figures. Typed into a renderer they would be
 * every challenge's figures, and the flier for a different campaign would advertise
 * the wrong prize — on an image that is downloaded, posted and then impossible to
 * correct. This codebase has four separate records of prose outliving the rule it
 * describes; a flier is that hazard with no edit button.
 *
 * So the layout is the comp's, exactly, and the strings come from the row and from
 * {@see ChallengeCopy}. {@see FLIER} is the only type scale, and nothing below reads a
 * hex that is not in {@see IN}.
 *
 * ── GD, BECAUSE THERE IS NO HEADLESS BROWSER ON THIS HOST ───────────────────
 *
 * The primitives are `FlierRaster`'s, shared with the ticket and the result card.
 * `roundRect()` was added there rather than written here: two renderers with their own
 * corner maths is how one graphic's cards come out a pixel rounder than another's, and
 * neither looks wrong on its own.
 *
 * ── THE COPY DIVERGENCE, RECORDED RATHER THAN RESOLVED SILENTLY ─────────────
 *
 * The comp's step wording is SHORTER than the page's: "Sign in and create your
 * account / Free, on Africa GATES" against `ChallengeCopy`'s "Sign in or create your
 * account / Free. Verify your phone number with a code." A 1080px square cannot hold
 * the page's sentences at 24px, and the design shortened them deliberately.
 *
 * The flier's own strings are therefore the flier's spec, with the numbers
 * interpolated. The alternative — wrapping the page's copy into three cards — would
 * reflow the whole lower third away from the comp. Listed as deviation 1.
 */
final class ChallengeFlier
{
    use FlierRaster;

    public const W = 1080;
    public const H = 1080;

    /**
     * The comp's palette, read off its markup. No hex appears below this block.
     *
     * These are the FLIER's colours, not the site's tokens: it is printed and posted
     * rather than rendered in the shell, the ground is warmer than the site's paper,
     * and the two greens are Nigeria's flag green (`#008751`, in the flag and the
     * highlighted words) and the design's own deeper green for solid fills.
     */
    private const IN = [
        'ground'   => '#f6f2ea',
        'ink'      => '#123f25',
        'green'    => '#1f6b2e',   // step discs, the URL pill
        'flag'     => '#008751',   // the flag bars and the highlighted words
        'gold'     => '#b8861f',   // WIN, and the prize unit
        'soft'     => '#4a5e51',   // a step's second line
        'muted'    => '#6b7a6f',   // the footer note
        'white'    => '#ffffff',
        'hairline' => '#d3c9b3',
    ];

    /**
     * CSS pixels → GD points.
     *
     * ── THE COMP IS IN PIXELS AND `imagettftext` TAKES POINTS ───────────────
     *
     * Every size in the design file is a CSS pixel. `imagettftext()`'s second argument
     * is a POINT size, and 1pt is 4/3 px — so the figure laid out at 168px rendered
     * 260px wide and 163px tall, ran past its 416px column, pushed "each" off the right
     * margin and shoved the promise into the artwork. Everything on the flier was a
     * third too large at once, which reads as "the design is wrong" rather than as a
     * unit mismatch.
     *
     * Converted here so the table below stays the comp's own numbers, which is what
     * makes it checkable against the design file.
     */
    private static function pt(float $cssPx): int
    {
        return (int) round($cssPx * 0.75);
    }

    /** The comp's type scale, in CSS PIXELS as the design states them. */
    private const FLIER = [
        'chip'      => 19,
        'win'       => 20,
        'prize'     => 168,
        'unit'      => 34,
        'promise'   => 30,
        'step_n'    => 21,
        'step_t'    => 24,
        'step_s'    => 17,
        'url'       => 28,
        'note'      => 17,
    ];

    /**
     * Render one challenge's flier as PNG bytes, or null when GD cannot.
     *
     * @param array<string,mixed> $c the challenge row
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

    private function draw(array $c, array $ctx): ?string
    {
        $copy = ChallengeCopy::for($c, $ctx);

        $im = imagecreatetruecolor(self::W, self::H);
        imagealphablending($im, true);
        imagesavealpha($im, false);

        $col = [];
        foreach (self::IN as $k => $hex) {
            [$r, $g, $b] = FlierLayout::rgb($hex);
            $col[$k] = (int) imagecolorallocate($im, $r, $g, $b);
        }

        imagefilledrectangle($im, 0, 0, self::W - 1, self::H - 1, $col['ground']);

        $bold = FlierService::fontPath('bold');
        $semi = FlierService::fontPath('semibold');

        $this->header($im, $col, $bold, $c);
        $this->art($im, $col, $bold, $c);
        $this->prize($im, $col, $bold, $copy);
        $this->steps($im, $col, $bold, $semi, $copy);
        $this->footer($im, $col, $bold, $semi);

        ob_start();
        imagepng($im, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes !== '' ? $bytes : null;
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The logo lockup, and the kicker chip opposite it.
     *
     * The chip is SIZED FROM ITS TEXT rather than fixed: the comp's "Independence Day
     * challenge" is one kicker, and a longer one on a fixed-width chip runs off the
     * right edge of an image nobody can scroll.
     */
    private function header($im, array $col, string $bold, array $c): void
    {
        // Logo disc: left 64, top 56, 72×72.
        imagefilledellipse($im, 64 + 36, 56 + 36, 72, 72, $col['white']);
        $this->mark($im, 64 + 36, 56 + 36, 52);

        // The 1px × 40px rule, 18px after the disc.
        imagefilledrectangle($im, 64 + 72 + 18, 56 + 16, 64 + 72 + 18, 56 + 56, $col['hairline']);

        $kicker = trim((string) ($c['kicker'] ?? ''));
        if ($kicker === '') return;

        $size = self::pt(self::FLIER['chip']);
        $tw   = $this->width($kicker, $size, $bold);

        // padding `0 20px 0 14px`, a 34px flag, a 12px gap.
        $cw = 14 + 34 + 12 + $tw + 20;
        $cx = self::W - 64 - $cw;

        $this->pill($im, $cx, 70, $cw, 48, $col['white']);

        // The flag: three equal bars in a 34×22 box, 4px corners.
        $fx = $cx + 14; $fy = 70 + 13;
        $this->roundRect($im, $fx, $fy, 34, 22, 4, $col['flag']);
        imagefilledrectangle($im, (int) ($fx + 34 / 3), (int) $fy,
            (int) ($fx + 34 * 2 / 3), (int) ($fy + 22), $col['white']);

        $this->text($im, $kicker, $size, $bold, $col['ink'],
            $fx + 34 + 12, 70 + 24 + $size * 0.36);
    }

    /**
     * The artwork, left 30 / top 120 / 560×560, `contain`.
     *
     * ── AND WHAT HAPPENS WHEN A CHALLENGE HAS NONE ──────────────────────────
     *
     * The comp is drawn around one campaign's illustration. Most challenges will not
     * have one, and leaving the left half empty makes the flier read as a broken image
     * rather than as a design — measured on the first render here, which came out with
     * half a square of nothing.
     *
     * The fallback is the one the challenge page already uses for the same absence: the
     * icon, large, on a soft disc. That keeps it inside the design system rather than
     * inventing a second answer, and the page and the flier agree about what a
     * challenge with no art looks like.
     */
    private function art($im, array $col, string $bold, array $c): void
    {
        $box = 560; $x = 30; $y = 120;

        $url = trim((string) ($c['art_url'] ?? ''));
        if ($url !== '') {
            $src = $this->loadPhoto($url);

            if ($src !== null && $src !== false) {
                $sw = imagesx($src); $sh = imagesy($src);
                // `contain`, not `cover`: the comp's illustration has its own margins
                // and cropping it would cut the lettering inside the artwork.
                $sc = min($box / max(1, $sw), $box / max(1, $sh));
                $dw = (int) round($sw * $sc); $dh = (int) round($sh * $sc);

                imagecopyresampled($im, $src, $x + (int) (($box - $dw) / 2), $y + (int) (($box - $dh) / 2),
                    0, 0, $dw, $dh, $sw, $sh);
                imagedestroy($src);

                return;
            }
        }

        // No art: a 360px disc with the challenge's own icon, centred in the same box.
        $cx = $x + $box / 2; $cy = $y + $box / 2;
        imagefilledellipse($im, (int) $cx, (int) $cy, 360, 360, $col['white']);

        $glyph = mb_substr(trim((string) ($c['title'] ?? 'A')), 0, 1);
        $size  = self::pt(190);
        $w     = $this->width($glyph, $size, $bold);

        $this->text($im, mb_strtoupper($glyph), $size, $bold, $col['green'],
            $cx - $w / 2, $cy + $size * 0.36);
    }

    /**
     * WIN · the figure · the promise.
     *
     * The prize is ABBREVIATED here and nowhere else — "₦6k" at 168px is the comp's
     * single loudest element, and "₦6,000" at that size does not fit the 416px column.
     * {@see shortPrize()}.
     */
    private function prize($im, array $col, string $bold, array $copy): void
    {
        $x = 600;

        // "WIN", 20px/700, letter-spacing .18em → 3.6px at 20px.
        $this->text($im, 'WIN', self::pt(self::FLIER['win']), $bold, $col['gold'], $x, 190 + 20, 3.6);

        $big  = $this->shortPrize($copy);
        $size = self::pt(self::FLIER['prize']);

        // ── THE BASELINE IS MEASURED, NOT A MULTIPLIER ──────────────────────
        // The comp stacks WIN (a 20px line from top 190) and then the figure block 2px
        // under it. A guessed "0.74 of the size" put the figure's cap height ABOVE
        // WIN's baseline and the two overlapped — visible only once the currency symbol
        // started drawing and widened the run.
        //
        // `imagettfbbox`'s upper-left Y is the ascent above the baseline for THIS
        // string in THIS face, so the baseline that puts the glyph top exactly where
        // the comp puts it is arithmetic rather than taste.
        $top = 190 + 20 + 2;
        $bb  = imagettfbbox($size, 0, $bold, $big);
        $by  = $top + ($bb === false ? (int) round($size * 1.0) : -$bb[7]);

        // `letter-spacing:-.05em` — negative, which `text()`'s tracking cannot express
        // (it only advances forward). Drawn per character with a negative advance here,
        // because the figure is the one place the comp's tightening is visible.
        $cx = $x;
        foreach (preg_split('//u', $big, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            // `faceFor()` per character, because this loop bypasses `text()` and would
            // otherwise reproduce the exact fault that one exists to fix: DM Sans has
            // no ₦, so the symbol drew NOTHING while still advancing its own width —
            // a flier reading "6k" with a gap where the currency should be.
            $face = $this->faceFor($bold, $ch);

            imagettftext($im, $size, 0, (int) round($cx), $by, $col['ink'], $face, $ch);
            $cx += $this->width($ch, $size, $face) - $size * 0.05;
        }

        $unit = $this->prizeUnit($copy);
        if ($unit !== '') {
            // Baseline-aligned with the figure, 14px after it.
            $this->text($im, $unit, self::pt(self::FLIER['unit']), $bold, $col['gold'], $cx + 14, $by);
        }

        $this->promise($im, $col, $bold, $copy, $x, $by + 16 + 24);
    }

    /**
     * The promise, wrapped to the comp's 416px column with the numbers in flag green.
     *
     * Drawn word by word so the two highlighted runs keep their colour across a line
     * break — the comp colours "11 people" and "10 unique nominees", and a highlight
     * that stops at the wrap is worse than none.
     */
    private function promise($im, array $col, string $bold, array $copy, float $x, float $top): void
    {
        $size = self::pt(self::FLIER['promise']);
        $lead = (int) round($size * 1.25);
        $maxW = 416;

        [$text, $hot] = $this->shortPromise($copy);

        $cx = $x; $cy = $top;
        foreach (explode(' ', $text) as $word) {
            $w = $this->width($word . ' ', $size, $bold);

            if ($cx + $w - $x > $maxW && $cx > $x) { $cx = $x; $cy += $lead; }

            $plain  = trim($word, '.,');
            $colour = in_array($plain, $hot, true) ? $col['flag'] : $col['ink'];

            $this->text($im, $word, $size, $bold, $colour, $cx, $cy);
            $cx += $w;
        }
    }

    /** The three cards, from `ChallengeCopy::steps()`. */
    private function steps($im, array $col, string $bold, string $semi, array $copy): void
    {
        $steps = array_slice($copy['steps'] ?? [], 0, 3);
        if ($steps === []) return;

        $gap  = 14;
        $full = self::W - 64 - 64;
        $cw   = ($full - $gap * 2) / 3;
        $ch   = 200;

        foreach ($steps as $i => $s) {
            $x = 64 + $i * ($cw + $gap);

            $this->roundRect($im, $x, 700, $cw, $ch, 24, $col['white']);

            // The numbered disc: 44×44, centred text.
            imagefilledellipse($im, (int) ($x + 22 + 22), 700 + 22 + 22, 44, 44, $col['green']);
            $n  = (string) $s['n'];
            $nw = $this->width($n, self::pt(self::FLIER['step_n']), $bold);
            $this->text($im, $n, self::pt(self::FLIER['step_n']), $bold, $col['white'],
                $x + 44 - $nw / 2, 700 + 44 + self::pt(self::FLIER['step_n']) * 0.37);

            $ty = 700 + 22 + 44 + 12 + self::pt(self::FLIER['step_t']);
            foreach ($this->wrapMeasured($this->shorten($s['t']), $cw - 44, self::pt(self::FLIER['step_t']), $bold, 3) as $line) {
                $this->text($im, $line, self::pt(self::FLIER['step_t']), $bold, $col['ink'], $x + 22, $ty);
                $ty += (int) round(self::pt(self::FLIER['step_t']) * 1.15);
            }

            $sy = $ty + 10;
            foreach ($this->wrapMeasured($this->shorten($s['s']), $cw - 44, self::pt(self::FLIER['step_s']), $semi, 2) as $line) {
                $this->text($im, $line, self::pt(self::FLIER['step_s']), $semi, $col['soft'], $x + 22, $sy);
                $sy += (int) round(self::pt(self::FLIER['step_s']) * 1.4);
            }
        }
    }

    private function footer($im, array $col, string $bold, string $semi): void
    {
        // The host, not the full URL: the comp's pill reads `afg.afrovanguard.org.ng`
        // with no scheme, which is what somebody types. Falls back to the production
        // host rather than to an empty pill — a flier with a blank address is a flier
        // that cannot do its one job.
        $base = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/');
        $host = (string) (parse_url($base, PHP_URL_HOST) ?: 'afg.afrovanguard.org.ng');

        $size = self::pt(self::FLIER['url']);
        $tw   = $this->width($host, $size, $bold);
        $pw   = $tw + 64;
        $py   = self::H - 56 - 68;

        $this->pill($im, 64, $py, $pw, 68, $col['green']);
        $this->text($im, $host, $size, $bold, $col['white'], 64 + 32, $py + 34 + $size * 0.36);

        // Right-aligned, two lines, as the comp's `<br>` sets them.
        $note = ['Only complete, verified', 'nominations count'];
        $ny   = $py + 24;
        foreach ($note as $line) {
            $lw = $this->width($line, self::pt(self::FLIER['note']), $semi);
            $this->text($im, $line, self::pt(self::FLIER['note']), $semi, $col['muted'],
                self::W - 64 - $lw, $ny);
            $ny += (int) round(self::pt(self::FLIER['note']) * 1.4);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * "₦6,000" → "₦6k". The abbreviation lives HERE and nowhere else.
     *
     * At 168px the column holds about five glyphs. The page keeps the exact figure —
     * that is the one somebody is owed — and the flier carries the shape of it, which
     * is what a square on a timeline is for.
     */
    private function shortPrize(array $copy): string
    {
        $big = (string) ($copy['prize_big'] ?? '');

        return (string) preg_replace_callback('/([0-9][0-9,]*)/', static function (array $m): string {
            $n = (int) str_replace(',', '', $m[1]);

            if ($n >= 1_000_000) return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'm';
            if ($n >= 1_000)     return rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.') . 'k';

            return (string) $n;
        }, $big, 1);
    }

    /** `cash_each` reads "each"; a pooled prize or points keeps its own word. */
    private function prizeUnit(array $copy): string
    {
        return trim((string) ($copy['prize_unit'] ?? ''));
    }

    /**
     * The flier's shorter promise, and the two runs the comp colours.
     *
     * The comp: "The first 11 people to nominate 10 unique nominees each win." The
     * page's sentence is longer and names verification; at 30px in a 416px column it
     * runs to six lines and collides with the steps. Deviation 1.
     *
     * @return array{0:string,1:list<string>} the sentence, and the words to highlight
     */
    private function shortPromise(array $copy): array
    {
        $cap    = (int) ($copy['cap'] ?? 0);
        $target = 0;

        // The target is on the facts strip as "10 different nominees", which is the one
        // place `for()` publishes it alongside its unit.
        foreach ($copy['facts'] ?? [] as $f) {
            if (($f['k'] ?? '') === 'To qualify') $target = (int) $f['v'];
        }

        $u    = (string) ($copy['u'] ?? '');
        $who  = $cap > 0 ? 'The first ' . $cap . ' people' : 'Everybody who finishes';
        $hot  = [];

        if ($cap > 0)    $hot[] = (string) $cap;
        if ($target > 0) $hot[] = (string) $target;

        $verb = str_contains($u, 'ticket') ? 'bring' : 'nominate';

        return [
            $who . ' to ' . $verb . ' ' . $target . ' ' . $u . ' each win.',
            array_merge($hot, ['people', $u !== '' ? explode(' ', $u)[0] : '']),
        ];
    }

    /** A step line trimmed to what a 1080 square can hold without reflowing the row. */
    private function shorten(string $s): string
    {
        $s = trim($s);

        // The comp's own second lines are four or five words. A page sentence is longer,
        // so it is cut at its first full stop rather than ellipsised mid-clause.
        $at = mb_strpos($s, '. ');

        return $at !== false ? mb_substr($s, 0, $at) : $s;
    }

    /**
     * The Africa GATES mark, centred in the white disc.
     *
     * Drawn from the bundled PNG when it is there; a solid green disc with the letter
     * otherwise. A flier that fails because a logo file moved is a flier nobody can
     * publish, and the fallback is recognisably ours rather than an empty hole.
     */
    private function mark($im, int $cx, int $cy, int $size): void
    {
        $path = dirname(__DIR__, 2) . '/public/assets/img/gates-logo.png';

        if (is_file($path)) {
            $src = @imagecreatefrompng($path);
            if ($src !== false) {
                $sw = imagesx($src); $sh = imagesy($src);
                $sc = min($size / max(1, $sw), $size / max(1, $sh));
                $dw = (int) round($sw * $sc); $dh = (int) round($sh * $sc);

                imagecopyresampled($im, $src, $cx - (int) ($dw / 2), $cy - (int) ($dh / 2),
                    0, 0, $dw, $dh, $sw, $sh);
                imagedestroy($src);

                return;
            }
        }

        [$r, $g, $b] = FlierLayout::rgb(self::IN['green']);
        $green = (int) imagecolorallocate($im, $r, $g, $b);
        imagefilledellipse($im, $cx, $cy, $size, $size, $green);

        $white = (int) imagecolorallocate($im, 255, 255, 255);
        $font  = FlierService::fontPath('bold');
        $w     = $this->width('G', 30, $font);
        $this->text($im, 'G', 30, $font, $white, $cx - $w / 2, $cy + 11);
    }
}
