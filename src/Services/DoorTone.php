<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The event's accent, made usable on the door's dark frame.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE TICKET'S ACCENT CANNOT BE USED AS IT STANDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every other surface that paints an event — the ticket, the flier, the tier ladder —
 * resolves its colours from `gates_site_events.ticket_accent`, and that value is chosen to
 * sit on PAPER. `EventTicketDesign::DEFAULT_ACCENT` is `#10292C`: a near-black teal, which
 * is exactly right printed on white and completely invisible on the door, whose frame runs
 * from `#2C3838` down to `#080D0E`.
 *
 * The door is dark by design and not by preference — it is worked at night, outdoors, at
 * arm's length — so the accent has to travel the other way from every other surface's. It
 * is lifted along its own hue until it clears the frame, which keeps the organiser's colour
 * recognisably theirs rather than substituting a stock one.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO VALUES, AND THE DISTINCTION IS THE SAME ONE EventFlierTheme MAKES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `accent` clears **3:1** and is for things that are not text: the sweep line in the
 * reticle, the torch's on state, the rule above the verdict and the mark glyph. That is
 * WCAG 1.4.11, the floor for a user-interface component.
 *
 * `accent_text` clears **4.5:1** and is for the one place the accent is asked to be READ —
 * a guest of honour's name, 21px at weight 500, which is not large text by 1.4.3 and so
 * owes the full ratio. Painting that name with the 3:1 value would be legible to the person
 * who chose the colour and marginal to the steward reading it in the dark.
 *
 * One value used for both is how a gold gala's honour line comes out as a smudge. The flier
 * shipped exactly that bug in reverse — `accent` used where `accent_text` was owed turned an
 * olive title unreadable — and the split is the fix that stuck.
 *
 * ── THE GROUND IS THE LIGHTEST PART OF THE FRAME, NOT THE DARKEST ────────────
 *
 * The accent is measured against `#2C3838`, the top of the frame gradient, because that is
 * the ground it has the LEAST contrast against. Measuring against `#080D0E` — where a light
 * accent scores beautifully — would pass every colour and protect nothing. The verdict slab
 * and the dock both sit over darker ground than the measurement, so clearing the top of the
 * gradient clears everywhere the accent is actually drawn.
 */
final class DoorTone
{
    /** The top of the frame gradient: the lightest ground the accent is ever drawn on. */
    public const FRAME_TOP = '#2C3838';

    /** The bottom of it, kept for the sweep's own check — see {@see readable()}. */
    public const FRAME_BOT = '#080D0E';

    /** WCAG 1.4.11 — a control, a rule, a glyph. */
    public const UI_RATIO = 3.0;

    /** WCAG 1.4.3 — a name somebody has to read. */
    public const TEXT_RATIO = 4.5;

    /**
     * Africa GATES gold, and the fallback for an event with no usable accent.
     *
     * It already clears both floors on this frame, so the fallback needs no lifting and an
     * unconfigured event looks deliberate rather than degraded.
     */
    public const DEFAULT_ACCENT = '#F3B416';

    /**
     * Both values for one event.
     *
     * @return array{accent:string, accent_text:string, soft:string}
     */
    public static function forEvent(object|array|null $event): array
    {
        $raw = '';
        if (is_object($event))      $raw = (string) ($event->ticket_accent ?? '');
        elseif (is_array($event))   $raw = (string) ($event['ticket_accent'] ?? '');

        return self::fromAccent($raw);
    }

    /**
     * @return array{accent:string, accent_text:string, soft:string}
     */
    public static function fromAccent(string $raw): array
    {
        $seed = self::normalise($raw);

        $ui   = self::lift($seed, self::UI_RATIO);
        $text = self::lift($seed, self::TEXT_RATIO);

        return [
            'accent'      => $ui,
            'accent_text' => $text,
            // The sweep is a gradient that fades to nothing at both ends, and a browser
            // that does not know `color-mix()` drops the WHOLE background declaration when
            // one stop is invalid — taking the scan line with it on exactly the older venue
            // phones this page is written for. So the mid stop is resolved here, in PHP.
            'soft'        => self::alpha($ui, 0.55),
        ];
    }

    /** A hex we can work with, or the platform's gold. */
    private static function normalise(string $raw): string
    {
        $hex = strtoupper(trim($raw));
        if ($hex !== '' && $hex[0] !== '#') $hex = '#' . $hex;

        if (preg_match('/^#[0-9A-F]{3}$/', $hex) === 1) {
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }

        return preg_match('/^#[0-9A-F]{6}$/', $hex) === 1 ? $hex : self::DEFAULT_ACCENT;
    }

    /**
     * Walk a colour lighter along its own hue until it clears $target on the frame.
     *
     * Only ever lighter. `EventFlierTheme::lift()` decides its direction by measuring the
     * ground, because a flier's ground can be either; the door's cannot. Every surface here
     * is dark, so "away from the ground" has one meaning and pretending otherwise would let
     * a mid-tone accent walk DOWN into the frame and disappear while the maths reported
     * success against the top stop alone.
     *
     * Saturation is held. Dropping it would reach the target faster and hand the organiser
     * a colour they did not choose — the point is their gold on a dark door, not a
     * serviceable yellow.
     *
     * Returns the best it reached when the target is unreachable, which happens only for a
     * hue with almost no headroom left. The best available still beats the value that
     * failed.
     */
    private static function lift(string $seed, float $target): string
    {
        if (EventTierPalette::contrast($seed, self::FRAME_TOP) >= $target) return $seed;

        [$h, $s, $l] = EventTierPalette::toHsl($seed);

        $best     = $seed;
        $bestSeen = EventTierPalette::contrast($seed, self::FRAME_TOP);

        for ($i = 1; $i <= 24; $i++) {
            $hex = EventTierPalette::fromHsl($h, $s, min(1.0, $l + $i * 0.04));
            $got = EventTierPalette::contrast($hex, self::FRAME_TOP);

            if ($got > $bestSeen) { $bestSeen = $got; $best = $hex; }
            if ($got >= $target) return $hex;
        }

        return $best;
    }

    /** `#RRGGBB` at an alpha, as `rgba()`. */
    private static function alpha(string $hex, float $a): string
    {
        $hex = self::normalise($hex);

        return 'rgba(' . hexdec(substr($hex, 1, 2)) . ',' . hexdec(substr($hex, 3, 2))
             . ',' . hexdec(substr($hex, 5, 2)) . ',' . round(max(0.0, min(1.0, $a)), 2) . ')';
    }

    /**
     * Whether a resolved accent clears its floor on BOTH ends of the frame gradient.
     *
     * For the tests, and for anybody adding a surface: the sweep crosses the middle of the
     * frame where the gradient is darkest, so a value that only just clears the top is still
     * safe there — but a future light-themed door would invert that, and this is the check
     * that would catch it rather than a screenshot.
     */
    public static function readable(string $hex, float $target): bool
    {
        return EventTierPalette::contrast($hex, self::FRAME_TOP) >= $target
            && EventTierPalette::contrast($hex, self::FRAME_BOT) >= $target;
    }
}
