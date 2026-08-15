<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * A colour for each ticket tier — drawn only from the event's own accent.
 *
 * ═════════════════════════════════════════════════════════════════════════════════
 * WHY A SLOT IS STORED AND NOT A HEX
 * ═════════════════════════════════════════════════════════════════════════════════
 *
 * The obvious build is a colour picker on every tier row. It is the wrong one, for a reason
 * that only shows up months later: the organiser changes the event's accent colour, and the
 * six tier colours they chose against the old one stay exactly where they were. The event is
 * now teal and the tiers are still burgundy, and nothing on the platform knows that is wrong.
 *
 * So a tier stores a SLOT — `deep`, `warm`, `bold` — and the actual hex is computed from the
 * event's accent every time it is read. Change the accent and the whole ladder moves with it,
 * permanently. "Only colours that match the event" stops being a rule somebody has to follow
 * and becomes a property of the storage.
 *
 * It also removes a validation surface. A hex column is a string that ends up inside a
 * `style` attribute; a slot column is checked against {@see SLOTS} and can only ever produce
 * output this file computed.
 *
 * ═════════════════════════════════════════════════════════════════════════════════
 * WHY THE SWATCHES ARE SEPARATED RATHER THAN JUST DERIVED
 * ═════════════════════════════════════════════════════════════════════════════════
 *
 * Six shades generated from one accent by fixed arithmetic collapse on the accents that
 * matter most. The platform's own default is `#10292C` — a very dark teal — and "the accent,
 * a bit darker" is invisibly different from it. An organiser offered two swatches they cannot
 * tell apart has been given a broken control, and the tier ladder they build with it will be
 * unreadable on the door.
 *
 * So the ladder is generated and then {@see separate()} walks it, pushing any swatch that
 * lands too near one already accepted. Deterministic, and true for every accent including
 * black, white and grey — which is what {@see tests} asserts by sampling the space rather
 * than by checking the one colour somebody had in mind.
 *
 * ═════════════════════════════════════════════════════════════════════════════════
 * COLOUR IS NEVER THE ONLY SIGNAL
 * ═════════════════════════════════════════════════════════════════════════════════
 *
 * WCAG 1.4.1. Every place a swatch is drawn, the tier's NAME is drawn beside it — the colour
 * is there to make a familiar name findable at a glance in a list, not to carry meaning on
 * its own. A door lit by a phone torch at 9pm is the environment this has to survive, and in
 * that environment the word is the thing that works.
 *
 * Each swatch therefore ships three values, not one: `fill`, the `ink` that stays legible on
 * it, and an `edge` dark enough to clear 3:1 against white (WCAG 1.4.11) so a pale swatch is
 * still a visible object rather than a gap in the page.
 */
final class EventTierPalette
{
    /**
     * The slots, in the order they are offered, and what each is called to an organiser.
     *
     * Six, not twelve. This is a decoration on a row an organiser fills in while doing
     * something else, and a colour grid is a place people stop and deliberate. Six named
     * options are pickable at a glance; a spectrum is a decision.
     */
    public const SLOTS = [
        'accent' => 'Event colour',
        'deep'   => 'Deep',
        'soft'   => 'Soft',
        'warm'   => 'Warm',
        'cool'   => 'Cool',
        'bold'   => 'Bold',
    ];

    /** Below this the two swatches read as the same colour. Redmean distance, 0–765. */
    private const MIN_SEPARATION = 64.0;

    /** WCAG 1.4.11: a non-text indicator needs 3:1 against what is behind it. */
    private const EDGE_TARGET = 3.0;

    /**
     * A stored value, or NULL for "no colour on this tier".
     *
     * NULL is a real answer and the default one. A tier ladder where every row is coloured
     * because the field had to be filled in is noisier than one where the organiser marked
     * the two that matter.
     */
    public static function slot(mixed $raw): ?string
    {
        $v = strtolower(trim((string) $raw));
        return array_key_exists($v, self::SLOTS) ? $v : null;
    }

    /**
     * The whole palette for one event.
     *
     * @param object|array|null $event a `gates_site_events` row
     * @return array<string, array{key:string, label:string, fill:string, ink:string, edge:string}>
     */
    public static function forEvent(object|array|null $event): array
    {
        $e = $event === null ? [] : (is_array($event) ? $event : (array) $event);
        return self::fromAccent(EventTicketDesign::colour((string) ($e['ticket_accent'] ?? '')));
    }

    /**
     * One tier's resolved colour, or NULL when it has none.
     *
     * Takes the event as well as the tier because the tier does not know its own colour —
     * that is the whole design. A tier row read without its event cannot be coloured, which
     * is correct: there is no such thing as a tier colour independent of the event.
     *
     * @return array{key:string, label:string, fill:string, ink:string, edge:string}|null
     */
    public static function forTier(object|array|null $tier, object|array|null $event): ?array
    {
        $t = $tier === null ? [] : (is_array($tier) ? $tier : (array) $tier);
        $slot = self::slot($t['colour'] ?? null);
        if ($slot === null) return null;

        return self::forEvent($event)[$slot] ?? null;
    }

    /**
     * Build the ladder from one accent.
     *
     * @return array<string, array{key:string, label:string, fill:string, ink:string, edge:string}>
     */
    public static function fromAccent(string $accent): array
    {
        $hex = EventTicketDesign::colour($accent);
        [$h, $s, $l] = self::toHsl($hex);

        // ── THE WORKING BAND ───────────────────────────────────────────────────
        //
        // The hue-rotated slots do NOT inherit the accent's lightness. On the platform's own
        // near-black default they would all be near-black, and on a pastel accent they would
        // all be washed out — in both cases six swatches nobody can tell apart. They are
        // built at a lightness that reads as a colour, and the accent keeps its own.
        $lw = max(0.30, min(0.52, $l));
        $sw = max(0.32, min(0.78, $s));

        // ── A MONOCHROME EVENT GETS A MONOCHROME LADDER ────────────────────────
        //
        // Black, white and grey have no hue to build a family out of. Flooring the saturation
        // the way the coloured path does would hand an organiser who chose charcoal a ladder
        // of pink, olive and teal — six colours that match nothing on their event, which is
        // the exact failure this whole feature exists to prevent. So the achromatic accent
        // stays achromatic and the ladder separates on lightness alone; `separate()` below
        // still guarantees no two of them read as the same grey.
        if ($s < 0.08) {
            $draft = [
                'accent' => [0.0, 0.0, $l],
                'deep'   => [0.0, 0.0, 0.16],
                'soft'   => [0.0, 0.0, 0.88],
                'warm'   => [0.0, 0.0, 0.38],
                'cool'   => [0.0, 0.0, 0.60],
                'bold'   => [0.0, 0.0, 0.02],
            ];
            return self::finish(self::separate($draft));
        }

        $draft = [
            'accent' => [$h, $s, $l],
            'deep'   => [$h, $sw, $lw * 0.50],
            'soft'   => [$h, $sw * 0.42, 0.82],
            'warm'   => [self::hue($h + 34), $sw, $lw],
            'cool'   => [self::hue($h - 34), $sw, $lw],
            // Not the exact complement. 180° from the accent is the one relationship that
            // stops reading as "the same family" and starts reading as a clash, which is the
            // single thing this feature exists to prevent.
            'bold'   => [self::hue($h + 152), $sw * 0.88, $lw],
        ];

        return self::finish(self::separate($draft));
    }

    /**
     * Turn a separated HSL ladder into the three values a template needs per swatch.
     *
     * @param array<string, array{float,float,float}> $ladder
     * @return array<string, array{key:string, label:string, fill:string, ink:string, edge:string}>
     */
    private static function finish(array $ladder): array
    {
        $out = [];
        foreach ($ladder as $key => $hsl) {
            $fill = self::fromHsl($hsl[0], $hsl[1], $hsl[2]);
            $out[$key] = [
                'key'   => $key,
                'label' => self::SLOTS[$key],
                'fill'  => $fill,
                // Computed, never chosen. An organiser who lands on a pale swatch and is
                // handed white text has been given a chip nobody can read, and the screen
                // where they find that out is the door.
                'ink'   => self::ink($fill),
                'edge'  => self::edge($fill),
            ];
        }
        return $out;
    }

    /**
     * Push apart any two swatches close enough to read as one colour.
     *
     * Walks in declared order and treats everything already accepted as fixed, so `accent` —
     * the organiser's actual choice — is never the one that moves. Lightness is the axis that
     * gets nudged: shifting hue to gain separation is what would take a swatch out of the
     * event's family, which is the one thing this must not do.
     *
     * @param array<string, array{float,float,float}> $draft
     * @return array<string, array{float,float,float}>
     */
    private static function separate(array $draft): array
    {
        $kept = [];
        foreach ($draft as $key => [$h, $s, $l]) {
            if (self::clearance($h, $s, $l, $kept) >= self::MIN_SEPARATION) {
                $kept[$key] = [$h, $s, $l];
                continue;
            }

            // ── SEARCHED, NOT STEPPED ───────────────────────────────────────
            //
            // The obvious implementation nudges one notch away from whatever it collided
            // with and tries again. That oscillates: a swatch wedged between two accepted
            // neighbours is pushed onto one, then back onto the other, and when the attempts
            // run out it is left sitting on a colour it had already rejected. A `#333333`
            // accent produced two identical swatches that way.
            //
            // Scanning the whole range and keeping the best cannot oscillate, and cannot
            // fail worse than the starting point.
            $bestL = $l;
            $best  = -INF;
            for ($c = 2; $c <= 98; $c += 2) {
                $cl = $c / 100;
                // The clearance benefit is CAPPED. Uncapped, every crowded swatch would flee
                // to the far end of the range and the ladder would come out as black, white
                // and nothing in between — so past "comfortably distinct" the tiebreak is
                // staying near the lightness the ladder actually asked for.
                $score = min(self::clearance($h, $s, $cl, $kept), self::MIN_SEPARATION * 1.2)
                       - abs($cl - $l) * 40;
                if ($score > $best) {
                    $best  = $score;
                    $bestL = $cl;
                }
            }
            $kept[$key] = [$h, $s, $bestL];
        }
        return $kept;
    }

    /**
     * How far a candidate sits from the nearest swatch already accepted.
     *
     * @param array<string, array{float,float,float}> $kept
     */
    private static function clearance(float $h, float $s, float $l, array $kept): float
    {
        if ($kept === []) return INF;
        $hex = self::fromHsl($h, $s, $l);
        $min = INF;
        foreach ($kept as $k) {
            $min = min($min, self::distance($hex, self::fromHsl($k[0], $k[1], $k[2])));
        }
        return $min;
    }

    /**
     * Which ink clears 4.5:1 on this fill — guaranteed, not approximated.
     *
     * ── WHY NOT JUST {@see EventTicketDesign::contrastInk()} ─────────────────────
     *
     * That one answers a different question. It picks the ink for a ticket HEADER — large,
     * heavy text over a big accent panel — and it does that with a single luminance
     * threshold tuned for exactly that use. On a mid-tone tier fill the same threshold can
     * hand back an ink at 2.85:1, which is below the 3:1 floor for large text, let alone the
     * 4.5:1 for normal text (WCAG 1.4.3). A palette that publishes an `ink` value has to mean
     * it wherever a caller puts text.
     *
     * So: whichever of the platform's ink and white actually measures better, and pure black
     * if neither reaches 4.5. That last branch is what makes the guarantee total — the worst
     * fill in the space still clears 4.58:1 against black — while keeping the brand ink on
     * the overwhelming majority of swatches.
     */
    private static function ink(string $fill): string
    {
        $best = self::contrast(EventTicketDesign::DEFAULT_ACCENT, $fill)
              >= self::contrast('#FFFFFF', $fill)
            ? EventTicketDesign::DEFAULT_ACCENT
            : '#FFFFFF';

        return self::contrast($best, $fill) >= 4.5 ? $best : '#000000';
    }

    /**
     * A border dark enough that the swatch is a visible object on a white card.
     *
     * A pale `soft` chip on `#fff` with no edge is not a light colour, it is a hole. Darkened
     * in steps until it clears 3:1, rather than a fixed grey, so the outline still belongs to
     * the same colour as the fill.
     */
    private static function edge(string $fill): string
    {
        [$h, $s, $l] = self::toHsl($fill);
        for ($i = 0; $i < 12; $i++) {
            $hex = self::fromHsl($h, $s, $l);
            if (self::contrast($hex, '#FFFFFF') >= self::EDGE_TARGET) return $hex;
            $l = max(0.0, $l - 0.06);
        }
        return self::fromHsl($h, $s, 0.0);
    }

    /** WCAG relative-luminance contrast ratio between two hexes. */
    public static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $chan = static function (int $c): float {
                $c /= 255;
                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };
            [$r, $g, $bl] = EventTicketDesign::channels($hex);
            return 0.2126 * $chan($r) + 0.7152 * $chan($g) + 0.0722 * $chan($bl);
        };
        $la = $lum($a);
        $lb = $lum($b);
        return ($la > $lb ? $la + 0.05 : $lb + 0.05) / ($la > $lb ? $lb + 0.05 : $la + 0.05);
    }

    /**
     * Perceptual-ish distance between two colours ("redmean").
     *
     * Not plain RGB distance: `#000080` and `#008000` are equally far apart by that measure
     * and wildly different to look at. Redmean is the cheap approximation that gets the eye's
     * weighting roughly right, and this only has to answer "can a person tell these apart",
     * not "by how much".
     */
    public static function distance(string $a, string $b): float
    {
        [$r1, $g1, $b1] = EventTicketDesign::channels($a);
        [$r2, $g2, $b2] = EventTicketDesign::channels($b);
        $rm = ($r1 + $r2) / 2;
        return sqrt(
            (2 + $rm / 256) * ($r1 - $r2) ** 2
            + 4 * ($g1 - $g2) ** 2
            + (2 + (255 - $rm) / 256) * ($b1 - $b2) ** 2
        );
    }

    // ══ colour space ═════════════════════════════════════════════════════════════════

    /** @return array{float,float,float} h in degrees, s and l in 0–1 */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = EventTicketDesign::channels($hex);
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d < 1e-9) return [0.0, 0.0, $l];

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match (true) {
            $max === $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $max === $g => (($b - $r) / $d) + 2,
            default     => (($r - $g) / $d) + 4,
        };
        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $h = self::hue($h) / 360;
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));

        if ($s < 1e-9) {
            $v = (int) round($l * 255);
            return sprintf('#%02X%02X%02X', $v, $v, $v);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $to = static function (float $t) use ($p, $q): int {
            if ($t < 0) $t += 1;
            if ($t > 1) $t -= 1;
            $v = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default    => $p,
            };
            return (int) round($v * 255);
        };
        return sprintf('#%02X%02X%02X', $to($h + 1 / 3), $to($h), $to($h - 1 / 3));
    }

    /** Wrap a hue into 0–360 — the rotations above deliberately run off both ends. */
    private static function hue(float $h): float
    {
        $h = fmod($h, 360);
        return $h < 0 ? $h + 360 : $h;
    }
}
