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
     *               chip:bool, qr:string, qr_label:string, host:string, when:string,
     *               venue:string}|null
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

        // ══ WHERE THE QR POINTS, AND WHAT IS PRINTED UNDER IT ═══════════════
        //
        // Confirmed: the sharer's own referral link, which is what makes the flier pay them.
        // Open: the plain event URL.
        //
        // ── MINTED BY ReferralService::link(), NOT BUILT HERE ────────────────
        //
        // The first version of this assembled `?ref=` by hand, which made the flier a SECOND
        // minter of referral URLs on a platform that already has one. That is the same drift
        // this codebase warns about for tier colours and for the Apps Script secret: two
        // constructions of one value, agreeing today, disagreeing the first time somebody
        // changes the parameter name or adds a path segment. `link()` is the minter.
        //
        // ── AND WHY THE PRINTED LINE IS NOT byte-for-byte THE QR ─────────────
        //
        // The printed address is the referral link. The QR is the referral link PLUS the
        // campaign tag. They differ by exactly the parameter whose purpose is to tell
        // channels apart — and a scan and a typed visit genuinely are different channels, so
        // them differing is the point rather than a mismatch. Everything that decides where
        // somebody LANDS, and who gets paid for it, is identical.
        // rawurlencode on the slug because ReferralService::link() does, and the fallback
        // below swaps one of these strings for the other.
        $eventUrl = rtrim($base, '/') . '/events/' . rawurlencode($slug);
        $reference = $eventUrl;

        // ── AND ONLY IF *THIS* EVENT SHARES ITS GATE ─────────────────────────
        //
        // `enabledForEvent()`, not just the global switch. An organiser can turn sharing off
        // for one evening whose margin is already committed, and ReferralService refuses such
        // a code twice over: `usable()` tells the buyer "referral links are not being used for
        // this event", and `credit()` refuses again at the moment money would be earned. A
        // flier printing `?ref=` there would promise a commission the platform will decline —
        // the sharer does the work, the buyer is told the code is dead, and nobody involved
        // can see why. The event page asks the same question before it offers the link at all.
        if ($confirmed && ReferralService::enabledForEvent((int) ($event->id ?? 0))) {
            $ref = self::referralFor($t['registration']);
            if ($ref !== '') $reference = ReferralService::link($base, $ref, $slug);
        }

        $target = $reference . (str_contains($reference, '?') ? '&' : '?') . 'c=' . self::CAMPAIGN;

        // Refused rather than truncated if it will not fit — see Qr::encodeBytes(). A URL
        // silently shortened scans perfectly and goes somewhere else.
        if (strlen($target) > Qr::MAX_BYTES) {
            $target    = $eventUrl;
            $reference = $eventUrl;
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
            // ── PRINTED AS TEXT BESIDE THE CODE ──────────────────────────────
            //
            // A QR is unreachable to a screen reader and unusable to anybody who cannot scan,
            // so the address goes in words as well. Derived from the SAME string the code
            // encodes, with the scheme dropped because nobody types one — so the two cannot
            // drift, and typing it credits the sharer exactly as scanning does.
            //
            // A referral code is `AG` plus six characters from an alphabet with no O/0, no
            // I/1/L and no vowels — chosen, per its own note in ReferralService, because that
            // matters on a code somebody prints. So this is typeable by design and needed no
            // shortener: see §11.2 of the handoff for why one was not built.
            'host'      => rtrim(preg_replace('~^https?://~', '', $reference) ?? '', '/'),
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
    public function png(array $f, string $fmt = 'plain', ?string $photo = null,
                       mixed $photoIm = null, ?float $focusX = null, ?float $focusY = null): ?string
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
        $hasPhoto = $photoIm !== null || ($photo !== null && trim($photo) !== '');
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
            // An already-decoded upload wins over a path: the upload is never written to disk,
            // so there is nothing to load it from. See decodeUpload().
            $img = $photoIm ?? $this->loadPhoto((string) $photo);
            // Guaranteed present by the fallback above, but a remote fetch can still fail —
            // and if it does, `plain` is again the answer rather than an empty slot.
            if ($img === null) return $this->png($f, 'plain');

            // The REFRAME, or the face. A cover-crop has one degree of freedom in each axis,
            // and the handoff is blunt that reframing is not optional polish: a mis-cropped
            // selfie is the main reason a generated flier gets binned, and the type sits
            // below the lower third.
            //
            // Resolved through focus() rather than used raw, so the detected default and the
            // dragged value come out of ONE function. Called here as well as in the
            // controller because a caller that reaches png() directly must get the same
            // framing as one that came through the route — a photo whose face is found on one
            // path and not the other is the drift this codebase keeps paying for.
            $fp = self::focus($img, $focusX, $focusY);
            $this->cover($im, $img, $slot['x'], $slot['y'], $slot['w'], $slot['h'],
                         $fp['x'], $fp['y']);
            if ($photoIm === null) imagedestroy($img);
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

        $whenSize = $L::WHEN_SIZE[$fmt];

        $usedH = $qrSide
               + $L::GAP_QR_META
               + $whenSize + $L::GAP_META_LINE
               + $titleLinesN * (int) round($metaSize * 1.3)
               + $L::GAP_STATE + $nameSize
               // The name is drawn in BOTH states, and in both there is one line above it:
               // the chip row when confirmed, the gold invitation when not. Measuring only
               // the confirmed one is how the open stack came out a line short and the claim
               // sat closer to the invitation than to its own rule.
               + (($f['confirmed'] ?? false)
                   ? self::CHIP_H + $L::GAP_CHIP
                   : $nameSize + $L::GAP_INVITE)
               + $L::GAP_CLAIM
               + $claimLinesN * (int) round($claimSz * $L::CLAIM_LINE)
               + $L::GAP_RULE + $L::RULE_H
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
                  (string) ($f['host'] ?? ''), $fmt, $mute, $ink, $pad, $qrY);

        // ── the event, in words, just above it ───────────────────────────────
        $ev = (array) ($f['event'] ?? []);
        $titleLines = $this->wrapMeasured(trim((string) ($ev['title'] ?? '')),
            $W - $pad * 2, $metaSize, FlierService::fontPath('bold'), 2);

        // Mono, uppercase, tracked. The house style reserves mono for metadata, and that is
        // what stops a date reading as a sentence — the first version set it in the body face
        // at nearly the title's size, so the title and the date competed and neither won.
        $when = mb_strtoupper(trim((string) ($f['when'] ?? '')));
        if (($f['venue'] ?? '') !== '') $when .= '  ·  ' . mb_strtoupper((string) $f['venue']);

        $metaBottom = $qrY - $L::GAP_QR_META;
        $whenY      = $metaBottom;
        $titleY     = $whenY - $whenSize - $L::GAP_META_LINE
                    - (count($titleLines) - 1) * (int) round($metaSize * 1.3);

        // Tracked uppercase SANS, not the mono face. Mono is the house idiom for metadata and
        // it is the wrong tool for this particular string: AGMono's period and colon sit left
        // in their cells with the rest of the cell as bearing, so "14:00" renders as "14: 00"
        // and a domain as "afg. afrovanguard. org. ng". That is inherent to the face and not
        // tracking — it survived setting tracking to zero. Uppercase and tracked gives the
        // micro-label reading without the cell artefacts; the kicker keeps the mono, because
        // it has no punctuation in it to open up.
        $this->text($im, $when, $whenSize, FlierService::fontPath('bold'), $mute,
                    $pad, $whenY, $L::MONO_TRACK);
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

        // ── THE NAME IS DRAWN IN BOTH STATES ────────────────────────────────
        //
        // It was drawn only when confirmed, which meant the OPEN flier — the ungated path,
        // the common one, the entire reason the feature exists — carried no name at all. The
        // generator asks for one, requires it, caps it at 60 characters, strips control
        // characters from it and signs it into a token, and then nothing read it. That is the
        // shape §17 of the codebase index is about, on the majority path, and it is visible
        // only by rendering the open state and looking for the name.
        //
        // The handoff's own first line is "their name, the event, and a QR". Its state table
        // lists what the SECOND line is — the mark and chips when confirmed, the gold
        // invitation when not — and that is the line above the name, not instead of it. One
        // template, one boolean: the name is the constant and the state decides what sits
        // over it.
        $this->text($im, (string) $f['name'], $nameSize,
            FlierService::fontPath('semibold'), $ink, $pad, $stateBottom);

        // `text()` takes a BASELINE and `chip()` draws a box DOWNWARD from its y, so a chip
        // placed one name-height above the name's baseline lands on top of the name's
        // ascenders. The first render put "Ticket confirmed" straight through "Ada Nwosu" —
        // legible enough to look deliberate, which is what made it worth a comment. The
        // chip's own height has to come off as well.
        if ($f['confirmed'] ?? false) {
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
            // Gold, and an invitation rather than a statement about a ticket. A BASELINE, so
            // one name-height up rather than a box height — the chip above needs its own
            // height taken off and a line of type does not.
            $inviteY = $stateBottom - $nameSize - $L::GAP_INVITE;
            $this->text($im, $L::INVITE, $nameSize,
                FlierService::fontPath('semibold'), $gold, $pad, $inviteY);
            $claimBottom = $inviteY - $nameSize - $L::GAP_CLAIM;
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

        // ── the hairline ────────────────────────────────────────────────────
        //
        // Between the kicker and the claim, so the claim has something to sit on. The design
        // was correct and loose without it: a stack of type where nothing was separated from
        // anything, so the eye had no reason to stop at the headline. This is the platform's
        // own idiom — hairline rules, mono micro-labels, one gold accent — and it does what
        // more whitespace cannot.
        $ruleY = $claimTop - $L::GAP_RULE;
        imagefilledrectangle($im, $pad, $ruleY, $pad + $L::RULE_W - 1,
                             $ruleY + $L::RULE_H - 1, $gold);

        $this->text($im, 'AFRICA GATES', $L::KICKER_SIZE, FlierService::fontPath('mono'),
            $paper ? $mute : $gold, $pad, $ruleY - $L::GAP_KICKER, $L::KICKER_TRACK);

        ob_start();
        imagepng($im, null, 6);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out !== '' ? $out : null;
    }

    /**
     * The largest photo this will decode, in bytes.
     *
     * A ceiling, not a policy: `imagecreatefromstring` allocates roughly width × height × 4
     * bytes whatever the compressed size is, so a 40MB JPEG is a memory limit away from a
     * blank page rather than an error. Refused with a sentence instead.
     */
    public const PHOTO_MAX_BYTES = 12 * 1024 * 1024;

    /** And the largest dimensions, for the same reason: 8000×8000 is 256MB decoded. */
    public const PHOTO_MAX_SIDE = 6000;

    /**
     * Where the crop is centred: what somebody dragged, or where the face is.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * ONE RESOLVER, TWO CALLERS, AND THAT IS THE POINT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `png()` needs it to draw. The route needs it to tell the browser where the frame
     * ended up, so the reframe step opens with its handle already on the face instead of in
     * the middle of the picture — a reframe screen that starts somewhere the render did not
     * is a preview that lies about the image it is previewing.
     *
     * Two places needing the same answer is exactly how this codebase has repeatedly grown
     * two answers. So there is one function, and both call it.
     *
     * ── A DRAG ALWAYS WINS ───────────────────────────────────────────────────
     *
     * If either axis was supplied, detection does not run. Somebody who has moved the frame
     * has told us where they want it, and a detector that overrules them is a control that
     * does not work. Note this is `!== null` on EITHER axis, not both: the reframe posts the
     * pair together, and a partial pair means a hand-built request, which gets the axis it
     * gave and the anchor for the one it did not.
     *
     * @param \GdImage|resource|null $photoIm
     * @return array{x:float|null,y:float|null}
     */
    public static function focus(mixed $photoIm, ?float $fx = null, ?float $fy = null): array
    {
        if ($fx !== null || $fy !== null) {
            return [
                'x' => $fx === null ? null : max(0.0, min(1.0, $fx)),
                'y' => $fy === null ? null : max(0.0, min(1.0, $fy)),
            ];
        }

        $face = FaceFinder::focus($photoIm);

        // Null on both axes when nothing was found, which keeps FlierRaster's own anchor —
        // the behaviour that shipped before FaceFinder existed. It can improve on that or
        // tie it; it cannot do worse, and that is what makes running it by default safe.
        return $face === null
            ? ['x' => null, 'y' => null]
            : ['x' => $face['x'], 'y' => $face['y']];
    }

    /**
     * The same photo, arriving as base64 instead of as a file part.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THERE IS A SECOND WAY IN
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Not a second decoder — it writes the bytes to a temp file and hands them to
     * {@see decodeUpload()}, so every guard (byte cap, real type from the bytes, pixel cap)
     * is enforced in exactly one place. Only the transport differs.
     *
     * It exists because the multipart transport is not reliably available. Production is a
     * shared host, and this platform's flier POST came back **406 Not Acceptable** there — a
     * status this application never returns, from any route, for any reason. A 406 on a POST
     * that carries an image is a request filter in front of PHP: cPanel ships mod_security
     * with `status:406` as its default deny, and its multipart body rules are the ones that
     * fire on an image upload. With no shell on this host, that filter cannot be inspected,
     * tuned, or switched off.
     *
     * So the browser has a second transport to fall back to, and this is its landing point.
     * A urlencoded field is a different shape to a filter than a multipart part is, which is
     * the entire reason it is worth trying. It is a fallback and not the default because
     * base64 is a third larger on the wire and buffers the whole image in memory as a string.
     *
     * @return \GdImage|resource|null
     */
    public static function decodeBase64(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // A canvas or a FileReader hands over `data:image/jpeg;base64,…`. Accept it and also
        // accept the bare payload, because which one arrives depends on how the browser was
        // asked and neither is wrong.
        if (str_starts_with($raw, 'data:')) {
            $comma = strpos($raw, ',');
            if ($comma === false) return null;
            $raw = substr($raw, $comma + 1);
        }

        // Refuse before decoding, not after. base64 is 4 bytes per 3, so this bounds the
        // decoded size without first materialising a decoded string that a caller could use
        // to exhaust memory — the cap has to hold against a hostile body, not a friendly one.
        if (strlen($raw) > (int) ceil(self::PHOTO_MAX_BYTES * 4 / 3) + 1024) return null;

        // Strict, so a body with junk in it fails here rather than decoding to bytes that
        // happen to start with a JPEG marker.
        $bin = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($bin === false || $bin === '' || strlen($bin) > self::PHOTO_MAX_BYTES) return null;

        // ── AND IT GOES THROUGH A TEMP FILE, ON PURPOSE ──────────────────────
        //
        // The privacy promise is that the photo is drawn and DISCARDED, verified "at the
        // storage layer, not by reading the code". PHP's own upload temp gives that for free
        // on the multipart path. Here the file is created and unlinked inside this function,
        // in a `finally`, so the window is a few milliseconds and no later bug can widen it.
        $tmp = @tempnam(sys_get_temp_dir(), 'agflier');
        if ($tmp === false) return null;

        try {
            if (@file_put_contents($tmp, $bin) === false) return null;

            return self::decodeUpload([
                'tmp_name' => $tmp,
                'size'     => strlen($bin),
                'error'    => UPLOAD_ERR_OK,
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * A photo the visitor just uploaded, decoded — and NEVER written anywhere.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE FILE IS NOT STORED, AT ALL
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The handoff says the upload is cropped and DISCARDED, that the UI must say so at the
     * point of upload, and that the discard has to be confirmed "at the storage layer, not by
     * reading the code". The easiest way to satisfy all three is to have no storage layer: the
     * flier is rendered in the SAME request the file arrives in, straight out of PHP's own
     * upload temp, and PHP unlinks that itself when the request ends.
     *
     * So there is no temp directory to sweep, no key to expire, no cron to forget, and nothing
     * for a later bug to leave behind. Asking somebody to upload a photo of their face to an
     * events site needs a reason to trust it, and "the file is never written to our disk" is a
     * stronger one than a retention promise.
     *
     * Returns null for anything that is not a decodable image, and the caller falls back to
     * `plain` — see png(), where a failed photo is already a designed outcome rather than a
     * hole.
     *
     * @param array{tmp_name?:string, size?:int, error?:int} $file one entry of $_FILES
     * @return \GdImage|null
     */
    public static function decodeUpload(array $file): mixed
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
        if (!is_uploaded_file($tmp) && !is_file($tmp)) return null;
        if ((int) ($file['size'] ?? 0) > self::PHOTO_MAX_BYTES) return null;

        // The declared type is whatever the browser said; the real one comes from the bytes.
        $info = @getimagesize($tmp);
        if ($info === false) return null;
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > self::PHOTO_MAX_SIDE || $h > self::PHOTO_MAX_SIDE) return null;

        $im = @imagecreatefromstring((string) @file_get_contents($tmp));

        return $im === false ? null : $im;
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
    private function qr($im, string $target, string $label, string $host, string $fmt,
                        int $mute, int $ink, int $x0, int $y0): void
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

        // ── the caption, BESIDE the code and stacked ─────────────────────────
        //
        // Two lines rather than one floating in the middle of an empty half: what the code
        // does, and where it goes. The instruction alone left a wide gap to the right of the
        // plate with nothing in it, which is the loosest part of the whole composition.
        //
        // The host is printed as TEXT on purpose, and the address it prints is the one the
        // code encodes — see forToken(). A QR is unreachable to a screen reader and unusable
        // to anybody who cannot scan, and a referral link that can only be scanned pays
        // nobody who reads it off a screenshot.
        $tx = $x0 + $side + 34;
        $ty = $y0 + intdiv($side, 2);

        if ($label !== '') {
            $this->text($im, $label, EventFlierLayout::QRLABEL_SIZE,
                FlierService::fontPath('bold'), $ink, $tx, $ty, 2.0);
        }
        if ($host !== '') {
            // ── WRAPPED ACROSS TWO LINES, NOT CUT ────────────────────────────
            //
            // This used wrapMeasured(), which splits on whitespace — and an address has none,
            // so it kept the whole token and drew it off the right edge of the canvas.
            // Off-canvas text does not throw; it is simply not there, and the fault showed up
            // as an address ending mid-code. It only became visible when the printed line
            // grew a `?ref=` on it, which is the change that made it the thing that pays the
            // sharer: every render before that happened to fit.
            //
            // Two lines rather than an ellipsis, because the last characters are the referral
            // CODE. Truncating the tail of this string is truncating somebody's commission.
            $room  = imagesx($im) - $tx - EventFlierLayout::PAD;
            $lines = $this->wrapUrl($host, (float) $room, EventFlierLayout::HOST_SIZE,
                                    FlierService::fontPath('regular'), 2,
                                    EventFlierLayout::HOST_TRACK);

            $hy = $ty + EventFlierLayout::QRLABEL_SIZE + 18;
            foreach ($lines as $line) {
                $this->text($im, $line, EventFlierLayout::HOST_SIZE,
                    FlierService::fontPath('regular'), $mute,
                    $tx, $hy, EventFlierLayout::HOST_TRACK);
                $hy += EventFlierLayout::HOST_SIZE + 8;
            }
        }
    }
}
