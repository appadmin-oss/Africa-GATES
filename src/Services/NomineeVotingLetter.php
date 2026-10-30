<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Brand;
use AfricaGates\Support\Letterhead;
use AfricaGates\Support\Pdf;
use AfricaGates\Support\Qr;

/**
 * The formal letter telling a verified nominee that their nomination is on the public vote.
 *
 * ── WHY A LETTER AND NOT ONLY AN EMAIL ───────────────────────────────────────
 *
 * A nomination is shown to an employer, a head teacher, a community leader or a sponsor,
 * and is often the first official word a person has ever had that their work is
 * recognised. An email is read and archived; a letter is printed, forwarded and kept. So
 * the email ({@see NomineeVotingNotice}) carries the news and the link, and this carries
 * the record: who, which award, which category, the reference, the voting period, the
 * page, and a signature. It follows the ordinary order of a formal letter for the reason
 * {@see InviteLetter} gives: it should look like the letters already on the wall.
 *
 * ── WHAT IT DELIBERATELY DOES NOT SAY ────────────────────────────────────────
 *
 *   · no TYPED figure. The steps are read from the live voting rules
 *     (NomineeVotingNotice::steps(): whether free votes exist, the vote price), and the
 *     detailed figures — the panel and public shares, the quorum — travel in the EMAIL's
 *     "How the public vote works", read by NomineeVotingNotice::howVotingWorks(). A figure
 *     typed into a letter people keep for years is the shape this codebase has paid for
 *     four times.
 *   · not "shortlisted". The ballot holds approved nominees; whether a programme also
 *     publishes a shortlist is a separate fact, and a letter must not claim one.
 *   · no rotating code or vote count — anything that is false a minute after the file
 *     is written.
 *
 * It DOES say that nobody is ever asked to pay to be nominated, to stay on the ballot or
 * to receive an award (plan D34). A letter like this is exactly what a "pay to be
 * honoured" scheme forges, so the genuine one says, on the page, what a forgery asks for.
 *
 * ── ONE PAGE, AT A4 AND AT US LETTER (design v2, 20 Oct 2026) ──────────────
 *
 * design_handoff_letter_email_admin/letter, "Nominee Voting Letter v2": full-bleed white,
 * 14mm top, 22mm sides, 10mm bottom; Source Serif 4 for the letter and Instrument Sans for
 * labels and meta, both 12pt with nothing smaller, the headline 16pt; the steps inline;
 * the QR at 21mm with a hairline frame; the footer pinned to the page foot. It replaced a
 * two-page letter-plus-annex. Letter paper is 17.6mm shorter, so the column is MEASURED
 * before it is drawn and gives up spacing, then leading, never type size, before anything
 * could reach the footer. NomineeVotingNoticeTest renders the longest inputs at both sizes.
 *
 * Drawn by Support\Pdf, the writer the ticket and the invitation letter use, rather than
 * the HTML-to-PDF library the handoff suggested: it embeds the TrueType faces itself, and
 * a second PDF engine would be two ways to get a page wrong.
 */
final class NomineeVotingLetter
{
    /** Paper, in mm. Letter for the recipients who use it; A4 for everyone else. */
    public const PAPER = ['A4' => [210.0, 297.0], 'letter' => [215.9, 279.4]];

    /** Where US Letter is the paper in the drawer. */
    private const LETTER_COUNTRIES = ['US', 'CA', 'MX', 'PH', 'CL', 'CO', 'VE', 'GT', 'PR'];

    private const TOP = 14.0;
    private const SIDE = 22.0;
    private const FOOT = 10.0;
    private const PT = 25.4 / 72;

    /** The paper for a recipient's country. */
    public static function paperFor(?string $countryCode): string
    {
        return in_array(strtoupper((string) $countryCode), self::LETTER_COUNTRIES, true) ? 'letter' : 'A4';
    }

    /**
     * @param array<string,mixed> $v the view NomineeVotingNotice::view() builds: name,
     *        category, award, reference, date, opens, closes, vote_url, rules_url, signatory,
     *        signatory_title, and `steps` (NomineeVotingNotice::steps())
     * @return string the PDF bytes
     */
    public static function render(array $v, string $paper = 'A4'): string
    {
        [$pw, $ph] = self::PAPER[$paper] ?? self::PAPER['A4'];
        $pdf = self::pdf($pw, $ph);
        $pdf->addPage();

        // Measured first, then drawn with whatever spacing fits: the same layout, run twice.
        $m = self::fit($pdf, $v, $pw, $ph);
        if ($m['bottom'] > $m['limit']) {
            // Drawn anyway (a letter that arrives beats none), but never silently: the
            // close would run over the pinned footer.
            error_log(sprintf('[voting-letter] %s overruns the footer on %s by %.1fmm',
                (string) ($v['reference'] ?? '?'), $paper, $m['bottom'] - $m['limit']));
        }
        self::layout($pdf, $v, $pw, $m['k'], true);
        self::foot($pdf, $v, $pw, $ph);

        return $pdf->output();
    }

    /**
     * Where the column would end on this paper, and where it must end.
     *
     * The one-page guarantee, as something a test can ask. render() only ever adds one page,
     * so counting pages proves nothing: a column that overruns draws over the pinned footer
     * on page one. This is the measure render() itself uses.
     *
     * @param array<string,mixed> $v
     * @return array{bottom:float, limit:float, k:float}
     */
    public static function measure(array $v, string $paper = 'A4'): array
    {
        [$pw, $ph] = self::PAPER[$paper] ?? self::PAPER['A4'];
        $pdf = self::pdf($pw, $ph);
        $pdf->addPage();

        return self::fit($pdf, $v, $pw, $ph);
    }

    /** @return array{bottom:float, limit:float, k:float} */
    private static function fit(Pdf $pdf, array $v, float $pw, float $ph): array
    {
        $limit  = $ph - self::FOOT - self::footHeight() - 4.0;
        $k      = 1.0;
        $bottom = self::layout($pdf, $v, $pw, $k, false);
        while ($k > 0.4 && $bottom > $limit) {
            $k -= 0.05;
            $bottom = self::layout($pdf, $v, $pw, $k, false);
        }
        return ['bottom' => $bottom, 'limit' => $limit, 'k' => $k];
    }

    /**
     * The column, top to bottom. Returns the y below the last line.
     *
     * $k scales the SPACES between blocks (1 = the design's) and, below 0.7, the leading
     * too, from 1.35 towards 1.2 — never the type, which the design holds at 12pt.
     *
     * @param array<string,mixed> $v
     */
    private static function layout(Pdf $pdf, array $v, float $pw, float $k, bool $draw): float
    {
        $x = self::SIDE;
        $w = $pw - self::SIDE * 2;
        $ink = self::rgb('ink');
        $mute = self::rgb('soft');
        $green = self::rgb('green-deep');
        $lead = 12 * self::PT * ($k >= 0.7 ? 1.35 : max(1.2, 1.35 - (0.7 - $k)));
        $leadAddr = 12 * self::PT * ($k >= 0.7 ? 1.4 : max(1.2, 1.4 - (0.7 - $k)));
        $gap = static fn (float $mm): float => $mm * $k;

        // A line box: the baseline sits where 12pt type would in a CSS line of that height.
        $put = static function (string $s, float $tx, float $top, float $width, string $alias, float $pt,
                                float $lh, array $rgb, int $max = 6) use ($pdf, $draw): float {
            $base = $top + ($lh - $pt * self::PT) / 2 + 0.8 * $pt * self::PT;
            if (!$draw) return $top + $pdf->lines($s, $width, $alias, $pt, $max) * $lh;
            return $pdf->paragraph($s, $tx, $base, $width, $alias, $pt, $lh, $rgb, $max) - $base + $top;
        };

        // ── the top edge (28 Oct 2026, owner: "grand means sleek and modern") ───
        // One thin line across the top of the sheet, green with a gold end: the only
        // flourish, printed edge to edge.
        if ($draw) {
            $pdf->rect(0, 0, $pw * 0.86, 1.2, self::rgb('green-deep'));
            $pdf->rect($pw * 0.86, 0, $pw * 0.14, 1.2, self::rgb('gold'));
        }

        // ── header: the mark at 15mm, the date and reference bottom-right ────
        $y = self::TOP;
        $logoH = 15.0;
        if ($draw) {
            $jpeg = Brand::logoJpeg(480);
            if ($jpeg !== null) {
                $pdf->image($jpeg, $x, $y, $logoH * Brand::LOGO_RATIO, $logoH);
            } else {
                $pdf->text('Africa GATES', $x, $y + 10.0, 'serifb', 16.0, $green);
            }
            foreach ([$v['date'], 'Ref ' . $v['reference']] as $i => $meta) {
                $base = $y + $logoH - (1 - $i) * $lead - 1.2;
                $pdf->text($meta, $x + $w - $pdf->width($meta, 'sans', 12.0), $base, 'sans', 12.0, $mute);
            }
        }
        $y += $logoH;

        // ── the addressee ─────────────────────────────────────────────────────
        $y += $gap(8.0);
        $y = $put($v['name'], $x, $y, $w, 'serifs', 12.0, $leadAddr, $ink, 2);
        $y = $put($v['category'], $x, $y, $w, 'serif', 12.0, $leadAddr, $ink, 2);
        $y = $put($v['award'], $x, $y, $w, 'serif', 12.0, $leadAddr, $ink, 2);

        // ── the headline ─────────────────────────────────────────────────────
        $y += $gap(8.0);
        // The edition's own headline (CycleLetter), with {fields} filled.
        $head = CycleLetter::fill((string) ($v['headline'] ?? CycleLetter::DEFAULT_HEADLINE), $v);
        $y = $put($head, $x, $y, $w, 'sanss', 18.0, 18 * self::PT * 1.15, $ink, 3);
        // A short, fine gold rule under it.
        $y += $gap(2.2);
        if ($draw) $pdf->rect($x, $y, 10.0, 0.6, self::rgb('gold'));
        $y += 0.6 + $gap(3.6);

        // ── the letter ───────────────────────────────────────────────────────
        foreach (array_merge(['Dear ' . $v['name'] . ','], self::body($v)) as $para) {
            $y = $put($para, $x, $y, $w, 'serif', 12.0, $lead, $ink, 8) + $gap(2.6);
        }

        // ── how voting works ─────────────────────────────────────────────────
        $steps = array_values((array) ($v['steps'] ?? []));
        if ($steps !== []) {
            $y += $gap(5.0) - $gap(2.6);
            $y = $put('How voting works', $x, $y, $w, 'sanss', 12.0, $lead, $green, 1) + $gap(2.4);
            foreach ($steps as $i => $step) {
                $put((string) ($i + 1), $x, $y, 8.0, 'serifb', 12.0, $leadAddr, $green, 1);
                $y = $put((string) $step, $x + 8.0, $y, $w - 8.0, 'serif', 12.0, $leadAddr, $ink, 4) + $gap(1.8);
            }
        }

        // ── prizes to be won, and the community return ───────────────────────
        // Short, from the award's own words and the edition's rules
        // (NomineeVotingNotice::prizes(), ::communityReturn()); left out when empty.
        // One short paragraph each, the label run in, so the letter keeps to one page; the email
        // carries them in full.
        $prize = trim((string) (((array) ($v['prizes'] ?? []))[0] ?? ''));
        foreach ([['Prizes to be won: ', $prize, 2], ['Your community return: ', trim((string) ($v['return_short'] ?? '')), 3]] as [$h, $para, $max]) {
            if ($para === '') continue;
            $y += $gap(2.4);
            $y = $put($h . $para, $x, $y, $w, 'serif', 12.0, $leadAddr, $ink, $max);
        }

        // ── the vote row: the QR, and the address for whoever cannot scan ────
        $y += $gap(5.0);
        $qr = 21.0;
        $url = (string) preg_replace('~^https?://~', '', (string) $v['vote_url']);
        $tx = $x + $qr + 7.0;
        if ($draw) {
            // The invitation, set apart by a hairline frame rather than a fill.
            $pdf->frame($x - 3.0, $y - 3.0, $w + 6.0, $qr + 6.0, self::rgb('line-2'), 0.5 * self::PT);
            $m = Qr::encodeBytes((string) $v['vote_url']);
            if ($m !== null) {
                // Four modules of quiet zone, inside the frame: the specification.
                $pdf->qr($m, $x, $y, $qr, 4);
                $pdf->frame($x, $y, $qr, $qr, self::rgb('line-2'), 0.5 * self::PT);
            }
            $mid = $y + $qr / 2;
            $pdf->text('Invite your community to vote: scan, or visit', $tx, $mid - 1.0, 'sanss', 12.0, $ink);
            // An address has no spaces to wrap at: set smaller until it fits, never cut —
            // and never below the 12pt floor unless the address could not otherwise be read.
            $size = 12.0;
            while ($size > 9.0 && $pdf->width($url, 'sans', $size) > $w - $qr - 7.0) $size -= 0.25;
            $pdf->text($url, $tx, $mid + $lead - 1.0, 'sans', $size, $green);
        }
        $y += $qr;

        // ── the close ────────────────────────────────────────────────────────
        $y += $gap(5.0);
        $y = $put('Yours sincerely,', $x, $y, $w, 'serif', 12.0, $lead, $ink, 1);
        $y += max(4.0, 7.0 * $k);
        $name = trim((string) ($v['signatory'] ?? ''));
        $title = trim((string) ($v['signatory_title'] ?? ''));
        if ($name !== '') $y = $put($name, $x, $y, $w, 'serifs', 12.0, $lead, $ink, 1);
        $y = $put(($title !== '' ? $title . ', ' : '') . 'Africa GATES', $x, $y, $w, 'serif', 12.0, $lead, $mute, 2);

        return $y;
    }

    private static function footHeight(): float
    {
        return 4.0 + 12 * self::PT * 1.35;
    }

    /** The foot, pinned: a hairline, the reference left, the host right. */
    private static function foot(Pdf $pdf, array $v, float $pw, float $ph): void
    {
        $x = self::SIDE;
        $w = $pw - self::SIDE * 2;
        $ruleY = $ph - self::FOOT - self::footHeight();
        $pdf->line($x, $ruleY, $x + $w, $ruleY, self::rgb('line-3'), 0.5 * self::PT);
        $base = $ruleY + 4.0 + 12 * self::PT * 1.05;
        $left = 'Issued electronically · ' . $v['reference'];
        $host = Letterhead::host();
        $mute = self::rgb('soft');
        $pdf->text($left, $x, $base, 'sans', 12.0, $mute);
        $pdf->text($host, $x + $w - $pdf->width($host, 'sans', 12.0), $base, 'sans', 12.0, $mute);
    }

    /**
     * The faces: Source Serif 4 for the letter, Instrument Sans for labels — the design's —
     * each with a fallback that has the letters it lacks. Instrument Sans has no ọ, ẹ or ṣ,
     * so a sans label falls through to DM Sans per character; Source Serif lacks Ɓ and Ɗ,
     * which fall to AG Text. A Yoruba or Hausa name sets whole rather than with boxes in it.
     */
    private static function pdf(float $w, float $h): Pdf
    {
        $pdf = new Pdf($w, $h);
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';
        $pdf->font('fb', $dir . 'AGText-Regular.ttf');
        $pdf->font('fbb', $dir . 'AGText-Bold.ttf');
        $pdf->font('dms', $dir . 'DMSans-Regular.ttf', 'fb');
        $pdf->font('dmss', $dir . 'DMSans-SemiBold.ttf', 'fbb');
        $pdf->font('serif', $dir . 'SourceSerif4-Regular.ttf', 'fb');
        $pdf->font('serifs', $dir . 'SourceSerif4-SemiBold.ttf', 'fbb');
        $pdf->font('serifb', $dir . 'SourceSerif4-Bold.ttf', 'fbb');
        $pdf->font('sans', $dir . 'InstrumentSans-Regular.ttf', 'dms');
        $pdf->font('sanss', $dir . 'InstrumentSans-SemiBold.ttf', 'dmss');

        return $pdf;
    }

    /** @return array{0:int,1:int,2:int} a palette colour, by name (Support\Accent) */
    private static function rgb(string $token): array
    {
        $h = ltrim(Accent::hex($token), '#');
        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }

    /**
     * The paragraphs, shared with the email so the two cannot disagree.
     *
     * The edition's own words since 28 Oct 2026 (CycleLetter, edited in the console), with
     * the no-payment notice added by CycleLetter::render() whatever the words say.
     *
     * @param array<string,mixed> $v
     * @return list<string>
     */
    public static function body(array $v): array
    {
        return CycleLetter::render((string) ($v['letter_body'] ?? CycleLetter::DEFAULT_BODY), $v);
    }

    /** The filename a nominee wants to see in their downloads folder. */
    /** @param array<string,mixed> $v */
    public static function fileName(array $v): string
    {
        $stem = \AfricaGates\Support\Slug::make($v['name'], 40);

        return 'africa-gates-voting-letter-' . ($stem !== '' ? $stem . '-' : '')
             . strtolower(preg_replace('~[^A-Za-z0-9]+~', '-', $v['reference'])) . '.pdf';
    }
}
