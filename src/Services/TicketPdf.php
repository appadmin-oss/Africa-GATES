<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Pdf;
use AfricaGates\Support\Qr;

/**
 * The ticket as a fixed artefact: a PDF, in millimetres, identical on every machine.
 *
 * ── WHY NOT THE BROWSER'S PRINT DIALOGUE ─────────────────────────────────────
 *
 * `@media print` hands the final document to the browser and the driver. Page size, margins,
 * whether fills print at all, and — the one that actually breaks things — the scale factor
 * are all somebody else's settings. "Fit to page" is on by default in more than one browser,
 * and it silently rescales a QR symbol below the module size a scanner can resolve. Nobody
 * discovers that in an office. They discover it at a gate with a queue behind them.
 *
 * A PDF is the same 30mm on every machine, and it is a FILE: it can be emailed to a guest
 * who has no printer, archived against a dispute, or sent to a print shop that will not
 * accept a web page.
 *
 * ── AND WHY THE TYPE IS SET IN A FACE THE SITE DOES NOT OTHERWISE USE ────────
 *
 * The brand face is DM Sans, and the copy bundled here has no Ọ, ọ, Ẹ, ẹ, Ṣ, ṣ and no ₦. On
 * a platform whose whole subject is African names that is not a cosmetic gap: a ticket for
 * Ọlásùnkànmí Ṣẹ́gun set in it comes out with holes through the middle of somebody's name,
 * and the price loses its currency. So the PDF sets text in a face that covers every African
 * Latin orthography, and {@see Pdf::font()} still takes a fallback chain so a brand face can
 * be introduced later without any of this changing.
 *
 * ── WHAT A TICKET HAS TO SURVIVE ─────────────────────────────────────────────
 *
 * A monochrome laser. A photocopier. Being folded into a back pocket. So nothing on it
 * depends on a fill: the accent is one rule along the top edge, and every fact — the code,
 * the holder, the date, the venue, the reference — is dark type on white.
 */
final class TicketPdf
{
    /** A4, in millimetres. */
    private const PAGE_W = 210.0;
    private const PAGE_H = 297.0;
    private const MARGIN = 8.0;

    /**
     * The QR's printed size.
     *
     * The one measurement in this class that is a physical fact rather than a taste. The
     * symbol is 21 modules plus a 4-module quiet zone, so 30mm puts a module at just over
     * 1mm — comfortably above what a phone camera needs and above what the cheap laser
     * scanners a venue actually owns need. It does not scale with the layout, ever.
     */
    private const QR_MM = 30.0;

    /** The 1A palette, taken from the design rather than from the site's tokens. */
    private const INK    = [16, 41, 44];      // #10292C — all dark type, and the notches
    private const MUTE   = [61, 71, 73];
    private const LINE   = [185, 196, 195];
    private const GOLD   = [243, 180, 22];    // #f3b416 — the panel ground and the perforation
    private const CREAM  = [246, 242, 230];   // #f6f2e6 — the stub stock
    private const IVORY  = [251, 246, 230];   // #fbf6e6 — type over the artwork
    private const LABEL  = [169, 174, 177];   // #a9aeb1 — the stub's micro-labels
    private const BLACK  = [0, 0, 0];

    /**
     * The two densities that fit A4 without leaving a hole in every ticket.
     *
     * The festival stub is LANDSCAPE and wide — an artwork panel, a perforation and a
     * portrait stub — so it does not tile into columns. Three full-width tickets fill an A4
     * at 194 × 93mm each, which is close to the proportions of a real festival ticket.
     *
     * Two is not three enlarged. It is the ticket somebody keeps, at half an A4, with the
     * type at the size of an invitation.
     */
    public const LAYOUTS = [2 => 'Two per page — large', 3 => 'Three per page'];

    /**
     * One attendee's ticket, alone on a page.
     *
     * @param array<string,mixed> $reg
     * @param array<string,mixed> $event
     */
    public static function one(array $reg, array $event, array $design, string $url = ''): string
    {
        $pdf = self::begin();
        // Centred, and at the width of the thing people expect to tear rather than stretched
        // to the paper. A ticket that fills A4 reads as a poster.
        // 180 × 82mm. The design is 2.67:1 and this is 2.2:1 — the difference is the stub,
        // which has to be tall enough to hold a 30mm symbol above a column of labels. That is
        // the one proportion physics takes back.
        //
        // It was 190 wide, which is exactly A4's printable width at a 10mm margin — so the
        // corner marks had nowhere to sit and the browser's own print path, now matched to
        // this card, had its cut line clipped off the edge of the paper. 180 leaves 15mm
        // each side on A4 and 18mm on Letter, which is room for the marks and for the fact
        // that plenty of printers cannot reach within 5mm of an edge at all.
        //
        // The HEIGHT is untouched at 86. Paper is only short in one direction and the stub
        // spends every millimetre of the other: taking 4mm off it put the venue address
        // back to `88 COLLEGE ROAD, NYSC BUS…`, with the town — the half somebody actually
        // navigates by — on the floor.
        $w = 180.0;
        $h = 86.0;
        $x = (self::PAGE_W - $w) / 2;
        $y = 46.0;
        self::ticket($pdf, $reg, $event, $design, $x, $y, $w, $h, true, $url);

        // ── A CUT LINE, BECAUSE THE PAGE IS NOT THE TICKET ──────────────────
        //
        // A ticket floating in the middle of a blank A4 with nothing else on it is a page
        // people fold rather than cut, and a folded ticket goes through a scanner creased
        // across the symbol. Four corner marks and one line of instruction is the whole of
        // it — a full dashed rectangle would print a border around the artwork and read as
        // part of the design.
        $m = 4.0;                                            // how far the marks stand off
        $t = 5.0;                                            // how long each arm is
        foreach ([[$x - $m, $y - $m, 1, 1], [$x + $w + $m, $y - $m, -1, 1],
                  [$x - $m, $y + $h + $m, 1, -1], [$x + $w + $m, $y + $h + $m, -1, -1]] as [$cx, $cy, $dx, $dy]) {
            $pdf->line($cx, $cy, $cx + $t * $dx, $cy, self::LINE, 0.25);
            $pdf->line($cx, $cy, $cx, $cy + $t * $dy, self::LINE, 0.25);
        }

        $note = 'Cut along the corner marks. At the door the stub tears off along the perforation — '
              . 'or just show the code on your phone.';
        $pdf->paragraph($note, $x, $y + $h + 14.0, $w, 'text', 8.0, 4.4, self::MUTE, 2);

        return $pdf->output();
    }

    /**
     * A box-office sheet: every confirmed ticket, laid out to be printed and cut.
     *
     * @param array<int,array<string,mixed>> $regs
     * @param array<string,mixed>            $event
     */
    public static function sheet(array $regs, array $event, array $design, int $per = 3): string
    {
        $per = in_array($per, [2, 3], true) ? $per : 3;
        $pdf = self::begin();

        // One column, always. The stub is wider than it is tall by a factor of two, so a
        // two-column sheet would print tickets the shape of a postage stamp.
        $cols = 1;
        $rows = $per;
        $cw   = (self::PAGE_W - self::MARGIN * 2) / $cols;
        $ch   = (self::PAGE_H - self::MARGIN * 2) / $rows;

        $i = 0;
        foreach ($regs as $reg) {
            $slot = $i % $per;
            if ($slot === 0) $pdf->addPage();

            $x = self::MARGIN + ($slot % $cols) * $cw;
            $y = self::MARGIN + intdiv($slot, $cols) * $ch;

            // The cut line, drawn on the cell rather than the ticket, so what is left after
            // the guillotine has no border printed on it.
            $pdf->frame($x, $y, $cw, $ch, self::LINE, 0.15, [1.2, 1.2]);
            self::ticket($pdf, $reg, $event, $design, $x + 2.5, $y + 2.5, $cw - 5, $ch - 5, $per === 2);
            $i++;
        }
        if ($i === 0) {
            $pdf->addPage();
            $pdf->text('No confirmed tickets to print.', self::MARGIN, 30, 'text', 12, self::MUTE);
        }
        return $pdf->output();
    }

    // ─────────────────────────────── drawing ────────────────────────────────

    private static function begin(): Pdf
    {
        $pdf = new Pdf(self::PAGE_W, self::PAGE_H);
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';

        // Registered in this order so `text` is the default: every call site that forgets to
        // name a face gets the one that can set an African name.
        $pdf->font('text', $dir . 'AGText-Regular.ttf');
        $pdf->font('bold', $dir . 'AGText-Bold.ttf');
        $pdf->font('mono', $dir . 'AGMono-Bold.ttf');

        // The display face the design is drawn in. Playfair covers U+1EA0–1EFF, so it sets
        // "Ogidì Ọmọ" properly — but it has no ₦, which is why it is registered with the text
        // face behind it rather than alone. A title that dropped its currency symbol would be
        // the same defect this whole font path exists to fix.
        $pdf->font('display', $dir . 'PlayfairDisplay-Bold.ttf', 'bold');
        return $pdf;
    }

    /**
     * ONE TICKET — the festival stub.
     *
     * ── THE SHAPE IS THE POINT ───────────────────────────────────────────────
     *
     * A wide artwork panel, a perforation with two notches, and a portrait stub carrying the
     * facts. It is the anatomy of every festival and cinema ticket ever printed, and that
     * convention IS the usability: a door team knows which end to take without being told,
     * and a guest knows which half is theirs.
     *
     * The tier is set enormous and pale across the artwork. On a door in poor light, at two
     * metres, that word is the only thing anybody needs to read off a ticket — it is what
     * sorts a queue — and it does that job long before the 8pt label beside the QR does.
     *
     * ── AND EVERY FACT STILL SITS ON WHITE ───────────────────────────────────
     *
     * The artwork is a photograph over a wash. The stub is unprinted stock with dark type.
     * A driver that drops fills, a monochrome laser, a photocopy: all of them take the
     * artwork away and none of them take away a single thing the door has to read.
     *
     * @param array<string,mixed> $reg
     * @param array<string,mixed> $event
     */
    private static function ticket(Pdf $pdf, array $reg, array $event, array $design,
                                   float $x, float $y, float $w, float $h,
                                   bool $large = false, string $url = ''): void
    {
        $s    = $large ? 1.18 : 1.0;
        $tier = mb_strtoupper(trim((string) ($reg['tier'] ?? '')));

        // ── PROPORTIONS, AND THE ONE PLACE THEY GIVE ────────────────────────
        //
        // The design is 940 × 352 with a stub a third of the width. Those proportions assume
        // the QR is decorative, which on paper it is not: 30mm is the size below which the
        // scanners a venue actually owns start hunting for the symbol. So the stub is widened
        // to fit a real one and the ticket is a little taller than 2.67:1. Everything else —
        // the gold ground, the wash, the perforation, the slanted tier, the label column, the
        // scan block at the top right — is as drawn.
        $tearW = 2.2;
        $stubW = max(self::QR_MM + 30, $w * 0.38);
        $artW  = $w - $stubW - $tearW;
        $tearX = $x + $artW;
        $stubX = $tearX + $tearW;

        // ── the artwork panel ───────────────────────────────────────────────
        //
        // Gold underneath, always. The photograph sits on top of it and the wash on top of
        // that, so an event with no artwork loses a photograph and keeps the design.
        $pdf->rect($x, $y, $artW, $h, self::GOLD);

        $wash = self::rgb((string) ($design['accent'] ?? '#2a0a4a'));
        $art  = self::artwork($design);
        // `0.5`, not the 0.28 this used to bias to. That number was there to keep faces in
        // frame on an uncropped portrait poster — the same guess `object-position:50% 32%`
        // makes in the stylesheet, and the same one TicketArtwork now removes the need for.
        // A framed image ALREADY is the crop the organiser chose; shifting it up by a fifth
        // of the difference is this file overruling them.
        if ($art !== null) $pdf->image($art, $x, $y, $artW, $h, 0.5);

        // Bottom-up over 62% of the height, at the design's own stops. It is what makes a
        // title legible over a photograph nobody chose, so it is drawn even on light artwork
        // — "nobody chose" is the operative part.
        $pdf->wash($x, $y + $h * 0.38, $artW, $h * 0.62, $wash);

        $pdf->pushClip($x, $y, $artW, $h);

        // The tier, slanted, ivory at 40%, running off its own panel. At two metres in bad
        // light this is the only thing anybody reads off a ticket — it is what sorts a queue —
        // and it does that job long before the 5pt label beside the QR does.
        //
        // Its baseline sits 10mm up rather than 5mm: the foot of the panel is now carrying
        // the recovery address, and the tier's descenders were landing on it.
        if ($tier !== '') {
            $pdf->text($tier, $x + 7 * $s, $y + $h - 10 * $s, 'display', 30 * $s,
                self::IVORY, 0, 0.40, 0.21);
        }

        // ── THE ADDRESS THAT GETS A LOST TICKET BACK, ON THE TICKET ─────────
        //
        // This used to be set on the PAGE, six millimetres below the card. Cut the ticket
        // out — which is exactly what the dashed line asks you to do — and the one line
        // that recovers it is on the offcut. So it moves inside, onto the foot of the
        // artwork panel, which is the only place on the whole ticket with 100mm of clear
        // width: in the 60mm stub the same string sets at 3.5pt or wraps across three
        // lines, and a URL with its tail missing looks like an address and is not one.
        //
        // Ivory over the foot of the wash, which is its opaque end — the same guarantee the
        // title relies on, so it is legible over a photograph nobody chose.
        //
        // The scheme is dropped. `https://` is eleven characters of a line that has about
        // ninety to spend, and nobody has typed it into a phone in a decade.
        if ($url !== '') {
            $pdf->text((string) preg_replace('#^https?://#i', '', $url),
                $x + 7 * $s, $y + $h - 3.4 * $s, 'mono', 5 * $s, self::IVORY, 200, 0.72);
        }

        $ax = $x + 7 * $s;
        $aw = $artW - 14 * $s;
        $when = trim((string) ($event['event_date'] ?? ''));
        $ts   = $when !== '' ? (strtotime($when) ?: time()) : 0;

        // The title sits above the tier, and the kicker above that — the design's stack.
        $titleBase = $y + $h - 26 * $s;
        $pdf->paragraph((string) ($event['title'] ?? 'Event'), $ax, $titleBase, $aw,
            'display', 17 * $s, 7 * $s, self::IVORY, 2);

        if ($ts > 0) {
            $city = mb_strtoupper(trim((string) ($event['city'] ?? '')));
            // Gold, over the darkest part of the wash. Set above the title it was invisible:
            // the wash is weak that high up, and gold type on a gold panel is not type.
            $pdf->text(date('d.m.y', $ts) . ($city !== '' ? '  ·  ' . $city : ''),
                $ax, $titleBase + 5.5 * $s, 'mono', 6 * $s, self::GOLD, 220);
        }

        $pdf->popClip();

        // ── the perforation ─────────────────────────────────────────────────
        //
        // Gold, like the panel it divides, with a dashed rule down the middle and a notch
        // punched out at each end. The notches are drawn in the PAGE colour so they read as
        // holes through the ticket rather than as two dots printed on it.
        $pdf->rect($tearX, $y, $tearW, $h, self::GOLD);
        $pdf->line($tearX + $tearW / 2, $y + 2.4, $tearX + $tearW / 2, $y + $h - 2.4,
            self::INK, 0.3, [1.1, 1.1]);
        $pdf->rect($tearX - 1.2, $y - 0.2, $tearW + 2.4, 1.7, [255, 255, 255]);
        $pdf->rect($tearX - 1.2, $y + $h - 1.5, $tearW + 2.4, 1.7, [255, 255, 255]);

        // ── the stub ────────────────────────────────────────────────────────
        $pdf->rect($stubX, $y, $stubW, $h, self::CREAM);

        $pad  = 5 * $s;
        $sx   = $stubX + $pad;
        $sw   = $stubW - $pad * 2;
        $codeW = 6 * $s;                                   // the vertical serial's own strip

        // The scan block, top right, with the code reading up its edge — the design's
        // arrangement, at a size a scanner can resolve.
        $qrX = $stubX + $stubW - $pad - $codeW - self::QR_MM;
        $qrY = $y + $pad;
        // On the stub's own cream, not on a white patch. Cream is 93% luminance — far above
        // what a decoder needs against black modules — and a white square on cream stock is
        // a hole in the design that no printer put there.
        $pdf->qr(self::matrix($reg), $qrX, $qrY, self::QR_MM, 4, self::CREAM);

        $code = trim((string) ($reg['ticket_code'] ?? '')) ?: (string) ($reg['reference'] ?? '—');
        $pdf->textUp($code, $qrX + self::QR_MM + 4.4 * $s, $qrY + self::QR_MM,
            'mono', 6 * $s, self::INK, 200);

        // EVENT sits to the LEFT of the symbol, in the column the design leaves for it.
        $pdf->text('EVENT', $sx, $qrY + 3 * $s, 'mono', 5 * $s, self::LABEL, 220);
        $pdf->paragraph(mb_strtoupper((string) ($event['title'] ?? '')), $sx, $qrY + 7 * $s,
            $qrX - $sx - 3, 'bold', 6.6 * $s, 3.2 * $s, self::INK, 3);

        // ── the label column, below the scan block ──────────────────────────
        //
        // The holder's name is RESERVED above the footer rather than flowed with the rest. It
        // is the field a door checks against the person in front of it, and the first cut let
        // a three-line address push it off the bottom — the ticket still looked complete,
        // which is the dangerous part.
        // ── THE FOUR MILLIMETRES THE ADDRESS NEEDED ─────────────────────────
        //
        // Between the scan block and the reserved holder there are about 40mm, and the
        // fields that have to go in them are the date (label + one line) and the venue
        // (label + two). Measured, the column was ~4mm short, so the address printed as
        // `88 COLLEGE ROAD, NYSC BUS STOP…` — with the town cut off, which is the half of
        // an address somebody actually needs to find the place.
        //
        // Nothing is removed to pay for it. It comes from three gaps that were larger than
        // they had to be: the air under the symbol, the gap between fields, and the clear
        // space above the holder's label. None is now below 1.5mm, which is still more
        // separation than the 5pt labels need to read as separate rows.
        $footY   = $y + $h - $pad;
        $footH   = 7.0 * $s;
        $nameY   = $footY - $footH - 2.8 * $s;
        $nameLab = $nameY - 3.0 * $s;
        $limit   = $nameLab - 1.4 * $s;
        $sy      = $qrY + self::QR_MM + 3.2 * $s;

        // ── A FIELD SHRINKS BEFORE IT DISAPPEARS ────────────────────────────
        //
        // This used to ask for the field's full height and give up entirely if it did not
        // fit. On a real ticket that meant the venue address — asking for three lines —
        // vanished while the one-line TICKET TYPE below it printed happily into the space
        // the address had just been refused. The ticket looked complete and had no address
        // on it, which is the field that cannot be reconstructed from any of the others.
        //
        // So the question changes from "does this fit?" to "how much of this fits?". The
        // room left is turned into a line count, the paragraph is set to that, and a field
        // is only skipped when there is not room for even ONE line — at which point nothing
        // was going to help. The tail of a long address is the right thing to lose, and it
        // is the only thing that is lost.
        $field = static function (string $label, string $value, int $maxLines = 2) use (
            $pdf, $sx, $sw, $s, $limit, &$sy
        ): void {
            if (trim($value) === '') return;

            $lead = 3.2 * $s;                                 // one line of the value
            $room = $limit - $sy - 3.0 * $s - 1.5 * $s;       // minus the label and the gap
            $fit  = (int) floor(($room + 0.01) / $lead);
            if ($fit < 1) return;

            $use = min($maxLines, $fit, max(1, $pdf->lines($value, $sw, 'bold', 6.8 * $s, $maxLines)));

            $pdf->text($label, $sx, $sy, 'mono', 5 * $s, self::LABEL, 220);
            $sy += 3.0 * $s;
            $sy  = $pdf->paragraph($value, $sx, $sy, $sw, 'bold', 6.8 * $s, $lead,
                self::INK, $use);
            $sy += 1.5 * $s;
        };

        $where = array_values(array_filter([
            trim((string) ($event['venue'] ?? '')),
            trim((string) ($event['location'] ?? '')),
        ]));
        $paid  = (int) ($reg['amount_naira'] ?? 0) > 0;
        $money = in_array('price', (array) ($design['rows'] ?? []), true)
            ? ($paid ? '₦' . number_format((int) $reg['amount_naira']) : 'FREE') : '';

        // ── WHEN, THEN WHERE, THEN THE REST ─────────────────────────────────
        //
        // The date goes FIRST, and that is a correction rather than a preference. The order
        // used to be address-then-date, on the reasoning that the artwork panel already
        // carries the date as its kicker — and the result, measured on a real ticket, was
        // that a two-line address left the date needing 9.6mm of a 7.5mm gap and `$field`
        // silently dropped it. The ticket printed with an empty band where the date should
        // be and no indication anything was missing, which is the worst way for a document
        // to fail: a person at a gate on the wrong evening with a ticket that looks complete.
        //
        // The kicker is not a substitute. It is `05.09.26` at 6pt in gold over a photograph,
        // with no day name and no time — a mark of identity, not a fact somebody plans
        // around. And "doors at 5" is exactly what people get wrong.
        //
        // So the two fields nobody can reconstruct — when and where — are drawn before
        // anything optional, and the address gives up its third line rather than the date
        // giving up its existence.
        if ($ts > 0) $field('DATE', strtoupper(date('D d M Y', $ts)) . '  ·  ' . date('g:i a', $ts), 2);
        $field('LOCATION', $where !== [] ? mb_strtoupper(implode(', ', $where)) : '', 3);
        $field('TICKET TYPE', trim(($tier !== '' ? $tier : 'ADMIT ONE')
            . ($money !== '' ? '   ' . $money : '')), 1);
        $field('SEAT', mb_strtoupper(trim((string) ($reg['seat_label'] ?? ''))), 1);

        // Reserved, and therefore always drawn.
        $pdf->text('HOLDER', $sx, $nameLab, 'mono', 5 * $s, self::LABEL, 220);
        $pdf->paragraph((string) ($reg['name'] ?? '—'), $sx, $nameY, $sw, 'bold',
            7.4 * $s, 3.4 * $s, self::INK, 1);

        // ── the foot: the mark, and the address of the site ─────────────────
        // ── THE MARK IS SET, NOT PLACED ─────────────────────────────────────
        //
        // The bundled logo is a PNG built for a dark ground. Composited onto cream at 8mm it
        // printed as a pale disc with unreadable text inside it — and a smudged mark on a
        // document carrying somebody's identity reads as a forgery rather than as a brand.
        // The wordmark in the display face is crisp at any size a printer can resolve, which
        // is the job the mark was there to do.
        $pdf->text('AFROVANGUARD', $sx, $footY - $footH + 1.5 * $s, 'mono', 4.6 * $s,
            self::LABEL, 220);

        $pdf->text('AFRICA GATES', $sx, $footY, 'display', 8 * $s, self::INK, 40);

        $ref = (string) ($reg['reference'] ?? '');
        if ($ref !== '') {
            $rw2 = $pdf->width($ref, 'mono', 4.6 * $s, 40);
            $pdf->text($ref, $stubX + $stubW - $pad - $rw2, $footY, 'mono',
                4.6 * $s, self::LABEL, 40);
        }
    }

    /**
     * The event's artwork as JPEG bytes, or null.
     *
     * ── WHY IT IS TRANSCODED RATHER THAN EMBEDDED AS FOUND ───────────────────
     *
     * Organisers upload PNGs and four-megabyte posters. PDF speaks JPEG natively and nothing
     * else worth implementing here, and a 2160 × 2700 poster on a 100mm panel is fifty times
     * the pixels a printer can use — which is a ticket file nobody can email. GD is asked for
     * a JPEG at roughly 300dpi for the panel and no more.
     *
     * Every failure returns null and the panel falls back to flat gold. A ticket without its
     * photograph is a ticket; a ticket that failed to render is not.
     */
    private static function artwork(array $design): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;

        // ── LOCAL FILES ONLY, WHICH IS NOT THE SAME AS "NOT A CDN URL" ──────
        //
        // Fetching a remote URL here is still refused: it would put a third party's server
        // in the path of a ticket download, and a slow host would hang the request.
        //
        // What changed is what happens next. This used to `return null` the moment the
        // stored URL began `https://`, and with Cloudinary configured EVERY stored URL does
        // — so on those deployments every ticket printed with no photograph, silently,
        // while the file sat on disk the whole time. UploadService writes locally first and
        // records where in `gates_uploads.local_path`; LocalMedia is the read of it.
        //
        // The originals are tried in turn rather than only the delivered image: `image` is
        // the 3:2 crop the organiser framed and is what should print, and `ticket_image_src`
        // is the master behind it — worth falling back to if the crop is ever missing from
        // disk, because a ticket with the whole poster beats a ticket with a gold rectangle.
        $path = '';
        foreach ([(string) ($design['image'] ?? ''), (string) ($design['image_src'] ?? '')] as $candidate) {
            if (trim($candidate) === '') continue;
            $path = \AfricaGates\Support\LocalMedia::file($candidate);
            if ($path !== '') break;
        }
        if ($path === '' || filesize($path) > 12 * 1024 * 1024) return null;

        try {
            $im = @imagecreatefromstring((string) file_get_contents($path));
            if ($im === false) return null;

            $w = imagesx($im);
            $h = imagesy($im);
            $target = 1600;                                     // ~300dpi across a 135mm panel
            if ($w > $target) {
                $scaled = imagescale($im, $target, (int) round($h * $target / $w));
                if ($scaled !== false) { imagedestroy($im); $im = $scaled; }
            }

            ob_start();
            imagejpeg($im, null, 82);
            $bytes = (string) ob_get_clean();
            imagedestroy($im);

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The QR modules, or an empty matrix.
     *
     * @return array<int,array<int,bool>>
     */
    private static function matrix(array $reg): array
    {
        $code = trim((string) ($reg['ticket_code'] ?? ''));
        if ($code === '') return [];
        return Qr::encode($code) ?? [];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return self::INK;
        return [(int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2))];
    }
}
