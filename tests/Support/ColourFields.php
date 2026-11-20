<?php
declare(strict_types=1);

namespace Tests\Support;

use AfricaGates\Support\Accent;

/**
 * WHICH COLOUR FAMILIES A PIECE OF MARKUP OR CSS PAINTS AS A FIELD.
 *
 * One answer for the three guards that count colour — `AccentTest`'s ceilings,
 * `ColourBudgetTest`'s tiers and its money rule — because two sweeps disagreeing about
 * what counts as a field is worse than either being loose.
 *
 * ── WHY IT READS THE PROPERTY AND NOT ONLY THE NAME ────────────────────────
 *
 * The previous palette spelled the question into its names: `--ag-honour-wash` was a
 * field and `--ag-honour-ink` was a word, whatever property it sat in. The handoff's
 * names do not: `--ag-green` is the primary button's fill AND the focus ring AND a link,
 * `--ag-gold` is a winner's field and also a star's stroke. Counting the bare name
 * charged every focus ring as a field; ignoring it made the guards blind to the
 * handoff's own fills, which is GAPS C2 — a page painted with `--ag-green` passing
 * without being checked.
 *
 * So a WASH (a token that is only ever an area) counts wherever it appears, and a
 * family's hue counts only where it is painted as an area: a `background*` or `fill`
 * declaration, or an SVG `fill="…"` attribute.
 */
final class ColourFields
{
    /** @return list<string> families painted as a field in this text */
    public static function families(string $text): array
    {
        $out = [];

        foreach (Accent::fields() as $family => $tokens) {
            foreach ($tokens as $t) {
                if (self::paints($text, $t)) {
                    $out[] = $family;
                    break;
                }
            }
        }

        return $out;
    }

    /** Is this one token painted as an area here? */
    public static function paints(string $text, string $token): bool
    {
        $q = preg_quote($token, '/');

        if (self::isWash($token)) {
            return (bool) preg_match('/--ag-' . $q . '(?![a-z0-9-])/', $text);
        }

        return (bool) preg_match(
            '/(?:background(?:-color|-image)?|(?<![a-z-])fill)\s*:[^;{}"]*var\(\s*--ag-' . $q . '(?![a-z0-9-])'
          . '|\bfill\s*=\s*"[^"]*var\(\s*--ag-' . $q . '(?![a-z0-9-])/i', $text);
    }

    private static function isWash(string $token): bool
    {
        return str_contains($token, '-wash');
    }
}
