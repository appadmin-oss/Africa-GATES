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
        // 190 × 86mm. The design is 2.67:1 and this is 2.2:1 — the difference is the stub,
        // which has to be tall enough to hold a 30mm symbol above a column of labels. That is
        // the one proportion physics takes back.
        $w = 190.0;
        $h = 86.0;
        self::ticket($pdf, $reg, $event, $design, (self::PAGE_W - $w) / 2, 42.0, $w, $h, true, $url);
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
        if ($art !== null) $pdf->image($art, $x, $y, $artW, $h, 0.28);

        // Bottom-up over 62% of the height, at the design's own stops. It is what makes a
        // title legible over a photograph nobody chose, so it is drawn even on light artwork
        // — "nobody chose" is the operative part.
        $pdf->wash($x, $y + $h * 0.38, $artW, $h * 0.62, $wash);

        $pdf->pushClip($x, $y, $artW, $h);

        // The tier, slanted, ivory at 40%, running off its own panel. At two metres in bad
        // light this is the only thing anybody reads off a ticket — it is what sorts a queue —
        // and it does that job long before the 5pt label beside the QR does.
        if ($tier !== '') {
            $pdf->text($tier, $x + 7 * $s, $y + $h - 5 * $s, 'display', 30 * $s,
                self::IVORY, 0, 0.40, 0.21);
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
        $footY   = $y + $h - $pad;
        $footH   = 7.0 * $s;
        $nameY   = $footY - $footH - 2.8 * $s;
        $nameLab = $nameY - 3.0 * $s;
        $limit   = $nameLab - 2.0 * $s;
        $sy      = $qrY + self::QR_MM + 5 * $s;

        // Measured, not reserved: assuming every field needs its maximum dropped the venue
        // address to make room for space that then stayed empty, and the address is the one
        // field on a ticket that cannot be reconstructed from the others.
        $field = static function (string $label, string $value, int $maxLines = 2) use (
            $pdf, $sx, $sw, $s, $limit, &$sy
        ): void {
            if (trim($value) === '') return;
            $used = $pdf->lines($value, $sw, 'bold', 6.8 * $s, $maxLines);
            $need = 3.0 * $s + $used * 3.2 * $s + 1.9 * $s;
            if ($sy + $need > $limit) return;

            $pdf->text($label, $sx, $sy, 'mono', 5 * $s, self::LABEL, 220);
            $sy += 3.0 * $s;
            $sy  = $pdf->paragraph($value, $sx, $sy, $sw, 'bold', 6.8 * $s, 3.2 * $s,
                self::INK, $maxLines);
            $sy += 1.9 * $s;
        };

        $where = array_values(array_filter([
            trim((string) ($event['venue'] ?? '')),
            trim((string) ($event['location'] ?? '')),
        ]));
        $paid  = (int) ($reg['amount_naira'] ?? 0) > 0;
        $money = in_array('price', (array) ($design['rows'] ?? []), true)
            ? ($paid ? '₦' . number_format((int) $reg['amount_naira']) : 'FREE') : '';

        // Priority order for the space that is left: the address outranks the date, and both
        // outrank the ticket type — because the artwork panel already carries the date as its
        // kicker and the type as a word the height of a fist, while the address appears
        // nowhere else on the ticket at all.
        $field('LOCATION', $where !== [] ? mb_strtoupper(implode(', ', $where)) : '', 3);
        if ($ts > 0) $field('DATE', strtoupper(date('D d M Y', $ts)) . '  ·  ' . date('g:i a', $ts), 2);
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

        $site = 'AGATES.ORG';
        $rw   = $pdf->width($site, 'mono', 5 * $s, 100);
        $pdf->text($site, $stubX + $stubW - $pad - $rw, $footY, 'mono', 5 * $s, self::MUTE, 100);

        $ref = (string) ($reg['reference'] ?? '');
        if ($ref !== '') {
            $rw2 = $pdf->width($ref, 'mono', 4.6 * $s, 40);
            $pdf->text($ref, $stubX + $stubW - $pad - $rw2, $footY - 4 * $s, 'mono',
                4.6 * $s, self::LABEL, 40);
        }

        // Paper cannot be clicked, so the link that recovers a lost ticket is set below it.
        if ($url !== '' && $large) {
            $pdf->text($url, $x, $y + $h + 6, 'text', 7, self::MUTE);
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
        $src = trim((string) ($design['image'] ?? ''));
        if ($src === '' || !function_exists('imagecreatefromstring')) return null;

        // Local files only. Fetching a remote URL here would put a third party's server in
        // the path of a ticket download, and a slow host would hang the request.
        if (preg_match('#^https?://#i', $src) === 1) return null;

        $path = dirname(__DIR__, 2) . '/public/' . ltrim($src, '/');
        if (!is_file($path) || !is_readable($path) || filesize($path) > 12 * 1024 * 1024) return null;

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
