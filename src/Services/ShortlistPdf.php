<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Pdf;

/**
 * The published shortlist as a document: A4, white, one rule, and the names.
 *
 * ── WHAT "MINIMALIST" HAS TO MEAN HERE ───────────────────────────────────────
 *
 * Not "fewer boxes". The document has one job: somebody who was not in the room reads it
 * and believes it. So everything on the page is either a name, or the evidence behind the
 * names — the rule that drew the line, the field it was drawn from, the date, and who
 * published it. There is no ornament, no gradient, no full-bleed colour, and no watermark.
 *
 * The consequence is that it also survives what actually happens to it: a monochrome laser,
 * a photocopier, a scan attached to an email. Nothing on this page depends on a fill. The
 * accent is the rank numbers and nothing else; every rule is a hairline and every fact is dark
 * type on white.
 *
 * ── WHY IT IS BUILT FROM THE SNAPSHOT AND NEVER FROM A QUERY ─────────────────
 *
 * {@see ShortlistService::entries()} reads frozen rows. If this rendered a live cut instead,
 * two downloads a minute apart could disagree — and the second one would be the one in
 * somebody's inbox contradicting the first. A document that can change after being sent is
 * not a document.
 *
 * ── WHY THE VOTE COUNTS ARE PRINTED ──────────────────────────────────────────
 *
 * The tempting minimal choice is to print only the names, and it is the wrong one. A
 * shortlist without its numbers cannot be checked, and a shortlist that cannot be checked
 * is a claim rather than a record. The numbers are also what make the tie group legible:
 * three names sharing a count explain, without a footnote, why eleven appear under a rule
 * that says ten. The daggered ones get the footnote anyway.
 */
final class ShortlistPdf
{
    private const PAGE_W = 210.0;
    private const PAGE_H = 297.0;
    private const M      = 20.0;      // generous margins are most of what makes it read as considered

    private const INK   = [16, 41, 44];        // #10292C
    private const MUTE  = [96, 108, 110];
    private const FAINT = [150, 160, 161];
    private const RULE  = [214, 221, 220];
    private const GOLD  = [243, 180, 22];

    /** Row pitch, and where the first row's baseline sits on each page. */
    private const ROW_H      = 11.0;
    private const FIRST_ROW  = 96.0;
    private const CONT_ROW   = 52.0;
    private const FOOT_Y     = 276.0;

    /**
     * @param array<string,mixed>       $shortlist the `gates_shortlists` row
     * @param list<array<string,mixed>> $entries   from {@see ShortlistService::entries()}
     * @param array<string,mixed>       $context   category title, programme, edition, year
     */
    public static function render(array $shortlist, array $entries, array $context): string
    {
        $pdf = new Pdf(self::PAGE_W, self::PAGE_H);
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';

        // The text face first, so anything that forgets to name one gets the face that can
        // set an African name. Playfair falls back to it for the glyphs it lacks — a
        // shortlist is a list of names and a hole in one of them is the only unforgivable
        // defect this document has.
        $pdf->font('text', $dir . 'AGText-Regular.ttf');
        $pdf->font('bold', $dir . 'AGText-Bold.ttf');
        $pdf->font('mono', $dir . 'AGMono-Bold.ttf');
        $pdf->font('display', $dir . 'PlayfairDisplay-Bold.ttf', 'bold');

        $rule  = (string) ($shortlist['rule_text'] ?? '');
        $total = count($entries);

        $perPage = (int) floor((self::FOOT_Y - 14.0 - self::FIRST_ROW) / self::ROW_H);
        $perCont = (int) floor((self::FOOT_Y - 14.0 - self::CONT_ROW) / self::ROW_H);

        // ── THE PAGE COUNT IS ARITHMETIC, NOT A SECOND PASS ─────────────────
        //
        // Pdf draws forward-only: a page is flushed the moment the next one begins, so
        // "1 of 3" cannot be stamped after the fact. It does not need to be — how many
        // pages a list of N names occupies is known before a single one is drawn.
        $pages = $total <= $perPage ? 1 : 1 + (int) ceil(($total - $perPage) / max(1, $perCont));

        $i = 0;
        for ($p = 1; $p <= $pages; $p++) {
            if ($p > 1) $pdf->addPage();

            $y    = $p === 1 ? self::masthead($pdf, $context, $shortlist, $total)
                             : self::continued($pdf, $context);
            $room = $p === 1 ? $perPage : $perCont;

            $slice = array_slice($entries, $i, $room);
            self::rows($pdf, $slice, $y);
            $i += count($slice);

            self::footer($pdf, $rule, $shortlist, $total, $p, $pages);
        }

        return $pdf->output();
    }

    // ─────────────────────────────── the masthead ────────────────────────────

    /** @return float the baseline of the first list row */
    private static function masthead(Pdf $pdf, array $ctx, array $sl, int $count): float
    {
        $x = self::M;

        // ── THE MARK ────────────────────────────────────────────────────────
        //
        // The same file the site's navigation uses, so the document and the site are
        // visibly the same institution. `Pdf::image()` takes JPEG only by design, so the
        // PNG is flattened onto white here — see logo().
        $logo = self::logo();
        $mark = 15.0;
        if ($logo !== null) {
            $pdf->image($logo, $x, 18.0, $mark, $mark, 0.5);
            $x += $mark + 5.0;
        }

        $pdf->text('AFRICA GATES', $x, 24.5, 'bold', 10.5, self::INK, 90.0);
        $pdf->text(mb_strtoupper((string) ($ctx['programme'] ?? 'Awards')), $x, 30.5, 'text', 8.0,
                   self::FAINT, 70.0);

        // One rule, full measure. It is the only line on the page that is not type.
        $pdf->line(self::M, 40.0, self::PAGE_W - self::M, 40.0, self::RULE, 0.2);

        // ── WHAT THIS DOCUMENT IS ───────────────────────────────────────────
        $pdf->text('SHORTLIST', self::M, 54.0, 'mono', 8.5, self::MUTE, 160.0);

        $title = (string) ($ctx['category'] ?? 'Shortlist');
        $size  = 25.0;
        // Step down rather than wrap. A two-line category title pushes every row down and
        // costs a page; the titles that need it are the long descriptive ones, which read
        // perfectly well a little smaller.
        while ($size > 15.0 && $pdf->width($title, 'display', $size) > self::PAGE_W - 2 * self::M) {
            $size -= 0.5;
        }
        $pdf->text($title, self::M, 68.0, 'display', $size, self::INK);

        $edition = trim((string) ($ctx['edition'] ?? '') . ' ' . (string) ($ctx['year'] ?? ''));
        if ($edition !== '') {
            $pdf->text($edition, self::M, 76.0, 'text', 10.0, self::MUTE);
        }

        // The count, stated plainly. `of N considered` is the honest denominator: a
        // shortlist of 10 from 12 and a shortlist of 10 from 400 are different facts and a
        // reader is entitled to know which one they are holding.
        $tally = $count . ' shortlisted of ' . (int) ($sl['considered'] ?? $count) . ' considered';
        $pdf->text($tally, self::PAGE_W - self::M - $pdf->width($tally, 'text', 9.0), 76.0,
                   'text', 9.0, self::FAINT);

        self::columnHeads($pdf, 86.0);

        return self::FIRST_ROW;
    }

    /** The lighter header every page after the first gets. */
    private static function continued(Pdf $pdf, array $ctx): float
    {
        $pdf->text(mb_strtoupper((string) ($ctx['category'] ?? '')) . ' — SHORTLIST, CONTINUED',
                   self::M, 30.0, 'mono', 8.0, self::FAINT, 140.0);
        $pdf->line(self::M, 34.0, self::PAGE_W - self::M, 34.0, self::RULE, 0.2);
        self::columnHeads($pdf, 42.0);

        return self::CONT_ROW;
    }

    private static function columnHeads(Pdf $pdf, float $y): void
    {
        $pdf->text('NOMINEE', self::M + 12.0, $y, 'mono', 7.0, self::FAINT, 140.0);
        $label = 'VOTES';
        $pdf->text($label, self::PAGE_W - self::M - $pdf->width($label, 'mono', 7.0), $y,
                   'mono', 7.0, self::FAINT, 140.0);
    }

    // ───────────────────────────────── the rows ──────────────────────────────

    /** @param list<array<string,mixed>> $rows */
    private static function rows(Pdf $pdf, array $rows, float $y): void
    {
        $right = self::PAGE_W - self::M;

        foreach ($rows as $r) {
            $rank  = (int) ($r['rank_no'] ?? 0);
            $name  = trim((string) ($r['nominee_name'] ?? ''));
            $votes = number_format((int) ($r['vote_count'] ?? 0));

            // The rank in gold, and nowhere else on the page. One accent, used once per row,
            // is what carries the eye down a list of forty without a single rule between them.
            $pdf->text(str_pad((string) $rank, 2, '0', STR_PAD_LEFT), self::M, $y, 'mono', 9.0,
                       self::GOLD, 40.0);

            // The votes are placed first so the name knows how much room it has. A long
            // name colliding with its own vote count is the one collision this layout can
            // actually produce.
            $vw = $pdf->width($votes, 'mono', 9.5);
            $pdf->text($votes, $right - $vw, $y, 'mono', 9.5, self::INK);

            $nx    = self::M + 12.0;
            $room  = $right - $vw - 6.0 - $nx;
            $dag   = ((int) ($r['tied_at_cut'] ?? 0)) === 1 ? ' †' : '';
            $shown = self::fit($pdf, $name, 'bold', 11.0, $room - $pdf->width($dag, 'text', 11.0));
            $pdf->text($shown . $dag, $nx, $y, 'bold', 11.0, self::INK);

            // The second line: organisation, then country. Both are optional and the line is
            // skipped entirely when neither is present, so a bare list stays a bare list
            // rather than growing a row of empty space per entry.
            $sub = array_values(array_filter([
                trim((string) ($r['organisation'] ?? '')),
                strtoupper(trim((string) ($r['country_code'] ?? ''))),
            ], fn ($v) => $v !== ''));

            if ($sub !== []) {
                $pdf->text(self::fit($pdf, implode('  ·  ', $sub), 'text', 8.5, $room),
                           $nx, $y + 4.2, 'text', 8.5, self::MUTE);
            }

            $y += self::ROW_H;
        }
    }

    /** Truncate to the measured width with an ellipsis, never mid-combining-mark. */
    private static function fit(Pdf $pdf, string $s, string $face, float $size, float $max): string
    {
        if ($s === '' || $max <= 0 || $pdf->width($s, $face, $size) <= $max) return $s;

        $n = mb_strlen($s);
        while ($n > 1 && $pdf->width(mb_substr($s, 0, $n) . '…', $face, $size) > $max) $n--;

        return mb_substr($s, 0, $n) . '…';
    }

    // ──────────────────────────────── the footer ─────────────────────────────

    /**
     * The evidence line, drawn on EVERY page.
     *
     * On every page deliberately: pages get separated, and a page 2 carrying eleven names
     * and no statement of how they were chosen is the half somebody forwards.
     */
    private static function footer(Pdf $pdf, string $rule, array $sl, int $count,
                                  int $page, int $pages): void
    {
        $when = (string) ($sl['published_at'] ?? '');
        $when = $when !== '' ? date('j F Y', strtotime($when) ?: time()) : '';

        $line = $rule !== '' ? $rule : 'Selected by the organisers.';
        $meta = array_values(array_filter([
            $when !== '' ? 'Published ' . $when : '',
            trim((string) ($sl['note'] ?? '')),
        ], fn ($v) => $v !== ''));

        $measure = self::PAGE_W - 2 * self::M - 24.0;

        $pdf->line(self::M, self::FOOT_Y - 6.0, self::PAGE_W - self::M, self::FOOT_Y - 6.0,
                   self::RULE, 0.2);

        $pdf->text(self::fit($pdf, $line, 'text', 8.0, $measure),
                   self::M, self::FOOT_Y, 'text', 8.0, self::MUTE);

        if ($meta !== []) {
            $pdf->text(self::fit($pdf, implode('  ·  ', $meta), 'text', 7.5, $measure),
                       self::M, self::FOOT_Y + 4.0, 'text', 7.5, self::FAINT);
        }

        // The dagger's key, and only when a dagger can actually appear on the page.
        if ($count > 0) {
            $pdf->text('† level with the cut', self::M, self::FOOT_Y + 8.0, 'text', 7.0, self::FAINT);
        }

        if ($pages > 1) {
            $n = "{$page} / {$pages}";
            $pdf->text($n, self::PAGE_W - self::M - $pdf->width($n, 'mono', 7.5),
                       self::FOOT_Y, 'mono', 7.5, self::FAINT);
        }
    }

    // ───────────────────────────────── the mark ──────────────────────────────

    /**
     * The navigation's logo, as a JPEG the PDF writer will accept.
     *
     * `Pdf::image()` is DCTDecode-only on purpose — the compressed bytes pass into the file
     * untouched and it contains no scanline decoder. The site's mark is a PNG with an alpha
     * channel, so it is composited onto WHITE first: `imagejpeg` on an image with alpha
     * writes the transparent pixels black, which would put a black square where the logo
     * should be on a white page.
     *
     * Cached in var/cache, because this runs on every download and re-encoding a 192px
     * square per request is work with a known answer.
     */
    private static function logo(): ?string
    {
        $src = dirname(__DIR__, 2) . '/public/assets/img/logo-mark.png';
        if (!is_file($src) || !function_exists('imagecreatefromstring')) return null;

        $cache = dirname(__DIR__, 2) . '/var/cache/shortlist-mark.jpg';
        if (is_file($cache) && filemtime($cache) >= filemtime($src)) {
            $bytes = (string) @file_get_contents($cache);
            if ($bytes !== '') return $bytes;
        }

        try {
            $im = @imagecreatefromstring((string) file_get_contents($src));
            if ($im === false) return null;

            $w = imagesx($im);
            $h = imagesy($im);

            // 15mm at ~300dpi is 177px. The source is 192 square, so it is used at its own
            // size — upscaling a logo to hit a round dpi figure only softens it.
            $flat = imagecreatetruecolor($w, $h);
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
            imagedestroy($im);

            ob_start();
            imagejpeg($flat, null, 92);
            $bytes = (string) ob_get_clean();
            imagedestroy($flat);

            if ($bytes === '') return null;
            if (is_dir(dirname($cache)) || @mkdir(dirname($cache), 0775, true)) {
                @file_put_contents($cache, $bytes);
            }

            return $bytes;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A download filename that survives a Windows filesystem and a Content-Disposition header. */
    public static function filename(array $ctx): string
    {
        $s = trim((string) ($ctx['category'] ?? 'shortlist') . '-' . (string) ($ctx['year'] ?? ''));
        $s = preg_replace('~[^\p{L}\p{N}]+~u', '-', $s) ?? 'shortlist';
        $s = trim(strtolower($s), '-');

        return ($s !== '' ? $s : 'shortlist') . '-shortlist.pdf';
    }
}
