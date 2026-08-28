<?php
declare(strict_types=1);
namespace AfricaGates\Services;

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
    private const PAGE_W = 210.0;
    private const PAGE_H = 297.0;
    private const MARGIN = 22.0;

    private const INK   = [0x10, 0x29, 0x2C];
    private const MUTE  = [0x5D, 0x72, 0x75];
    private const GOLD  = [0xF3, 0xB4, 0x16];
    private const RULE  = [0xCD, 0xD6, 0xD6];

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

        $w = self::PAGE_W - self::MARGIN * 2;
        $x = self::MARGIN;
        $y = self::MARGIN;

        // ── masthead ─────────────────────────────────────────────────────────
        $pdf->text('AFRICA GATES', $x, $y + 6.0, 'mono', 9.0, self::GOLD, 1.6);
        $pdf->text('An Afrovanguard Initiative', $x, $y + 11.4, 'text', 8.0, self::MUTE);
        $pdf->text(DisplayTime::show(Carbon::now()->toDateTimeString(), 'j F Y'),
                   $x + $w - $pdf->width(DisplayTime::show(Carbon::now()->toDateTimeString(), 'j F Y'), 'text', 8.0),
                   $y + 11.4, 'text', 8.0, self::MUTE);
        $y += 18.0;
        $pdf->line($x, $y, $x + $w, $y, self::RULE, 0.3);
        $y += 16.0;

        // ── the title ────────────────────────────────────────────────────────
        $pdf->text('An Invitation', $x, $y + 9.0, 'display', 26.0, self::INK);
        $y += 15.0;
        $pdf->text(mb_strtoupper($spec['label'] . ' · guest of honour'), $x, $y + 4.0,
                   'mono', 8.0, self::GOLD, 1.4);
        $y += 14.0;

        // ── the letter ───────────────────────────────────────────────────────
        // Baseline, not a box top: imagettftext and Pdf::text both take a baseline while
        // every rectangle grows downward from its origin, which is how a line of type comes
        // to sit on top of the one above it.
        $y = $pdf->paragraph(
            $spec['salutation'] . ' ' . trim((string) $invite->name) . ',',
            $x, $y + 4.0, $w, 'bold', 11.5, 6.0, self::INK, 2
        );
        $y += 4.0;

        $when  = DisplayTime::showZoned((string) $event->event_date, 'l j F Y \a\t H:i');
        $where = trim(implode(', ', array_filter([
            trim((string) ($event->venue ?? '')),
            trim((string) ($event->location ?? '')),
        ])));

        $body = [
            'It is our privilege to invite you to ' . trim((string) $event->title)
                . ($where !== '' ? ', at ' . $where : '') . ', on ' . $when . '.',

            // The operator's sentence, WHOLE. This was the third place assembling it —
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

        foreach ($body as $para) {
            $y = $pdf->paragraph($para, $x, $y + 6.0, $w, 'text', 10.5, 5.6, self::INK, 8);
        }

        // ── the reference, set apart ─────────────────────────────────────────
        $y += 10.0;
        $boxH = 22.0;
        $pdf->frame($x, $y, $w, $boxH, self::GOLD, 0.4);
        $pdf->text('YOUR REFERENCE AND GUEST CODE', $x + 6.0, $y + 8.0, 'mono', 7.5, self::MUTE, 1.2);
        $pdf->text(trim((string) $invite->reference), $x + 6.0, $y + 17.0, 'mono', 13.0, self::INK, 1.0);
        $quota = (int) $invite->guest_quota . ' guests · ' . InviteAudience::discountPercent() . '% off';
        $pdf->text($quota, $x + $w - 6.0 - $pdf->width($quota, 'text', 9.0), $y + 17.0,
                   'text', 9.0, self::MUTE);

        // ── sign-off, anchored to the page foot ──────────────────────────────
        $footY = self::PAGE_H - self::MARGIN - 20.0;
        $pdf->line($x, $footY - 10.0, $x + $w, $footY - 10.0, self::RULE, 0.3);
        $pdf->text('Africa GATES', $x, $footY - 2.0, 'bold', 10.0, self::INK);
        $pdf->text('Continental Cultural Recognition · An Afrovanguard Initiative',
                   $x, $footY + 4.0, 'text', 8.0, self::MUTE);

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
        $pdf = new Pdf(self::PAGE_W, self::PAGE_H);
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';

        // `text` first so it is the default: every call site that forgets to name a face
        // gets the one that can set an African name.
        $pdf->font('text', $dir . 'AGText-Regular.ttf');
        $pdf->font('bold', $dir . 'AGText-Bold.ttf');
        $pdf->font('mono', $dir . 'AGMono-Bold.ttf');
        // Playfair covers U+1EA0–1EFF so it sets "Ọlásùnkànmí" properly, but it has no ₦ —
        // registered with the text face behind it for exactly that reason. This letter
        // quotes a naira figure.
        $pdf->font('display', $dir . 'PlayfairDisplay-Bold.ttf', 'bold');

        return $pdf;
    }
}
