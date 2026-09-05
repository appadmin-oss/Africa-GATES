<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * A colour swatch: one hex, or two for a two-tone item.
 *
 * ── WHY THIS IS ITS OWN CLASS AND WHY IT REFUSES RATHER THAN CLEANS UP ───────
 *
 * A swatch is drawn by putting its value into a `style` attribute, which makes it one of the
 * two places in this codebase where an editor's typing is EXECUTED rather than displayed
 * (the other is the ticket's accent — {@see \AfricaGates\Services\EventTicketDesign}). The
 * same rule therefore applies:
 *
 *   • Only `#rgb` and `#rrggbb` are accepted, and shorthand is expanded so what reaches CSS
 *     is one predictable shape.
 *   • Anything else returns NOTHING, and the caller falls back to the text label — which is
 *     always present anyway. A half-sanitised string would be a value nobody intended.
 *   • Validated on write AND on read, because a row can arrive from a restored backup or a
 *     direct SQL edit and never pass through the form at all.
 *
 * Two colours are stored as `#aabbcc/#ddeeff`. A slash rather than a comma because a comma is
 * how CSS separates gradient stops and a stored value that looks like the syntax it will be
 * interpolated into is a value that eventually gets interpolated wrong.
 *
 * ── AND WHY THE TEXT LABEL IS NEVER REPLACED ─────────────────────────────────
 *
 * Nothing here returns something to show INSTEAD of the colour's name. Roughly one man in
 * twelve cannot reliably tell two of these apart, a screen reader has nothing to announce for
 * a coloured square, and an order email reading "you bought the ■" is not a receipt. A swatch
 * is an accelerator on top of the name, never a substitute for it.
 */
final class Swatch
{
    /** Longest stored form: `#aabbcc/#ddeeff`. */
    public const MAX_STORED = 20;

    /**
     * The colours this swatch draws, as validated hex strings.
     *
     * @return list<string> zero, one or two entries. Empty means "no swatch" — draw the label.
     */
    public static function colours(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $out = [];
        // At most two. A third value is not a richer swatch, it is somebody pasting a palette
        // into a field, and rendering it would make one product's buttons a different shape
        // from every other's.
        foreach (array_slice(explode('/', $raw), 0, 2) as $part) {
            $hex = self::hex($part);
            if ($hex !== '') {
                $out[] = $hex;
            }
        }
        return $out;
    }

    /** True when there is at least one usable colour. */
    public static function has(?string $raw): bool
    {
        return self::colours($raw) !== [];
    }

    /**
     * The CSS `background` for a swatch button, or '' when there is no swatch.
     *
     * Built from already-validated hex, so what lands in the attribute is `#` plus six hex
     * digits (and, for two colours, a fixed gradient string) by construction — there is no
     * path by which caller input reaches CSS unchecked.
     *
     * A HARD-EDGED gradient for two colours, not a blend: a navy-and-cream shirt is navy and
     * cream, and a gradient between them shows a colour the garment does not have.
     */
    public static function css(?string $raw): string
    {
        $c = self::colours($raw);
        return match (count($c)) {
            0 => '',
            1 => $c[0],
            default => 'linear-gradient(135deg,' . $c[0] . ' 0 50%,' . $c[1] . ' 50% 100%)',
        };
    }

    /**
     * Normalise for storage, or NULL.
     *
     * NULL and not '' — the column means "no swatch", and two spellings of the same absence
     * is how a query that filters on one of them misses half the rows.
     */
    public static function store(?string $raw): ?string
    {
        $c = self::colours($raw);
        return $c === [] ? null : implode('/', $c);
    }

    /**
     * Whether this swatch is light enough that a white tick on it would disappear.
     *
     * The chosen option is marked with a tick INSIDE the swatch, because a ring around it is
     * indistinguishable from a hover state on a small target. A white tick on cream is
     * invisible, so the tick colour is computed — same relative-luminance reasoning as the
     * ticket's ink, for the same reason: nobody should have to notice this to get it right.
     */
    public static function isLight(?string $raw): bool
    {
        $c = self::colours($raw);
        if ($c === []) {
            return true;                      // no swatch: the button is the page's own light surface
        }
        // The FIRST colour, because the tick is drawn over the leading half of the gradient.
        $h   = ltrim($c[0], '#');
        $lin = static function (float $v): float {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((float) hexdec(substr($h, 0, 2)))
           + 0.7152 * $lin((float) hexdec(substr($h, 2, 2)))
           + 0.0722 * $lin((float) hexdec(substr($h, 4, 2)));
        return $l > 0.45;
    }

    /** One hex colour, expanded and upper-cased, or '' if it is not one. */
    private static function hex(string $raw): string
    {
        $v = trim($raw);
        if ($v === '') {
            return '';
        }
        if ($v[0] !== '#') {
            $v = '#' . $v;                    // a picker posts `#aabbcc`; a person types `aabbcc`
        }
        if (preg_match('/^#([0-9a-f]{3})$/i', $v, $m) === 1) {
            $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/i', $v) === 1 ? strtoupper($v) : '';
    }
}
