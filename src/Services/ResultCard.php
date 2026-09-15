<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * THE GRAPHIC A RESULT IS SHARED AS.
 *
 * 1200×630 — the shape X, LinkedIn, Facebook and WhatsApp crop a link preview to. Not a
 * second aspect ratio for the same reason the nominee's card is not the nominee's flier:
 * a 4:5 artefact loses its bottom third in the one surface where the sharing happens.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS ON IT, AND WHAT DELIBERATELY IS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Four things: the award it is, the name, the index, and the SPLIT that produced the index.
 *
 * The split is the whole argument. Every other awards platform's share graphic says a name
 * and a trophy; this one says 812 of 1000, 371 of it from the community and 441 from the
 * panel, on an image somebody screenshots and argues with. A number nobody can decompose is
 * a number nobody can check, and a result nobody can check is a result this platform has no
 * business asking anyone to believe.
 *
 * Absent on purpose: the runner-up, the vote counts, the country, the field size, the
 * quorum. This is read at roughly a third of native size in a thread. A fifth and sixth
 * element are not small text, they are the reason the name stops being legible.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT CANNOT DRAW A RESULT THE PAGE WILL NOT SHOW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see PublicResults::heldReason()} is asked here too. A card is the most durable thing
 * this platform emits — it outlives the page in caches, in threads, in screenshots — so a
 * withheld award leaking through the image while its own page says "still being verified"
 * is the version of this bug that cannot be recalled.
 */
final class ResultCard
{
    use FlierRaster;

    public const W = 1200;
    public const H = 630;

    /** Cache window, matching the nominee card: long enough to be cheap, short enough to
     *  not be yesterday's. A result does not change, but a withdrawn one must go dark. */
    public const TTL = 600;

    /**
     * The card, or null when GD or a bundled face is unavailable so the caller can fall
     * back rather than serve a broken image.
     *
     * @param array<string,mixed> $r A {@see PublicResults::category()} result.
     */
    public function png(array $r): ?string
    {
        if (!function_exists('imagecreatetruecolor')) return null;
        if (!FlierService::fontsPresent()['ok'])      return null;
        if (($r['held'] ?? null) !== null)            return null;

        $w = $r['winner'] ?? null;
        if (!is_array($w)) return null;

        $W = self::W; $H = self::H;

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);

        $c = static function (string $hex) use ($im): int {
            [$rr, $gg, $bb] = FlierLayout::rgb($hex);
            return (int) imagecolorallocate($im, $rr, $gg, $bb);
        };
        $white   = $c(FlierLayout::C_WHITE);
        $gold    = $c(FlierLayout::C_GOLD);
        $goldInk = $c(FlierLayout::C_ON_GOLD);
        $mist    = $c(FlierLayout::C_MIST);
        $muted   = $c(FlierLayout::C_MUTED);

        $this->vGradient($im, 0, 0, $W, $H,
            FlierLayout::rgb(FlierLayout::C_BG_TOP), FlierLayout::rgb(FlierLayout::C_BG_BOTTOM));

        $x   = 74;
        $cw  = $W - $x - 74;
        $bold = FlierService::fontPath('bold');
        $disp = FlierService::fontPath('display');
        $semi = FlierService::fontPath('semibold');
        $mono = FlierService::fontPath('mono');

        // ── The pill, top right. Measured first: the kicker has to clear it. ─
        $badge = $this->badge($r);
        $bw    = $this->width($badge, 22, $bold, 4.2) + 46;
        $this->pill($im, $W - 74 - $bw, 62, $bw, 46, $gold);
        $this->text($im, $badge, 22, $bold, $goldInk, $W - 74 - $bw + 23, 93, 4.2);

        // ── Kicker: the award this is. Mono, because it is metadata. ─────────
        $kick = mb_strtoupper($this->trim((string) ($r['programme'] ?? 'Africa GATES'), 34));
        $this->text($im, $kick, 19, $mono, $gold, $x, 92, 5.4);

        $cat = $this->trim((string) ($r['category']->title ?? ''), 52);
        $ed  = trim((string) ($r['edition'] ?? ''));
        $this->text($im, $cat . ($ed !== '' ? '  ·  ' . $ed : ''), 26, $semi, $mist, $x, 136);

        // ── The name. Two lines at most; the size drops before it wraps to ───
        //     three, because a three-line name at this width is smaller than a
        //     two-line one at the next size down and reads worse at thumbnail.
        $name = (string) $w['name'];
        [$size, $lines] = $this->fitName($name, $cw, $disp);
        $y = 246;
        foreach ($lines as $line) {
            $this->text($im, $line, $size, $disp, $white, $x, $y);
            $y += (int) round($size * 1.06);
        }

        // ── The index, and the split that produced it. ───────────────────────
        //
        // Baselines, not boxes: imagettftext() draws from the baseline while every
        // measurement above grows downward, which is how a figure comes to sit on top of
        // the line above it.
        $base = $H - 96;
        $cpi  = (string) (int) $w['cpi'];
        $this->text($im, $cpi, 96, $disp, $gold, $x, $base);
        $wCpi = $this->width($cpi, 96, $disp);
        $this->text($im, ' / 1000', 30, $semi, $muted, $x + $wCpi + 8, $base);

        // Community FIRST, and in the leaf green, because the split is the argument: the
        // half a nominee cannot buy leads, and it is the half that was silently reading
        // zero on the cycle that prompted all of this. Drawn as two runs rather than one
        // string so the colour lands where the meaning is — the same reason the page
        // beneath it colours the community figure and not the judges'.
        //
        // The words carry it too. Colour alone would say nothing to a reader who cannot
        // separate these two hues, and this graphic has no alt text once it is a
        // screenshot in somebody's thread.
        $cTxt = mb_strtoupper((int) $w['community_points'] . ' community');
        $jTxt = mb_strtoupper('   ·   ' . (int) $w['judge_points'] . ' judges');
        $this->text($im, $cTxt, 20, $mono, $c(FlierLayout::C_LEAF), $x, $base + 42, 3.2);
        $this->text($im, $jTxt, 20, $mono, $mist,
            $x + $this->width($cTxt, 20, $mono, 3.2), $base + 42, 3.2);

        // The hairline the house style runs under a figure — it separates the index from
        // the name without a panel, which at preview size would read as a second card.
        //
        // ALPHA, not a flat tone. It was `C_PANEL` on a gradient that is within a few
        // values of it at this scanline, so the rule was drawn, correct, and completely
        // invisible: a line nobody can see is not a subtle line, it is dead ink in a
        // graphic that is read at a third of its size.
        imagesetthickness($im, 1);
        $rule = (int) imagecolorallocatealpha($im, 255, 255, 255, 100);
        imageline($im, $x, $base - 118, $W - 74, $base - 118, $rule);

        ob_start();
        imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png === '' ? null : $png;
    }

    /**
     * The word on the pill.
     *
     * A dead heat says so on the graphic. The alternative is a card that says WINNER for a
     * result the methodology could not separate, which is the single most screenshot-able
     * overstatement this platform could make.
     */
    private function badge(array $r): string
    {
        if (!empty($r['dead_heat'])) return 'DEAD HEAT';
        return 'WINNER';
    }

    /**
     * The largest display size at which this name fits in two lines, and those lines.
     *
     * Measured against the real face rather than counted in characters: at 68px Playfair,
     * "Ọlásùnkànmí Adébáyọ̀" and "Ada Obi" are nothing like the same width, and a character
     * budget picks the wrong break for one of them whichever number you choose.
     *
     * @return array{0:int, 1:list<string>}
     */
    private function fitName(string $name, float $maxW, string $font): array
    {
        foreach ([78, 68, 58, 48, 40] as $size) {
            $lines = $this->wrapMeasured($name, $maxW, $size, $font, 2);
            if ($lines === []) continue;
            $longest = 0.0;
            foreach ($lines as $l) $longest = max($longest, $this->width($l, $size, $font));
            // wrapMeasured() truncates to its line budget rather than refusing, so a name
            // that does not fit comes back TWO lines wide and looks like a success. The
            // width check is what makes the loop mean anything.
            if ($longest <= $maxW) return [$size, $lines];
        }

        return [40, $this->wrapMeasured($name, $maxW, 40, $font, 2)];
    }

    /** Ellipsised to a character budget — for metadata, where the face is uniform. */
    private function trim(string $s, int $max): string
    {
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1) . '…';
    }
}
