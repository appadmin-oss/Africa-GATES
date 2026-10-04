<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * What a THING's photo slot shows when there is no real image (GAPS §8 Q8, answered
 * 4 Oct 2026): "a generated cover — the event's own accent through EventFlierTheme (already
 * one accent → a whole contrast-checked palette), the title set in the cover, drawn in CSS
 * so it is never a request that can fail."
 *
 * The partial (`partials/photo.twig`) decides real image versus fallback; this only hands it
 * the four colours, as custom properties, so the template types no hex and the floors are
 * EventFlierTheme's measured ones (its sampling test covers the accent space) rather than a
 * second palette invented for a new surface (CLAUDE.md, the tier list section).
 *
 * `deep` is the style: a dark cinematic ground carrying the title in white, which is also
 * what the desktop hero's scrim produces over a photograph — so a page reads the same
 * whether or not the organiser uploaded one.
 */
final class PhotoCover
{
    /**
     * @return array{top:string, bottom:string, ink:string, rule:string}
     */
    public static function tone(?string $accent): array
    {
        $t = EventFlierTheme::fromAccent(EventTicketDesign::colour((string) $accent), 'deep', 'plain');

        return [
            'top'    => (string) $t['ground_top'],
            'bottom' => (string) $t['ground_bot'],
            'ink'    => (string) $t['ink'],
            'rule'   => (string) $t['rule'],
        ];
    }

    /** The same, as the `style` attribute value the partial writes. Values are validated hexes. */
    public static function style(?string $accent): string
    {
        $t = self::tone($accent);

        return '--ph-top:' . $t['top'] . ';--ph-bottom:' . $t['bottom']
             . ';--ph-ink:' . $t['ink'] . ';--ph-rule:' . $t['rule'];
    }

    /** Up to two initials, for a person's monogram. Letters only, upper-cased per word. */
    public static function initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w === '' || !preg_match('/\p{L}/u', $w, $m)) continue;
            $out .= mb_strtoupper($m[0]);
            if (mb_strlen($out) >= 2) break;
        }
        return $out;
    }
}
