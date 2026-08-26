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
 * ~14px a comfortable-looking inset gives you. This was checked by doing what a messaging
 * app does — downscale to 75%, JPEG at quality 50, twice — and at two modules the `square`
 * and `plain` codes decoded when sent and FAILED when forwarded. An undersized quiet zone
 * still looks completely correct on screen, which is what makes it expensive.
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
     * see the class note for the recompression measurement that decided it.
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
    public const GAP_QR_META   = 44;
    public const GAP_META_LINE = 10;
    public const GAP_STATE     = 40;
    public const GAP_CLAIM     = 30;
    public const GAP_KICKER    = 26;
    /** Between the mark row and the name under it. */
    public const GAP_CHIP      = 22;

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

    public const NAME_SIZE  = ['story' => 40, 'square' => 34, 'plain' => 38];
    public const META_SIZE  = ['story' => 30, 'square' => 25, 'plain' => 28];
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
