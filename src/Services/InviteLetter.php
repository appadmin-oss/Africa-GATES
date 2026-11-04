<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Letterhead;
use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\Pdf;
use Illuminate\Support\Carbon;

/**
 * The formal invitation, as a document somebody keeps.
 *
 * ── WHY A PDF EXISTS AT ALL WHEN THE PASS IS A WEB PAGE ──────────────────────
 *
 * The thing that opens a door is deliberately NOT a document — see
 * {@see \AfricaGates\Controllers\HonourController} on why a printed QR is a photograph.
 * But an invitation to be honoured in public is a different object from a pass: it is
 * shown to a spouse, forwarded to a head teacher, printed and put on a wall, and read
 * years later. That is a letter, and a letter has to survive having no internet.
 *
 * So the two are separated on purpose: this carries the words and the reference, the
 * page carries the code that changes.
 *
 * ── WHY THE REFERENCE IS ON IT AND THE CODE IS NOT ───────────────────────────
 *
 * The reference is stable, is the discount code their guests spend, and is what a
 * steward types if a phone is dead. The rotating code would be false within a minute of
 * the file being written, and a document showing an expired pass is worse than one
 * showing none — somebody would hold it up at the door and be turned away by a letter
 * that told them they were invited.
 *
 * ── A4, ONE PAGE, NO SECOND PAGE EVER ────────────────────────────────────────
 *
 * A4 because this is Nigeria and a head teacher's printer takes A4. One page because the
 * body is measured and wrapped against the real face through {@see Pdf::paragraph()}, and
 * the long name in the salutation is the variable — "Dr Ọlásùnkànmí Adébáyọ̀-Williams"
 * wraps where "Ada Obi" does not, which is exactly the kind of overflow that only shows
 * up on the one letter nobody previewed.
 */
final class InviteLetter
{
    private const PAGE_W = Letterhead::PAGE_W;
    private const PAGE_H = Letterhead::PAGE_H;
    private const MARGIN = Letterhead::MARGIN;

    private const INK   = Letterhead::INK;
    private const MUTE  = Letterhead::MUTE;
    private const RULE  = Letterhead::RULE;

    /**
     * Where the reference block and the sign-off sit, measured up from the page foot.
     *
     * Fixed rather than flowed, so every copy of this letter has its close in the same
     * place — see the note at the reference block. `REF_Y` leaves 18.5mm for the block
     * itself plus a gap before `SIGN_Y`.
     */
    private const REF_Y  = self::PAGE_H - self::MARGIN - 66.0;
    private const SIGN_Y = self::PAGE_H - self::MARGIN - 42.0;

    /**
     * @param object $invite a `gates_event_invites` row
     * @param object $event  a `gates_site_events` row
     * @return string the PDF bytes
     */
    public static function render(object $invite, object $event, ?object $lowestTier = null): string
    {
        $spec = InviteAudience::spec((string) $invite->audience);
        $pdf  = self::begin();
        $pdf->addPage();

        // ── THE SHAPE OF A LETTER ────────────────────────────────────────────
        //
        // This was a web page printed to A4: a wordmark, a rule, "An Invitation" set at
        // 26pt like a magazine cover, an eyebrow, and then straight into "Dear". Every
        // convention that makes correspondence read as correspondence was missing — it
        // carried no date, no reference block, no subject line, no recipient block, and
        // most tellingly nowhere for anybody to sign it.
        //
        // The order below is the ordinary order of a formal letter, and it is ordinary on
        // purpose: this is shown to a spouse, forwarded to a head teacher, and put on a
        // wall. It should look like the letters that already hang there.
        //
        // The MEASURE is narrower than the margins allow — 148mm inside a 166mm text
        // block. 166mm of 10.5pt type is about 95 characters a line, which is half again
        // the width prose is comfortable at, and a wide measure is the single loudest tell
        // that a document was laid out by its container rather than for its reader.
        $x     = self::MARGIN;
        $w     = self::PAGE_W - self::MARGIN * 2;
        $mw    = 148.0;
        $y     = self::MARGIN;

        // ── 1 · letterhead ───────────────────────────────────────────────────
        // Shared with every formal letter; the reasoning for its shape is in Letterhead.
        $y    = Letterhead::draw($pdf);
        $host = Letterhead::host();

        // ── 2 · reference and date ───────────────────────────────────────────
        // A letter with no date is a notice. Both sit right, above the recipient block,
        // which is where a reader's eye goes for them.
        $y += 12.0;
        $ref  = 'Our ref: ' . trim((string) $invite->reference);
        $date = DisplayTime::show(Carbon::now()->toDateTimeString(), 'j F Y');
        $pdf->text($ref,  $x + $w - $pdf->width($ref,  'text', 9.0), $y, 'text', 9.0, self::MUTE);
        $pdf->text($date, $x + $w - $pdf->width($date, 'text', 9.0), $y + 5.2, 'text', 9.0, self::INK);

        // ── 3 · the recipient ────────────────────────────────────────────────
        // There is no postal address to set — nobody collects one — so the block carries
        // what this platform actually knows: who they are and what they are being honoured
        // for. That is the honest version of an address block rather than a blank one.
        $pdf->text(trim((string) $invite->name), $x, $y, 'semi', 11.0, self::INK);
        $pdf->text($spec['one'] . ' · ' . trim((string) $event->title),
                   $x, $y + 5.4, 'text', 9.0, self::MUTE);

        $y += 20.0;

        $when  = DisplayTime::showZoned((string) $event->event_date, 'l j F Y \a\t H:i');
        $where = trim(implode(', ', array_filter([
            trim((string) ($event->venue ?? '')),
            trim((string) ($event->location ?? '')),
        ])));

        // ── 4 · the subject line ─────────────────────────────────────────────
        // What "An Invitation" at 26pt was trying to be. A formal letter states its
        // business in one bold line above the salutation, and the line says what the
        // letter is FOR rather than what kind of thing it is.
        $y = $pdf->paragraph(
            mb_strtoupper('Invitation to ' . trim((string) $event->title)
                        . ' as a guest of honour'),
            $x, $y, $mw, 'bold', 10.0, 5.4, self::INK, 3
        );
        $y += 8.0;

        // ── 5 · the letter ───────────────────────────────────────────────────
        // Baseline, not a box top: Pdf::text takes a baseline while every rectangle grows
        // downward from its origin, which is how a line of type comes to sit on the one
        // above it.
        $y = $pdf->paragraph(
            $spec['salutation'] . ' ' . trim((string) $invite->name) . ',',
            $x, $y, $mw, 'text', 10.5, 5.4, self::INK, 2
        );
        $y += 3.0;

        $body = [
            'It is our privilege to invite you to ' . trim((string) $event->title)
                . ($where !== '' ? ', at ' . $where : '') . ', on ' . $when . '.',

            // The operator\'s sentence, WHOLE. This was the third place assembling it —
            // after the Twig template and the plain-text builder — each prefixing "We want
            // the hall packed " onto what the settings screen presents as an editable
            // sentence. Three authors for one paragraph, and the letter is the copy the
            // invitee keeps.
            'You are invited as a guest of honour. ' . $spec['witness'],
            'We would like the people who know that work best to be in the room to see it '
                . 'recognised.',

            'Your personal reference is ' . trim((string) $invite->reference) . '. It is also '
                . 'the code your guests use: it takes ' . InviteAudience::discountPercent()
                . '% off their tickets, for up to ' . (int) $invite->guest_quota . ' of them'
                . ($lowestTier !== null
                    ? ', from ₦' . number_format((int) $lowestTier->price_naira)
                      . ' (' . trim((string) $lowestTier->name) . ') upwards'
                    : '')
                . '. Share it freely with them.',

            'Your own entry is arranged and needs no ticket. The email that carried this '
                . 'letter has a link to your pass — open it on your phone when you arrive '
                . 'and the door will be expecting you.',

            'We would be honoured to have you with us.',
        ];

        // ── MEASURED BEFORE IT IS DRAWN ──────────────────────────────────────
        //
        // The reference block and the sign-off are at fixed positions (see REF_Y), so the
        // body is the thing that has to fit. It is an operator's 240-character sentence
        // plus a name of up to 160, and the first version of this simply flowed and then
        // pushed the block down when it ran long — which printed "Yours sincerely," across
        // the middle of the reference on the longest letter the console will accept.
        //
        // So the height is worked out first, against the real face, and the leading
        // tightens if it has to. Tightening is the right thing to give: a letter set a
        // half-millimetre closer is a letter, and one with the close printed over the
        // reference is waste paper. Nothing is ever cut — these are somebody's words about
        // somebody's life's work.
        $lead = 5.4;
        $gap  = 5.4;
        $room = self::REF_Y - 8.0 - $y;

        // The line count does not change with the leading, so it is measured once.
        // `lines()` returns a COUNT, not the lines — same 8-line cap the draw uses, so the
        // measurement and the drawing cannot disagree about a long paragraph.
        $rows = 0;
        foreach ($body as $para) $rows += $pdf->lines($para, $mw, 'text', 10.5, 8);

        // Bounded by the FLOORS, not by a fixed number of passes. The first cut of this
        // ran six iterations, which cannot exhaust both ranges — so a letterhead that grew
        // by six millimetres was enough to leave it still overrunning, silently, with the
        // close printed over the reference. 4.6mm at 10.5pt is 1.24 leading: tight, still
        // comfortably readable, and where this stops giving.
        while ($rows * $lead + count($body) * $gap > $room) {
            if ($lead > 4.6)     { $lead = max(4.6, $lead - 0.1); continue; }
            if ($gap  > 3.0)     { $gap  = max(3.0, $gap  - 0.2); continue; }
            break;   // Both at their floor. The guard test is what says this is enough.
        }

        foreach ($body as $para) {
            $y = $pdf->paragraph($para, $x, $y + $gap, $mw, 'text', 10.5, $lead, self::INK, 8);
        }

        // ── 6 · the reference, set apart ─────────────────────────────────────
        // A hairline rule above and below rather than a gold box: a bordered panel in the
        // middle of a letter is a coupon, and this is the line a steward reads off the
        // page when somebody's phone is dead.
        //
        // ANCHORED to the foot with the sign-off rather than flowed after the body, and
        // that is the one-page guarantee. Flowing it means the length of the body decides
        // where it lands — and the body's length is an operator's 240-character sentence
        // plus a name, neither of which anybody previews. Anchored, a long letter runs
        // toward a fixed block instead of pushing it off the page, and the only failure
        // left is a collision that {@see \Tests\Unit\InviteMailerTest} measures against
        // the longest input the settings screen will accept.
        $y = self::REF_Y;
        $pdf->line($x, $y, $x + $mw, $y, self::RULE, 0.3);
        $pdf->text('YOUR REFERENCE AND GUEST CODE', $x, $y + 6.4, 'mono', 7.0, self::MUTE, 1.4);
        $pdf->text(trim((string) $invite->reference), $x, $y + 14.0, 'mono', 13.0, self::INK, 1.2);
        $quota = (int) $invite->guest_quota . ' guests · ' . InviteAudience::discountPercent() . '% off';
        $pdf->text($quota, $x + $mw - $pdf->width($quota, 'text', 9.0), $y + 14.0,
                   'text', 9.0, self::MUTE);
        $y += 18.5;
        $pdf->line($x, $y, $x + $mw, $y, self::RULE, 0.3);

        // ── 7 · the close, and somewhere to sign ─────────────────────────────
        //
        // The missing formality, and the loudest one. A letter of invitation that nobody
        // has signed is a printout; the space for a signature is what makes the difference
        // whether or not anybody ever writes in it. The rule is 62mm — a signature's
        // width, not the measure's.
        //
        // Anchored to the page foot rather than flowing after the body, so a long name or
        // a long operator sentence pushes the letter rather than the signature, and the
        // close sits where a reader expects it on every copy.
        $signY = self::SIGN_Y;
        $pdf->text('Yours sincerely,', $x, $signY, 'text', 10.5, self::INK);
        $pdf->line($x, $signY + 18.0, $x + 62.0, $signY + 18.0, self::RULE, 0.3);
        $pdf->text('For and on behalf of Africa GATES', $x, $signY + 23.4, 'semi', 9.5, self::INK);
        $pdf->text('Continental Cultural Recognition · An Afrovanguard Initiative',
                   $x, $signY + 28.6, 'text', 8.0, self::MUTE);

        // ── 8 · the foot ─────────────────────────────────────────────────────
        $footY = self::PAGE_H - self::MARGIN;
        $pdf->line($x, $footY - 6.0, $x + $w, $footY - 6.0, self::RULE, 0.3);
        $foot = 'This invitation is personal to ' . trim((string) $invite->name)
              . ' and carries the reference above.';
        $pdf->text($foot, $x, $footY, 'text', 7.5, self::MUTE);
        $pdf->text($host, $x + $w - $pdf->width($host, 'text', 7.5), $footY, 'text', 7.5, self::MUTE);

        return $pdf->output();
    }

    /** The filename an operator and a recipient both want to see. */
    public static function fileName(object $invite): string
    {
        $stem = \AfricaGates\Support\Slug::make((string) $invite->name, 40);

        return 'africa-gates-invitation-' . ($stem !== '' ? $stem . '-' : '')
             . strtolower((string) $invite->reference) . '.pdf';
    }

    private static function begin(): Pdf
    {
        return Letterhead::pdf();
    }
}
