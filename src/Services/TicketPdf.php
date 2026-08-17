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

    private const INK   = [16, 41, 44];
    private const MUTE  = [61, 71, 73];
    private const LINE  = [185, 196, 195];
    private const BLACK = [0, 0, 0];

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
        // 180 × 78mm, which is a shade over the proportions of the design and a shade under
        // the width of A4 — a ticket that fills the paper edge to edge reads as a poster.
        $w = 180.0;
        $h = 78.0;
        self::ticket($pdf, $reg, $event, $design, (self::PAGE_W - $w) / 2, 45.0, $w, $h, true, $url);
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
        $accent = self::rgb((string) ($design['accent'] ?? '#2a0a4a'));
        $s      = $large ? 1.35 : 1.0;

        // The stub is sized from the QR outward, because the QR does not scale: it is 30mm
        // whatever else happens, so the panel that holds it cannot be narrower than 30mm plus
        // its margins. Everything else gets the remainder.
        $stubW = max(self::QR_MM + 22, $w * 0.34);
        $tearW = 2.6;
        $artW  = $w - $stubW - $tearW;
        $artX  = $x;
        $tearX = $x + $artW;
        $stubX = $tearX + $tearW;

        // ── the artwork panel ───────────────────────────────────────────────
        $pdf->rect($artX, $y, $artW, $h, $accent);
        $art = self::artwork($design);
        if ($art !== null) {
            $pdf->image($art, $artX, $y, $artW, $h, 0.3);
            // The wash, which is what makes a title legible over an unknown photograph. Kept
            // even on light artwork, because "unknown" is the operative word: an organiser
            // uploads whatever they have and the type has to survive all of it.
            $pdf->rect($artX, $y + $h * 0.42, $artW, $h * 0.58, $accent, 0.86);
            $pdf->rect($artX, $y + $h * 0.62, $artW, $h * 0.38, $accent, 0.97);
        }

        $tier = strtoupper(trim((string) ($reg['tier'] ?? '')));
        if ($tier !== '') {
            // Pale, enormous, and clipped by the panel it sits in — a watermark rather than a
            // label. 22% is the alpha at which it reads as a texture up close and as a word
            // across a room, which is the only distance it has to work at.
            $pdf->text($tier, $artX + 8 * $s, $y + $h - 5 * $s, 'bold', 34 * $s,
                [255, 255, 255], 0, 0.22);
        }

        $ax = $artX + 8 * $s;
        $aw = $artW - 16 * $s;
        $ay = $y + $h - 24 * $s;

        $when = trim((string) ($event['event_date'] ?? ''));
        if ($when !== '') {
            $ts = strtotime($when) ?: time();
            $kicker = strtoupper(date('d.m.y', $ts));
            $city   = strtoupper(trim((string) ($event['city'] ?? '')));
            $pdf->text($kicker . ($city !== '' ? ' · ' . $city : ''), $ax, $ay - 9 * $s,
                'mono', 6.5 * $s, [243, 180, 22], 180);
        }
        $pdf->paragraph((string) ($event['title'] ?? 'Event'), $ax, $ay, $aw,
            'bold', 15 * $s, 6.4 * $s, [251, 246, 230], 2);

        // ── the perforation ─────────────────────────────────────────────────
        $pdf->rect($tearX, $y, $tearW, $h, [246, 242, 230]);
        $pdf->line($tearX + $tearW / 2, $y + 2, $tearX + $tearW / 2, $y + $h - 2,
            [140, 150, 150], 0.25, [1.1, 1.1]);
        // The notches are drawn in the PAGE colour, so they read as holes punched through the
        // ticket rather than as two dots printed on it.
        $pdf->rect($tearX - 1.1, $y - 0.1, $tearW + 2.2, 1.6, [255, 255, 255]);
        $pdf->rect($tearX - 1.1, $y + $h - 1.5, $tearW + 2.2, 1.6, [255, 255, 255]);

        // ── the stub ────────────────────────────────────────────────────────
        $pdf->rect($stubX, $y, $stubW, $h, [246, 242, 230]);

        $pad = 5 * $s;
        $sx  = $stubX + $pad;
        $sw  = $stubW - $pad * 2;

        // ── THE BOTTOM OF THE STUB IS RESERVED, AND MEASURED FIRST ──────────
        //
        // The scan block and the holder's name are the two things a door actually uses, and
        // both are anchored to the foot. Everything above them flows down into whatever is
        // left. Doing it the other way round — flowing from the top and hoping — is what
        // printed a three-line address straight through the word HOLDER: two correct blocks,
        // one on top of the other, which is what a fixed-height panel does every time.
        $footY   = $y + $h - $pad;
        $qrBot   = $footY - 3.5 * $s;
        $qrTop   = $qrBot - self::QR_MM;
        $nameY   = $qrTop - 3.0 * $s;
        $nameLab = $nameY - 3.2 * $s;
        $limit   = $nameLab - 3 * $s;

        $sy = $y + $pad + 2.5 * $s;

        // Label-above-value pairs in the register's own idiom: a tiny tracked mono label over
        // a short bold value, uppercase throughout, because this panel is read at a glance and
        // never as prose.
        //
        // Returns false when the field did not fit, so the caller stops rather than drawing
        // over the block below. The order they are offered in IS the priority order.
        $field = static function (string $label, string $value, int $maxLines = 2) use (
            $pdf, $sx, $sw, $s, $limit, &$sy
        ): bool {
            if (trim($value) === '') return true;

            // Measured, not reserved. Assuming every field needs its maximum dropped the
            // venue address to make room for space that then stayed empty — which is the
            // worst of both, because the address is the one field on a ticket that cannot be
            // reconstructed from the others.
            $used = $pdf->lines($value, $sw, 'bold', 7.2 * $s, $maxLines);
            $need = 3.0 * $s + $used * 3.3 * $s + 1.8 * $s;
            if ($sy + $need > $limit) return false;

            $pdf->text($label, $sx, $sy, 'mono', 5 * $s, [140, 148, 152], 200);
            $sy += 3.0 * $s;
            $sy  = $pdf->paragraph($value, $sx, $sy, $sw, 'bold', 7.2 * $s, 3.3 * $s,
                self::INK, $maxLines);
            $sy += 1.8 * $s;
            return true;
        };

        $where = array_values(array_filter([
            trim((string) ($event['venue'] ?? '')),
            trim((string) ($event['location'] ?? '')),
        ]));

        $paid  = (int) ($reg['amount_naira'] ?? 0) > 0;
        $money = in_array('price', (array) ($design['rows'] ?? []), true)
            ? ($paid ? '₦' . number_format((int) $reg['amount_naira']) : 'FREE') : '';

        // ── PRIORITY ORDER, BECAUSE THE PANEL CAN RUN SHORT ─────────────────
        //
        // LOCATION outranks DATE, and both outrank the EVENT name — because the artwork panel
        // beside this one already carries the title at six times the size and the date as its
        // kicker, while the address appears nowhere else on the ticket. Whichever field falls
        // off the bottom should be the one a guest can find somewhere else.
        //
        // `continue`, not `break`: a field that did not fit is skipped, and a shorter one
        // after it may still fit. Stopping at the first refusal would drop a one-line SEAT
        // because a three-line address did not fit.
        foreach ([
            ['TICKET TYPE', trim(($tier !== '' ? $tier : 'ADMIT ONE') . ($money !== '' ? '   ' . $money : '')), 2],
            ['LOCATION',    $where !== [] ? mb_strtoupper(implode(', ', $where)) : '', 3],
            ['DATE',        $when !== ''
                ? strtoupper(date('D d M Y', strtotime($when) ?: time()))
                  . '  ·  ' . date('g:i a', strtotime($when) ?: time()) : '', 2],
            ['SEAT',        mb_strtoupper(trim((string) ($reg['seat_label'] ?? ''))), 1],
            ['EVENT',       mb_strtoupper((string) ($event['title'] ?? '')), 2],
        ] as [$label, $value, $lines]) {
            $field($label, $value, $lines);
        }

        // ── the holder and the scan block ───────────────────────────────────
        $pdf->text('HOLDER', $sx, $nameLab, 'mono', 5 * $s, [140, 148, 152], 200);
        $pdf->paragraph((string) ($reg['name'] ?? '—'), $sx, $nameY, $sw, 'bold',
            8.4 * $s, 3.8 * $s, self::INK, 1);

        $pdf->qr(self::matrix($reg), $sx, $qrTop, self::QR_MM);

        $code = trim((string) ($reg['ticket_code'] ?? '')) ?: (string) ($reg['reference'] ?? '—');
        // Down the right-hand edge of the symbol, which is where a stub has always carried its
        // serial — and it keeps the code beside the thing it encodes without stealing a line.
        $pdf->textUp($code, $sx + self::QR_MM + 3.6, $qrBot, 'mono', 6.2 * $s, self::INK, 120);

        $pdf->text('AFROVANGUARD', $sx, $footY, 'mono', 5 * $s, [140, 148, 152], 200);
        $ref = (string) ($reg['reference'] ?? '');
        $rw  = $pdf->width($ref, 'mono', 5 * $s, 40);
        $pdf->text($ref, $stubX + $stubW - $pad - $rw, $footY, 'mono', 5 * $s, [140, 148, 152], 40);

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
     * Organisers upload PNGs and 4MB posters. PDF speaks JPEG natively and nothing else that
     * is worth implementing here, and a 2160 × 2700 poster on a 60mm panel is fifty times the
     * pixels a printer can use — which is a ticket file nobody can email. GD is asked for a
     * JPEG at roughly 300dpi for the panel and no more.
     *
     * Every failure returns null and the panel falls back to flat accent. A ticket without its
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
            // ~300dpi across a panel that is never wider than 130mm.
            $target = 1600;
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
