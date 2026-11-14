<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * WCAG relative luminance, and the contrast ratio between two colours. Once.
 *
 * ── WHY THIS CLASS EXISTS ────────────────────────────────────────────────────
 *
 * There were FOUR copies of this arithmetic: `Swatch::isLight()`,
 * `EventTierPalette::contrast()`, `OrgBrand::luminance()` and
 * `EventTicketDesign::contrastInk()`. Four implementations of the one calculation that
 * decides whether a person can read the text in front of them.
 *
 * ── AND WHAT THAT ACTUALLY COST, STATED HONESTLY ─────────────────────────────
 *
 * Nothing yet, and the check was worth running before claiming otherwise. Three of the
 * four linearise the channel at `0.03928` (WCAG 2.x's own wording) and `OrgBrand` at
 * `0.04045` (the sRGB standard's). Those thresholds are 10.01/255 and 10.31/255, and no
 * integer channel value falls between them — so the two agree on all 256 inputs and on
 * every contrast ratio this platform has ever computed. Verified across the palette; the
 * divergence is exactly zero, not merely small.
 *
 * So this is a maintenance fault rather than a shipped one, and it is fixed for the reason
 * the shipped ones are: the next person needing a contrast ratio was going to write a
 * FIFTH, and the fifth is where the wrong threshold, the naive channel average, or a
 * forgotten `+0.05` finally lands. `EventTicketDesign::contrastInk()`'s own docblock
 * records that somebody already reached for the naive average once and had white text
 * handed to a bright green header.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────────
 *
 * No APCA. It was removed from the WCAG 3 working drafts in 2023 and the current draft
 * says the contrast algorithm is undetermined; WCAG 2.2 is the operative standard
 * (W3C Recommendation, ISO/IEC 40500:2025) and its numbers are what an audit measures.
 */
final class Contrast
{
    /** Body text on its background. WCAG 2.2 SC 1.4.3, level AA. */
    public const TEXT = 4.5;

    /** Large text — 18.66px bold, or 24px. Same criterion, lower floor. */
    public const LARGE = 3.0;

    /**
     * Borders, rings, focus indicators, icons, the boundary of a control. SC 1.4.11, AA.
     *
     * The floor this codebase kept missing, because nothing was measuring it: a hairline
     * in an accent is a NON-TEXT contrast question and reads as a styling choice.
     */
    public const UI = 3.0;

    /** The ratio between two hex colours, 1.0 to 21.0. Order does not matter. */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la < 0 || $lb < 0) return 0.0;   // unparseable: never report a pass

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Relative luminance, 0.0 to 1.0, or -1.0 when the colour cannot be read. */
    public static function luminance(string $hex): float
    {
        $h = self::hex($hex);
        if ($h === '') return -1.0;

        $out = 0.0;
        foreach ([[0, 0.2126], [2, 0.7152], [4, 0.0722]] as [$at, $weight]) {
            $c = ((float) hexdec(substr($h, $at, 2))) / 255;
            $c = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            $out += $c * $weight;
        }

        return $out;
    }

    /** Does `$fg` clear `$floor` against `$bg`? */
    public static function passes(string $fg, string $bg, float $floor = self::TEXT): bool
    {
        return self::ratio($fg, $bg) >= $floor;
    }

    /**
     * Which of the house ink or white stays readable on `$bg`.
     *
     * The threshold is a LUMINANCE cut rather than a ratio comparison, and it is 0.36
     * rather than 0.5 — white-on-mid-tone reads better than dark-on-mid-tone at the
     * weights used on a ticket header. Carried over from `EventTicketDesign::contrastInk()`
     * unchanged, because a printed ticket's contrast decision must not move underneath a
     * refactor.
     */
    public static function inkOn(string $bg, string $dark = '#10292C', string $light = '#FFFFFF'): string
    {
        $l = self::luminance($bg);

        return $l > 0.36 ? $dark : $light;
    }

    /**
     * The nearest shade of `$seed` that reads at `$floor` on EVERY ground given — same hue,
     * same saturation, lightness moved away from the grounds until the worst of them passes.
     *
     * This is what lets a colour somebody CHOSE be accepted as it is: their exact colour stays
     * the identity (a fill, a swatch, a spine), and the words and edges drawn in it come from
     * this. Refusing a pale brand colour was the old answer, and it answers a taste question
     * with a correctness rule the platform could have satisfied itself.
     *
     * Which way is "away" is decided by the grounds, not the seed: on light grounds the shade
     * darkens, on dark grounds it lightens, so the same call serves a light page and a dark
     * one. Lightness steps of 1% to the end of the range, where black or white always clears
     * 4.5:1 against a light or dark ground respectively — so the answer is never the seed
     * failing; at worst it is the best shade reachable, and the caller can measure it.
     *
     * @param list<string> $grounds hex colours the result will be drawn on
     */
    public static function reach(string $seed, array $grounds, float $floor = self::TEXT): string
    {
        $seed = self::hex($seed);
        if ($seed === '' || $grounds === []) return $seed === '' ? '' : '#' . $seed;
        $worst = static function (string $hex) use ($grounds): float {
            $w = 21.0;
            foreach ($grounds as $g) $w = min($w, self::ratio($hex, $g));
            return $w;
        };
        if ($worst('#' . $seed) >= $floor) return '#' . $seed;

        // Away from the grounds: lighter grounds push the shade darker. Decided on the mean
        // luminance, so a light card and a light wash agree, and a mixed set still moves one way.
        $mean = array_sum(array_map(static fn (string $g): float => self::luminance($g), $grounds)) / count($grounds);
        $darker = $mean > 0.18;

        [$h, $s, $l] = \AfricaGates\Services\EventTierPalette::toHsl('#' . $seed);
        $best = '#' . $seed;
        $bestSeen = $worst($best);
        for ($i = 1; $i <= 100; $i++) {
            $cl = $darker ? $l - $i * 0.01 : $l + $i * 0.01;
            if ($cl < 0.0 || $cl > 1.0) break;
            $hex = strtolower(\AfricaGates\Services\EventTierPalette::fromHsl($h, $s, $cl));
            $got = $worst($hex);
            if ($got > $bestSeen) { $bestSeen = $got; $best = $hex; }
            if ($got >= $floor) return $hex;
        }
        // The end of the range: black or white, whichever the grounds need.
        $end = $darker ? '#000000' : '#ffffff';
        return $worst($end) > $bestSeen ? $end : $best;
    }

    /**
     * Whichever of `$dark` and `$light` reads better on `$bg`, by RATIO — for a label on a
     * fill somebody else chose. {@see inkOn()} is a luminance cut tuned for a printed ticket
     * and must not move; this one is the general answer, and on a mid-tone it is the side
     * that actually measures higher.
     */
    public static function labelOn(string $bg, string $dark = '#10292c', string $light = '#ffffff'): string
    {
        return self::ratio($dark, $bg) >= self::ratio($light, $bg) ? $dark : $light;
    }

    /** `#rgb` or `#rrggbb` expanded to six lower-case digits, or '' if it is neither. */
    public static function hex(string $raw): string
    {
        $h = strtolower(ltrim(trim($raw), '#'));

        if (preg_match('/^[0-9a-f]{3}$/', $h)) {
            return $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $h) ? $h : '';
    }
}
