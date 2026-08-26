<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Every number and colour the "I will be there" flier is drawn from.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE TABLE, THREE FORMATS, TWO STATES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff is explicit that this is one design and not two: "One template, one boolean.
 * Do not build two designs." So the state — has a ticket, or does not — changes exactly two
 * things, the mark and the second line, and nothing else in this file branches on it.
 *
 * The FORMAT changes layout, and the three are genuinely different rather than one scaled:
 *
 *   · `story`  1080×1920 — photo on top, claim on a gradient plate below. The face lands in
 *     the centre-square crop a messaging app makes for its thumbnail, which is the only part
 *     most people ever see.
 *   · `square` 1080×1080 — a hard vertical split. Nothing that carries meaning within
 *     {@see SQ_SAFE} of an edge, because a display picture is rendered as a CIRCLE and the
 *     corners are simply gone.
 *   · `plain`  1080×1080 — no photo, and the most common case by far. A warm typographic
 *     ground, offered FIRST. It is a different design, not a degraded one: a dark layout
 *     with a hole where a face should be is what makes somebody close the page.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE QUIET ZONE IS A MEASUREMENT, NOT A MARGIN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Four modules of white on every side, which at these module sizes is 26–28px and not the
 * ~14px a comfortable-looking inset gives you. It is four because the QR specification says
 * four: a decoder locates the symbol by its edge, and the quiet zone is part of the symbol
 * rather than a margin around it.
 *
 * ── AND IT CANNOT BE JUSTIFIED BY SIMULATION ─────────────────────────────────
 *
 * The obvious way to defend the number is to render the code, put it through what a
 * messaging app does, and show that a smaller zone fails. That was built
 * (`tests/Support/qr-recompression-check.py`) and it does not support the conclusion:
 * moving the plate by ONE PIXEL flips pass to fail, and the 2-module zone sometimes decodes
 * where the 4-module one does not. What it measures is how `cv2.resize` and JPEG happen to
 * land on the module grid, not robustness — a real scanner samples continuously through a
 * camera and thresholds adaptively.
 *
 * So: four because it is specified, and the script stays as a smoke check that a rendered
 * symbol decodes at all. An undersized quiet zone still looks completely correct on screen,
 * which is what makes trimming it tempting and expensive.
 *
 * The pattern also never sits on the dark ground: it always gets its own white plate.
 */
final class EventFlierLayout
{
    /** The three, and only three. A fourth is a fourth thing to keep in sync. */
    public const FORMATS = ['story', 'square', 'plain'];

    /** Canvas, per format. */
    public const SIZE = [
        'story'  => [1080, 1920],
        'square' => [1080, 1080],
        'plain'  => [1080, 1080],
    ];

    /** Where the photo sits, per format. `plain` has none — that is the point of it. */
    public const PHOTO = [
        'story'  => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1120],
        'square' => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 560],
    ];

    /**
     * A display picture is a circle, so the corners of a square are not shown at all.
     * Nothing that carries meaning goes within this of an edge.
     */
    public const SQ_SAFE = 90;

    public const PAD = 80;

    /**
     * QR module size and its quiet zone, per format. The pad is four modules, rounded up —
     * see the class note for why four, and for why not to try to prove it by simulation.
     *
     * ── NO x/y HERE, DELIBERATELY ────────────────────────────────────────────
     *
     * The first build fixed the QR at a coordinate per format and it collided with the meta
     * line on every one of them: the text above it wraps to one line or two depending on the
     * event's title and the sharer's name, so anything below it at a constant y is sometimes
     * on top of it. The plate is opaque, so the collision does not look like a bug — it looks
     * like a date that has been cropped.
     *
     * The whole stack is bottom-anchored now and the QR's position is COMPUTED. See
     * {@see EventFlier::png()}.
     */
    public const QR = [
        'story'  => ['module' => 7, 'pad' => 28],
        'square' => ['module' => 6, 'pad' => 26],
        'plain'  => ['module' => 6, 'pad' => 27],
    ];

    /** Gaps in the bottom-anchored stack. */
    public const GAP_QR_META   = 52;
    public const GAP_META_LINE = 14;
    public const GAP_STATE     = 44;
    public const GAP_CLAIM     = 34;
    public const GAP_KICKER    = 26;
    /** Between the mark row and the name under it. */
    public const GAP_CHIP      = 22;

    /**
     * Baseline-to-baseline between the gold invitation and the name below it, on the open
     * flier.
     *
     * NOT `GAP_CHIP`, which is what separates the confirmed flier's chip ROW from the name.
     * A chip carries its own vertical padding inside a filled box, so 22px of clear space
     * below it reads as a comfortable gap; 22px between two BASELINES of 38px type is 16px
     * of ink-to-ink and the two lines close up into one block. Rendered side by side, the
     * open flier's "Come with me." and the name below it read as a single two-line
     * paragraph while the confirmed flier's chip and name read as two things.
     *
     * The same number in two places meaning two different amounts of space is the trap here,
     * and it is only visible with both states open next to each other.
     */
    public const GAP_INVITE    = 40;

    /**
     * The hairline under the claim.
     *
     * ── WHY A RULE AND NOT MORE SPACING ─────────────────────────────────────
     *
     * The first version of this design was correct and loose: a stack of type on a ground,
     * with nothing anchoring it. Everything read as equally important because nothing was
     * separated from anything, and the eye had no reason to stop at the claim.
     *
     * A hairline is this platform's own idiom — the design system is "paper ground, hairline
     * rules, mono micro-labels, one gold accent" — and it does the work that adding whitespace
     * cannot: it says the claim is the headline and everything below it is the detail. Gold,
     * because the accent is already gold and a second colour would be a second decision.
     *
     * 2px, not 1: this image is recompressed by a messaging app and viewed as a thumbnail, and
     * a one-pixel line has nothing to lose before it is gone.
     */
    public const RULE_H     = 2;
    public const RULE_W     = 132;
    public const GAP_RULE   = 30;

    /**
     * Tracking on the mono lines — deliberately ZERO.
     *
     * These lines are tracked uppercase SANS, not the mono face — which is a deliberate
     * departure from the house "mono micro-labels" rule, for one reason: AGMono's period and
     * colon sit at the left of their cells with the remainder as bearing, so "14:00" renders
     * as "14: 00" and a domain as "afg. afrovanguard. org. ng". That is the face, not the
     * tracking; it survived setting tracking to zero. Uppercase and tracked reads as a
     * micro-label without the cell artefacts.
     *
     * The kicker still uses mono, because "AFRICA GATES" has no punctuation in it to open up
     * and a wide-set mono word is exactly the effect wanted there.
     */
    public const MONO_TRACK = 2.2;
    /**
     * The typeable address under the QR's label.
     *
     * Smaller than the label above it, and it has to be: it is the whole path now, not a
     * bare host, so on a long slug it is the longest string on the flier. Truncated by
     * measurement rather than by character count — see EventFlier::qr().
     */
    public const HOST_SIZE  = 19;
    public const HOST_TRACK = 0.8;

    // ── type ────────────────────────────────────────────────────────────────

    /** The claim, in Playfair. Identical in both states — see the handoff's state table. */
    public const CLAIM = 'I will be there';

    public const CLAIM_SIZE = ['story' => 96, 'square' => 74, 'plain' => 86];
    public const CLAIM_LINE = 1.06;

    /** The invitation line on an open flier. Gold, and never a statement of what is missing. */
    public const INVITE = 'Come with me.';

    /** Mono micro-label above the claim. */
    public const KICKER_SIZE = 20;
    public const KICKER_TRACK = 6.0;

    public const NAME_SIZE  = ['story' => 42, 'square' => 34, 'plain' => 38];
    /** The event's own name — a name, so sans and not mono. */
    public const META_SIZE  = ['story' => 31, 'square' => 26, 'plain' => 29];
    /** The date and place. Mono micro-label, so smaller than the title above it. */
    public const WHEN_SIZE  = ['story' => 23, 'square' => 20, 'plain' => 22];
    public const CHIP_SIZE  = 22;
    public const QRLABEL_SIZE = 24;

    /**
     * What the QR says it is for, per state. Two strings because they are two different
     * instructions: on an open flier the reader is being sent to buy a ticket, on a
     * confirmed one they are being sent through somebody's referral link.
     */
    public const QR_LABEL_OPEN      = 'GET YOUR TICKET';
    public const QR_LABEL_CONFIRMED = 'SCAN FOR YOUR TICKET';

    /** The mark, on a confirmed flier only. There is no negative form of this. */
    public const MARK = 'Ticket confirmed';

    /**
     * Tiers that earn a chip beside the mark.
     *
     * ── WHY THIS IS AN ALLOW-LIST AND NOT "ANY TIER" ────────────────────────
     *
     * A chip reading "General" on a flier somebody is posting about themselves is a badge
     * that says "the cheapest one". The handoff calls this a deliberate omission rather than
     * a missing case, and it is: the chip appears where it flatters and is absent where it
     * would not. Matched case-insensitively on the tier's NAME, so an organiser who calls
     * their top tier "patron" in lower case still gets it.
     */
    public const CHIP_TIERS = ['patron', 'supporter', 'benefactor', 'vip', 'founder', 'sponsor'];

    // ── colour ──────────────────────────────────────────────────────────────
    //
    // The dark formats share the platform's own deep green ground; `plain` is the warm one.

    public const C_INK        = '#10292c';
    public const C_WHITE      = '#ffffff';
    public const C_GOLD       = '#f3b416';
    public const C_ON_GOLD    = '#2a1e02';
    public const C_GREEN      = '#237b22';
    public const C_PLATE_TOP  = '#0d2f26';
    public const C_PLATE_BOT  = '#071d18';
    public const C_MIST       = '#e8f2ec';
    public const C_MUTED      = '#a9c7bd';
    /** `plain`'s ground: paper, warm, and legibly not a dark layout with the photo missing. */
    public const C_PAPER_TOP  = '#f4efe4';
    public const C_PAPER_BOT  = '#e7dfcd';
    public const C_PAPER_INK  = '#20180a';
    public const C_PAPER_MUTE = '#6b5f45';

    /** @return array{0:int,1:int} */
    public static function size(string $fmt): array
    {
        return self::SIZE[$fmt] ?? self::SIZE['plain'];
    }

    public static function valid(string $fmt): bool
    {
        return in_array($fmt, self::FORMATS, true);
    }

    /** Does this tier's name earn a chip? See CHIP_TIERS. */
    public static function chipFor(string $tier): bool
    {
        $t = strtolower(trim($tier));
        if ($t === '') return false;

        foreach (self::CHIP_TIERS as $earns) {
            if (str_contains($t, $earns)) return true;
        }
        return false;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (strlen($h) !== 6 || !ctype_xdigit($h)) return [0, 0, 0];

        return [(int) hexdec(substr($h, 0, 2)),
                (int) hexdec(substr($h, 2, 2)),
                (int) hexdec(substr($h, 4, 2))];
    }
}
