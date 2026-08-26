<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Qr;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The "I will be there" flier — a shareable image an attendee makes for themselves.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS, AND WHAT IT IS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It is not an organiser's promo asset. Anyone can make one — no account, no ticket — and a
 * confirmed ticket earns a visible mark and turns the QR into that person's referral link,
 * so the flier pays them.
 *
 * That last part is the whole reason it exists. The referral programme already worked and was
 * already on the event page: as a read-only text field behind a sign-in, which is exactly why
 * nobody used it. This is the same link in a form people already share unprompted.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS RASTERISED HERE AND NOT IN A BROWSER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff asked for a headless renderer. There is no shell on this production host — that
 * constraint runs through the whole platform, and it is why maintenance is a token-gated HTTP
 * endpoint rather than cron — so a headless Chromium cannot be installed and cannot be kept
 * running. The handoff's reason for rejecting client-side canvas is right and stands: these
 * layouts depend on Playfair Display at 900, and a browser export inherits whatever the
 * device has and substitutes silently.
 *
 * So it is GD, server-side, with the faces bundled in `resources/fonts/`. This is not a
 * workaround invented here — {@see FlierService} has rendered the nominee flier this way
 * since it shipped, and the primitives are shared through {@see FlierRaster} rather than
 * written twice.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE STATE CHANGES TWO THINGS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Confirmed adds the mark and replaces the invitation line with the sharer's name; it also
 * points the QR at their referral link and changes the QR's label. Nothing else differs, and
 * there is deliberately no negative form: no "ticket not confirmed", no "pending", no dimmed
 * badge. Absence of the mark IS the signal. A flier that states its own weakness is a flier
 * nobody sends, and it takes the reach with it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT REFUSES A FINISHED EVENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see forToken()} returns null for a past or cancelled event, and the route answers 410. A
 * live QR pointing at a ticket page for an evening that has already happened is worse than no
 * flier at all: somebody scans it, buys nothing, and concludes the platform is broken.
 */
final class EventFlier
{
    use FlierRaster;

    /** The campaign tag, so a flier's traffic is attributable apart from a pasted link. */
    public const CAMPAIGN = 'flier';

    /**
     * A chip's box height. Named because the stack MEASURES with it before drawing, and a
     * chip whose real height differs from the number the layout reserved is the overlap this
     * class has already shipped once.
     */
    public const CHIP_H = 52;

    /**
     * Everything one flier needs, resolved from a signed token.
     *
     * @return array{event:array<string,mixed>, name:string, confirmed:bool, tier:string,
     *               chip:bool, qr:string, qr_label:string, when:string, venue:string}|null
     */
    public static function forToken(string $token, string $base): ?array
    {
        $t = EventFlierToken::read($token);
        if ($t === null) return null;

        try {
            $event = DB::table('gates_site_events')->where('id', $t['event'])->first();
        } catch (\Throwable) {
            return null;
        }
        if (!$event) return null;

        // Past or cancelled: refused, and refused HERE rather than at the route, so every
        // caller inherits it and none has to remember.
        $status = strtolower((string) ($event->status ?? ''));
        if ($status === 'cancelled' || $status === 'draft') return null;
        $when = strtotime((string) ($event->event_date ?? ''));
        if ($when === false || $when < time() - 86400) return null;

        $slug = (string) ($event->slug ?? '');

        // ── the ticket, if there is one ──────────────────────────────────────
        //
        // The tier is read from the REGISTRATION rather than carried in the token, so a token
        // cannot assert a tier its registration does not hold. A free tier counts as
        // confirmed: they have a real ticket, and the mark means ticketed rather than paid —
        // which is the handoff's third open decision, answered that way for that reason.
        $confirmed = false;
        $tier = '';
        if ($t['registration'] > 0) {
            try {
                $reg = DB::table('gates_event_registrations')
                    ->where('id', $t['registration'])
                    ->where('event_id', $t['event'])
                    ->first();
            } catch (\Throwable) { $reg = null; }

            if ($reg && strtolower((string) ($reg->status ?? '')) === 'confirmed') {
                $confirmed = true;
                $tier = trim((string) ($reg->tier ?? ''));
            }
        }

        // ── where the QR points ─────────────────────────────────────────────
        //
        // Confirmed: the sharer's own referral link, which is what makes the flier pay them.
        // Open: the plain event URL. Both carry the campaign tag.
        $eventUrl = rtrim($base, '/') . '/events/' . $slug;
        $target   = $eventUrl . '?c=' . self::CAMPAIGN;

        if ($confirmed) {
            $ref = self::referralFor($t['registration']);
            if ($ref !== '') {
                $target = $eventUrl . '?ref=' . rawurlencode($ref) . '&c=' . self::CAMPAIGN;
            }
        }

        // Refused rather than truncated if it will not fit — see Qr::encodeBytes(). A URL
        // silently shortened scans perfectly and goes somewhere else.
        if (strlen($target) > Qr::MAX_BYTES) {
            $target = $eventUrl;
        }

        return [
            'event'     => (array) $event,
            'name'      => $t['name'],
            'confirmed' => $confirmed,
            'tier'      => $tier,
            'chip'      => $confirmed && EventFlierLayout::chipFor($tier),
            'qr'        => $target,
            'qr_label'  => $confirmed
                ? EventFlierLayout::QR_LABEL_CONFIRMED
                : EventFlierLayout::QR_LABEL_OPEN,
            // Shown in the OPERATOR'S display zone, not the server's. Every date on this
            // platform goes through DisplayTime for the reason its docblock gives: storage is
            // UTC, and formatting a stored timestamp with date() prints an hour that is wrong
            // by the offset on any host not set to the display zone — which is a flier telling
            // people the wrong time to arrive.
            'when'      => \AfricaGates\Support\DisplayTime::showZoned(
                (string) ($event->event_date ?? ''), 'D j M · H:i'),
            // `location` is the city-ish line and `venue` is the room; the flier has space for
            // one and the city is what tells somebody whether they can be there. There is no
            // `city` column — that guess cost a test run.
            'venue'     => trim((string) ($event->location ?? '')) !== ''
                ? trim((string) ($event->location ?? ''))
                : trim((string) ($event->venue ?? '')),
        ];
    }

    /** The sharer's referral code, or '' when they have none. */
    private static function referralFor(int $registrationId): string
    {
        try {
            $reg = DB::table('gates_event_registrations')->where('id', $registrationId)->first();
            if (!$reg) return '';
            $email = strtolower(trim((string) ($reg->email ?? '')));
            if ($email === '') return '';

            $user = DB::table('gates_users')->where('email', $email)->first();
            if (!$user) return '';

            return (string) (ReferralService::codeFor((int) $user->id) ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The flier as a PNG, or null when it cannot be drawn.
     *
     * Null rather than a broken image: without GD or without the bundled faces this would
     * render type in GD's built-in bitmap font at a fixed tiny size, which is not a degraded
     * flier — it is a graphic somebody would think the platform meant to produce.
     *
     * @param array<string,mixed> $f from {@see forToken()}
     */
    public function png(array $f, string $fmt = 'plain', ?string $photo = null): ?string
    {
        if (!EventFlierLayout::valid($fmt)) return null;
        if (!function_exists('imagecreatetruecolor')) return null;
        if (!FlierService::fontsPresent()['ok']) return null;

        // ── NO PHOTO MEANS `plain`, WHATEVER WAS ASKED FOR ───────────────────
        //
        // `story` and `square` are the photo formats: they are built around a face in the
        // upper slot. Rendered without one, `story` is 1120px of flat dark green above the
        // type — which is precisely the "dark layout with a hole" the handoff has on its
        // verify list, and it is what the first build produced.
        //
        // `plain` is not a degraded version of them, it is the design for this case, and the
        // handoff says to offer it first because most people upload nothing. So the fallback
        // is here rather than only in the generator: a format cannot be talked into drawing
        // the hole by a hand-written URL.
        $hasPhoto = $photo !== null && trim($photo) !== '';
        if (!$hasPhoto && isset(EventFlierLayout::PHOTO[$fmt])) {
            $fmt = 'plain';
        }

        [$W, $H] = EventFlierLayout::size($fmt);
        $L = EventFlierLayout::class;

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);

        $c = static function (string $hex) use ($im): int {
            [$r, $g, $b] = EventFlierLayout::rgb($hex);
            return (int) imagecolorallocate($im, $r, $g, $b);
        };

        $paper = $fmt === 'plain';

        $ink   = $c($paper ? $L::C_PAPER_INK : $L::C_WHITE);
        $mute  = $c($paper ? $L::C_PAPER_MUTE : $L::C_MUTED);
        $gold  = $c($L::C_GOLD);
        $white = $c($L::C_WHITE);

        // ── the ground ──────────────────────────────────────────────────────
        //
        // On a photo format the gradient covers only the region BELOW the slot — "the claim on
        // a single gradient plate below", which is what the handoff asks for and also what
        // makes the seam land exactly. Running one gradient over the whole canvas and then
        // fading the photo's scrim to the gradient's END colour put the scrim's bottom and the
        // plate's top two different greens apart at the same y, and the join showed as a hard
        // line across the card.
        $slot = $L::PHOTO[$fmt] ?? null;
        $groundY = $slot !== null ? $slot['y'] + $slot['h'] : 0;

        $this->vGradient($im, 0, $groundY, $W, $H - $groundY,
            EventFlierLayout::rgb($paper ? $L::C_PAPER_TOP : $L::C_PLATE_TOP),
            EventFlierLayout::rgb($paper ? $L::C_PAPER_BOT : $L::C_PLATE_BOT));

        // ── the photo, where the format has one ─────────────────────────────
        if ($slot !== null) {
            // Guaranteed present by the fallback above, but a remote fetch can still fail —
            // and if it does, `plain` is again the answer rather than an empty slot.
            $img = $this->loadPhoto((string) $photo);
            if ($img === null) return $this->png($f, 'plain');

            $this->cover($im, $img, $slot['x'], $slot['y'], $slot['w'], $slot['h']);
            imagedestroy($img);
            // A scrim over the lower band of the photo, so type below it stays legible
            // whatever somebody uploaded. A gradient rather than a flat wash, because a hard
            // edge across a face reads as a rendering fault.
            // An ALPHA scrim, not a gradient between two colours: vGradient() is opaque, so
            // fading "from the plate colour to the plate colour" would paint a solid block
            // over the bottom of the photo rather than darkening it. It ramps to the plate's
            // TOP colour, which is the value immediately below the slot, so the scrim and the
            // plate meet at one number and there is no seam.
            $this->scrim($im, $slot['x'], $slot['y'] + $slot['h'] - 320, $slot['w'], 320,
                         EventFlierLayout::rgb($L::C_PLATE_TOP));
        }

        // ══ THE STACK IS BOTTOM-ANCHORED ════════════════════════════════════
        //
        // Measured from the bottom edge upward, not laid out from a set of fixed
        // coordinates. The first build did the latter and the QR plate landed on top of the
        // meta line on all three formats — because the text above it wraps to one line or two
        // depending on the event's title and the sharer's name, and anything below that at a
        // constant y is sometimes on top of it. The plate is opaque, so it did not read as a
        // bug: it read as a date that had been cropped.
        //
        // Bottom-up also puts the QR where a thumb reaches and the claim where the eye lands,
        // and it cannot collide however long anything wraps.

        $pad = $fmt === 'square' ? $L::SQ_SAFE : $L::PAD;

        // ── how tall the whole stack will be, before any of it is drawn ──────
        //
        // Measured rather than assumed, so the block can be CENTRED in whatever room the
        // format leaves. The first bottom-anchored build was correct and looked unfinished:
        // everything hugged the bottom edge and a third of the frame above it was empty
        // paper. On a square, which is rendered as a circle for a display picture, that is
        // most of what somebody sees.
        $qrSide   = $this->qrSide((string) ($f['qr'] ?? ''), $fmt);
        $metaSize = $L::META_SIZE[$fmt];
        $nameSize = $L::NAME_SIZE[$fmt];
        $claimSz  = $L::CLAIM_SIZE[$fmt];

        $titleLinesN = count($this->wrapMeasured(
            trim((string) (((array) ($f['event'] ?? []))['title'] ?? '')),
            $W - $pad * 2, $metaSize, FlierService::fontPath('bold'), 2));
        $claimLinesN = count($this->wrapMeasured($L::CLAIM, $W - $pad * 2, $claimSz,
            FlierService::fontPath('display'), 2));

        $usedH = $qrSide
               + $L::GAP_QR_META
               + (int) round($metaSize * 0.9) + $L::GAP_META_LINE
               + $titleLinesN * (int) round($metaSize * 1.3)
               + $L::GAP_STATE + $nameSize
               + (($f['confirmed'] ?? false) ? self::CHIP_H + $L::GAP_CHIP : 0)
               + $L::GAP_CLAIM
               + $claimLinesN * (int) round($claimSz * $L::CLAIM_LINE)
               + $L::GAP_KICKER + $L::KICKER_SIZE;

        // The room the format leaves: below the photo where there is one, below the top
        // margin where there is not.
        $regionTop = $slot !== null ? $slot['y'] + $slot['h'] + $L::GAP_CLAIM : $pad;
        $regionBot = $H - $pad;

        // Centred in the room the format leaves, then clamped at BOTH ends: never past the
        // bottom margin, and never so high that the kicker leaves the canvas.
        //
        // The upper clamp is measured from the top margin rather than from `regionTop`,
        // because on `square` the stack is TALLER than the space below the photo and rising
        // over the scrim is correct there — that is the "hard vertical split" the handoff
        // describes. What is not correct is rising off the edge, which is what an unclamped
        // centre does the moment a title wraps to two lines.
        $centred = $regionTop + (int) round((($regionBot - $regionTop) + $usedH) / 2) - $qrSide;
        $qrY = (int) min($regionBot - $qrSide, max($pad + $usedH - $qrSide, $centred));
        $this->qr($im, (string) ($f['qr'] ?? ''), (string) ($f['qr_label'] ?? ''),
                  $fmt, $mute, $pad, $qrY);

        // ── the event, in words, just above it ───────────────────────────────
        $ev = (array) ($f['event'] ?? []);
        $titleLines = $this->wrapMeasured(trim((string) ($ev['title'] ?? '')),
            $W - $pad * 2, $metaSize, FlierService::fontPath('bold'), 2);

        $when = trim((string) ($f['when'] ?? ''));
        if (($f['venue'] ?? '') !== '') $when .= '  ·  ' . (string) $f['venue'];
        $whenSize = (int) round($metaSize * 0.9);

        $metaBottom = $qrY - $L::GAP_QR_META;
        $whenY      = $metaBottom;
        $titleY     = $whenY - $whenSize - $L::GAP_META_LINE
                    - (count($titleLines) - 1) * (int) round($metaSize * 1.3);

        $this->text($im, $when, $whenSize, FlierService::fontPath('regular'), $mute, $pad, $whenY);
        $ty = $titleY;
        foreach ($titleLines as $line) {
            $this->text($im, $line, $metaSize, FlierService::fontPath('bold'), $ink, $pad, $ty);
            $ty += (int) round($metaSize * 1.3);
        }

        // ── the state: the mark and the second line ─────────────────────────
        //
        // The ONLY two things the boolean changes. There is deliberately no negative branch:
        // absence of the mark IS the signal, and a flier that states its own weakness is a
        // flier nobody sends.
        $stateBottom = $titleY - $metaSize - $L::GAP_STATE;

        if ($f['confirmed'] ?? false) {
            $this->text($im, (string) $f['name'], $nameSize,
                FlierService::fontPath('semibold'), $ink, $pad, $stateBottom);

            // `text()` takes a BASELINE and `chip()` draws a box DOWNWARD from its y, so a
            // chip placed one name-height above the name's baseline lands on top of the
            // name's ascenders. The first render put "Ticket confirmed" straight through
            // "Ada Nwosu" — legible enough to look deliberate, which is what made it worth
            // a comment. The chip's own height has to come off as well.
            $chipY = $stateBottom - $nameSize - self::CHIP_H - $L::GAP_CHIP;
            $this->chip($im, $L::MARK, $pad, $chipY, $gold, $c($L::C_ON_GOLD));

            if (!empty($f['chip']) && trim((string) ($f['tier'] ?? '')) !== '') {
                $w = $this->width($L::MARK, $L::CHIP_SIZE, FlierService::fontPath('bold'));
                $this->chip($im, (string) $f['tier'], (int) ($pad + $w + 66), $chipY,
                    $paper ? $ink : $white, $paper ? $c($L::C_PAPER_TOP) : $c($L::C_INK));
            }
            $claimBottom = $chipY - $L::GAP_CLAIM;
            unset($w);
        } else {
            // Gold, and an invitation rather than a statement about a ticket.
            $this->text($im, $L::INVITE, $nameSize,
                FlierService::fontPath('semibold'), $gold, $pad, $stateBottom);
            $claimBottom = $stateBottom - $nameSize - $L::GAP_CLAIM;
        }

        // ── the claim, filling what is left ─────────────────────────────────
        //
        // The whole width: the QR sits BELOW it now, so there is no column to reserve. The
        // first build reserved 360px for a QR beside the claim and then drew the QR at the
        // bottom anyway, which is why `plain` came out with an empty right half.
        $claimSize = $claimSz;
        $claimLines = $this->wrapMeasured($L::CLAIM, $W - $pad * 2, $claimSize,
            FlierService::fontPath('display'), 2);

        $lineStep = (int) round($claimSize * $L::CLAIM_LINE);
        $cy = $claimBottom - ($lineStep * (count($claimLines) - 1));
        $claimTop = $cy - $claimSize;
        foreach ($claimLines as $line) {
            $this->text($im, $line, $claimSize, FlierService::fontPath('display'), $ink, $pad, $cy);
            $cy += $lineStep;
        }

        $this->text($im, 'AFRICA GATES', $L::KICKER_SIZE, FlierService::fontPath('bold'),
            $paper ? $mute : $gold, $pad, $claimTop - $L::GAP_KICKER, $L::KICKER_TRACK);

        ob_start();
        imagepng($im, null, 6);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out !== '' ? $out : null;
    }

    /**
     * A vertical fade from transparent to one opaque colour.
     *
     * Type sits below the photo, not on it, so this is not about legibility over a face — it
     * is about the join. Without it the photo ends at a hard horizontal line against the
     * plate, whatever somebody uploaded, and a straight edge across the middle of a graphic
     * reads as a rendering fault rather than as a design.
     *
     * `imagecolorallocatealpha` counts DOWN to opaque: 127 is invisible and 0 is solid.
     *
     * @param array{0:int,1:int,2:int} $to
     */
    private function scrim($im, int $x, int $y, int $w, int $h, array $to): void
    {
        imagealphablending($im, true);
        for ($i = 0; $i < $h; $i++) {
            $t = $h > 1 ? $i / ($h - 1) : 1.0;
            // Eased rather than linear: a linear ramp has a visible top edge where it starts.
            $a = (int) round(127 - 127 * ($t * $t));
            $c = (int) imagecolorallocatealpha($im, $to[0], $to[1], $to[2], $a);
            imageline($im, $x, $y + $i, $x + $w - 1, $y + $i, $c);
        }
    }

    /**
     * How tall and wide the QR block will be, so the stack above it can be measured.
     *
     * Zero when there is nothing to encode: the layout then closes up rather than leaving a
     * square of empty paper where a code should be.
     */
    private function qrSide(string $target, string $fmt): int
    {
        if ($target === '') return 0;
        $m = Qr::encodeBytes($target);
        if ($m === null) return 0;

        $spec = EventFlierLayout::QR[$fmt];

        return count($m) * (int) $spec['module'] + (int) $spec['pad'] * 2;
    }

    /**
     * A pill with a word in it.
     *
     * Measured rather than guessed at: a chip sized from the character count is a chip that
     * wraps a long tier name outside its own background.
     */
    private function chip($im, string $label, int $x, int $y, int $fill, int $ink): void
    {
        $size = EventFlierLayout::CHIP_SIZE;
        $font = FlierService::fontPath('bold');
        $w    = (int) ceil($this->width($label, $size, $font));
        $h    = self::CHIP_H;

        imagefilledrectangle($im, $x, $y, $x + $w + 44, $y + $h - 1, $fill);
        $this->text($im, $label, $size, $font, $ink, $x + 22, $y + $h - 18);
    }

    /**
     * The code, its quiet zone, and the line saying what it does.
     *
     * The white plate is not decoration: the pattern never sits on the dark ground, because a
     * scanner finds the symbol by its edge and a QR flush against a dark field is a QR that
     * does not read. The plate is the module size × (modules + 8) — four modules a side — and
     * that number came from putting the image through what a messaging app does. See
     * EventFlierLayout's note.
     */
    private function qr($im, string $target, string $label, string $fmt, int $mute,
                        int $x0, int $y0): void
    {
        if ($target === '') return;

        $m = Qr::encodeBytes($target);
        if ($m === null) return;

        $spec = EventFlierLayout::QR[$fmt];
        $mod  = (int) $spec['module'];
        $pad  = (int) $spec['pad'];
        $side = count($m) * $mod + $pad * 2;

        $white = (int) imagecolorallocate($im, 255, 255, 255);
        $black = (int) imagecolorallocate($im, 0, 0, 0);

        // `$side - 1`, not `$side`. imagefilledrectangle() is INCLUSIVE of both corners, so
        // filling to $side draws a plate one pixel taller and wider than the geometry the
        // stack measured with — which put its bottom edge exactly on the square format's
        // 90px keep-out line. FlierService carries the same comment about the same trap for
        // the same reason.
        imagefilledrectangle($im, $x0, $y0, $x0 + $side - 1, $y0 + $side - 1, $white);
        foreach ($m as $r => $row) {
            foreach ($row as $col => $on) {
                if (!$on) continue;
                $x = $x0 + $pad + $col * $mod;
                $y = $y0 + $pad + $r * $mod;
                imagefilledrectangle($im, $x, $y, $x + $mod - 1, $y + $mod - 1, $black);
            }
        }

        if ($label !== '') {
            // Vertically centred against the PLATE, not hung off its top corner. Off the top
            // it reads as a caption for the first few rows of the pattern and leaves the rest
            // of the line beside an empty square.
            $this->text($im, $label, EventFlierLayout::QRLABEL_SIZE,
                FlierService::fontPath('bold'), $mute,
                $x0 + $side + 34,
                $y0 + intdiv($side, 2) + intdiv(EventFlierLayout::QRLABEL_SIZE, 2), 3.0);
        }
    }
}
