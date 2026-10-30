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
 * ── TWO PAGES: THE LETTER, AND THE GUIDE (owner, 30 Oct 2026) ──────────────
 *
 * The v2 design put the letter, the steps, the QR and the signature on one page. Adding the
 * prizes and the community return to that page made it a wall of text ("the PDF is not
 * well-structured"), so the document is rebuilt as two pages that each do one job:
 *
 *   1  THE LETTER — the header, the addressee, the headline, the edition's own paragraphs
 *      (CycleLetter), the particulars as a ruled box, the invitation with the QR, and the
 *      signature. Measured before it is drawn, and on a long name it gives up spacing,
 *      never type size, before anything could reach the footer.
 *   2  YOUR NOMINATION GUIDE — "How voting works", "Prizes to be won" and "Your community
 *      return" as numbered sections, the return as labelled rows (NomineeVotingNotice::
 *      communityReturn()), each left out when this edition has nothing true to say.
 *
 * Margins and faces are the design's: 22mm sides, Source Serif 4 for reading and
 * Instrument Sans for labels, 12pt and nothing smaller. NomineeVotingNoticeTest renders the
 * longest inputs at A4 and at US Letter, which is 17.6mm shorter.
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
     * @param array<string,mixed> $v the view NomineeVotingNotice::view() builds
     * @return string the PDF bytes
     */
    public static function render(array $v, string $paper = 'A4'): string
    {
        [$pw, $ph] = self::PAPER[$paper] ?? self::PAPER['A4'];
        $pdf = self::pdf($pw, $ph);

        $pdf->addPage();
        $m = self::fit($pdf, $v, $pw, $ph, 'letter');
        if ($m['bottom'] > $m['limit']) {
            // Drawn anyway (a letter that arrives beats none), but never silently.
            error_log(sprintf('[voting-letter] %s overruns the footer on %s by %.1fmm',
                (string) ($v['reference'] ?? '?'), $paper, $m['bottom'] - $m['limit']));
        }
        self::letter($pdf, $v, $pw, $m['k'], true);
        self::foot($pdf, $v, $pw, $ph, 1);

        if (self::hasGuide($v)) {
            $pdf->addPage();
            $g = self::fit($pdf, $v, $pw, $ph, 'guide');
            self::guide($pdf, $v, $pw, $g['k'], true);
            self::foot($pdf, $v, $pw, $ph, 2);
        }
        return $pdf->output();
    }

    /**
     * Where a page's column would end on this paper, and where it must end. `letter` is page
     * one, whose paragraphs an operator edits (NomineeVotingNotice::fit() asks it); `guide`
     * is page two.
     *
     * @param array<string,mixed> $v
     * @return array{bottom:float, limit:float, k:float}
     */
    public static function measure(array $v, string $paper = 'A4', string $page = 'letter'): array
    {
        [$pw, $ph] = self::PAPER[$paper] ?? self::PAPER['A4'];
        $pdf = self::pdf($pw, $ph);
        $pdf->addPage();
        return self::fit($pdf, $v, $pw, $ph, $page);
    }

    /** Is there anything for page two? */
    public static function hasGuide(array $v): bool
    {
        return ($v['steps'] ?? []) !== [] || ($v['prizes'] ?? []) !== [] || ($v['community_return'] ?? []) !== [];
    }

    /** @return array{bottom:float, limit:float, k:float} */
    private static function fit(Pdf $pdf, array $v, float $pw, float $ph, string $page): array
    {
        $limit = $ph - self::FOOT - self::footHeight() - 4.0;
        $draw = static fn (float $k): float => $page === 'guide' ? self::guide($pdf, $v, $pw, $k, false) : self::letter($pdf, $v, $pw, $k, false);
        $k = 1.0;
        $bottom = $draw($k);
        while ($k > 0.4 && $bottom > $limit) {
            $k -= 0.05;
            $bottom = $draw($k);
        }
        return ['bottom' => $bottom, 'limit' => $limit, 'k' => $k];
    }

    /**
     * A line box: the baseline sits where the type would in a CSS line of that height.
     * Returns the y below the last line.
     */
    private static function put(Pdf $pdf, bool $draw, string $s, float $x, float $top, float $width, string $alias,
                                float $pt, float $lh, array $rgb, int $max = 12): float
    {
        $base = $top + ($lh - $pt * self::PT) / 2 + 0.8 * $pt * self::PT;
        if (!$draw) return $top + $pdf->lines($s, $width, $alias, $pt, $max) * $lh;
        return $pdf->paragraph($s, $x, $base, $width, $alias, $pt, $lh, $rgb, $max) - $base + $top;
    }

    /** Leading for 12pt reading type: 1.4, closing to 1.25 only when the page is tight. */
    private static function lead(float $k): float
    {
        return 12 * self::PT * ($k >= 0.7 ? 1.4 : max(1.25, 1.4 - (0.7 - $k)));
    }

    /** Page one: the letter. Returns the y below the signature. */
    private static function letter(Pdf $pdf, array $v, float $pw, float $k, bool $draw): float
    {
        $x = self::SIDE;
        $w = $pw - self::SIDE * 2;
        $ink = self::rgb('ink'); $soft = self::rgb('soft'); $green = self::rgb('green-deep');
        $lead = self::lead($k);
        $gap = static fn (float $mm): float => $mm * $k;
        $put = static fn (string $s, float $top, string $alias, float $pt, float $lh, array $rgb, int $max = 12, float $tx = 0.0, float $tw = 0.0): float
            => self::put($pdf, $draw, $s, $tx ?: $x, $top, $tw ?: $w, $alias, $pt, $lh, $rgb, $max);

        self::masthead($pdf, $v, $pw, $draw);
        $y = self::TOP + 15.0;

        // The addressee, set as a formal letter sets one.
        $y += $gap(8.0);
        $y = $put((string) $v['name'], $y, 'serifs', 12.0, $lead, $ink, 2);
        $y = $put((string) $v['category'], $y, 'serif', 12.0, $lead, $ink, 2);
        $y = $put((string) $v['award'], $y, 'serif', 12.0, $lead, $ink, 2);

        // The headline, with a short gold rule.
        $y += $gap(7.0);
        $head = CycleLetter::fill((string) ($v['headline'] ?? CycleLetter::DEFAULT_HEADLINE), $v);
        $y = $put($head, $y, 'sanss', 18.0, 18 * self::PT * 1.2, $ink, 3);
        $y += $gap(2.5);
        if ($draw) $pdf->rect($x, $y, 12.0, 0.7, self::rgb('gold'));
        $y += 0.7 + $gap(5.0);

        // The letter.
        foreach (array_merge(['Dear ' . $v['name'] . ','], self::body($v)) as $para) {
            $y = $put($para, $y, 'serif', 12.0, $lead, $ink, 10) + $gap(3.2);
        }

        // The particulars: a ruled box of label and value, read at a glance.
        // The award and category are in the address above, so the box holds what is not.
        $rows = [['Public voting', $v['opens'] . ' to ' . $v['closes']], ['Reference', (string) $v['reference']]];
        $y += $gap(4.0);
        $labelW = 34.0; $pad = 4.0;
        $top = $y;
        $y += $pad;
        foreach ($rows as $i => [$label, $value]) {
            $rowTop = $y;
            $put($label, $rowTop, 'sans', 12.0, $lead, $soft, 1, $x + $pad, $labelW);
            $y = $put($value, $rowTop, $i === 1 ? 'sans' : 'serif', 12.0, $lead, $ink, 3, $x + $pad + $labelW, $w - $labelW - $pad * 2);
            $y += $gap(1.2);
        }
        $y += $pad - $gap(1.2);
        if ($draw) {
            $pdf->rect($x, $top, 1.0, $y - $top, $green);
            $pdf->frame($x, $top, $w, $y - $top, self::rgb('line-2'), 0.5 * self::PT);
        }

        // The invitation: the QR, and the address for whoever cannot scan.
        $y += $gap(6.0);
        $qr = 22.0;
        $url = (string) preg_replace('~^https?://~', '', (string) $v['vote_url']);
        if ($draw) {
            $m = Qr::encodeBytes((string) $v['vote_url']);
            if ($m !== null) $pdf->qr($m, $x, $y, $qr, 4);
            $tx = $x + $qr + 7.0;
            $mid = $y + $qr / 2;
            $pdf->text('Invite your community to vote', $tx, $mid - 2.2, 'sanss', 12.0, $ink);
            $pdf->text('Scan the code, or visit', $tx, $mid + 2.6, 'sans', 12.0, $soft);
            $size = 12.0;
            while ($size > 9.0 && $pdf->width($url, 'sans', $size) > $w - $qr - 7.0) $size -= 0.25;
            $pdf->text($url, $tx, $mid + 2.6 + $lead, 'sans', $size, $green);
        }
        $y += $qr;

        // The close.
        $y += $gap(7.0);
        $y = $put('Yours sincerely,', $y, 'serif', 12.0, $lead, $ink, 1);
        $y += max(4.0, 8.0 * $k);
        $name = trim((string) ($v['signatory'] ?? ''));
        $title = trim((string) ($v['signatory_title'] ?? ''));
        if ($name !== '') $y = $put($name, $y, 'serifs', 12.0, $lead, $ink, 1);
        return $put(($title !== '' ? $title . ', ' : '') . 'Africa GATES', $y, 'serif', 12.0, $lead, $soft, 2);
    }

    /**
     * Page two: the guide. Numbered sections, each with a title and a rule; the community
     * return as labelled rows. Returns the y below the last section.
     */
    private static function guide(Pdf $pdf, array $v, float $pw, float $k, bool $draw): float
    {
        $x = self::SIDE;
        $w = $pw - self::SIDE * 2;
        $ink = self::rgb('ink'); $soft = self::rgb('soft'); $green = self::rgb('green-deep');
        $lead = self::lead($k);
        $gap = static fn (float $mm): float => $mm * $k;
        $put = static fn (string $s, float $top, string $alias, float $pt, float $lh, array $rgb, int $max = 12, float $tx = 0.0, float $tw = 0.0): float
            => self::put($pdf, $draw, $s, $tx ?: $x, $top, $tw ?: $w, $alias, $pt, $lh, $rgb, $max);

        self::masthead($pdf, $v, $pw, $draw);
        $y = self::TOP + 15.0 + $gap(10.0);
        $y = $put('Your nomination guide', $y, 'sanss', 18.0, 18 * self::PT * 1.2, $ink, 1);
        $y = $put($v['name'] . ' · ' . $v['category'], $y + $gap(1.0), 'sans', 12.0, $lead, $soft, 2);

        $sections = [];
        if (($v['steps'] ?? []) !== []) $sections[] = ['How voting works', 'steps', (array) $v['steps']];
        if (($v['prizes'] ?? []) !== []) $sections[] = ['Prizes to be won', 'paras', (array) $v['prizes']];
        if (($v['community_return'] ?? []) !== []) $sections[] = ['Your community return', 'rows', (array) $v['community_return']];

        foreach ($sections as $n => [$title, $kind, $items]) {
            $y += $gap(7.5);
            if ($draw) $pdf->line($x, $y, $x + $w, $y, self::rgb('line-2'), 0.5 * self::PT);
            $y += $gap(4.0);
            $put((string) ($n + 1), $y, 'sanss', 14.0, 14 * self::PT * 1.3, $green, 1, $x, 8.0);
            $y = $put($title, $y, 'sanss', 14.0, 14 * self::PT * 1.3, $ink, 1, $x + 8.0, $w - 8.0) + $gap(2.5);

            if ($kind === 'steps') {
                foreach ($items as $i => $step) {
                    $put(chr(97 + $i) . '.', $y, 'sans', 12.0, $lead, $soft, 1, $x + 8.0, 6.0);
                    $y = $put((string) $step, $y, 'serif', 12.0, $lead, $ink, 5, $x + 14.0, $w - 14.0) + $gap(2.0);
                }
            } elseif ($kind === 'paras') {
                // The "overall winners" line and at most two paragraphs of four lines: the award's
                // full words are on its page.
                foreach (array_slice($items, 0, 3) as $para) $y = $put((string) $para, $y, 'serif', 12.0, $lead, $ink, 4, $x + 8.0, $w - 8.0) + $gap(2.4);
            } else {
                $labelW = 47.0;
                foreach ($items as $i => $row) {
                    [$label, $text] = is_array($row) ? [(string) ($row[0] ?? ''), (string) ($row[1] ?? '')] : ['', (string) $row];
                    if ($i > 0 && $draw) $pdf->line($x + 8.0, $y - $gap(1.2), $x + $w, $y - $gap(1.2), self::rgb('line-3'), 0.4 * self::PT);
                    $rowTop = $y;
                    $put($label, $rowTop, 'sanss', 12.0, $lead, $ink, 2, $x + 8.0, $labelW - 3.0);
                    $y = max($y, $put($text, $rowTop, 'serif', 12.0, $lead, $ink, 6, $x + 8.0 + $labelW, $w - 8.0 - $labelW)) + $gap(2.6);
                }
            }
        }

        $y += $gap(6.0);
        return $put('The full rules are published at ' . (string) ($v['rules_url'] ?? '') . '.', $y, 'sans', 12.0, $lead, $soft, 2);
    }

    /** The top edge, the mark at 15mm, and the date and reference bottom-right. */
    private static function masthead(Pdf $pdf, array $v, float $pw, bool $draw): void
    {
        if (!$draw) return;
        $x = self::SIDE;
        $w = $pw - self::SIDE * 2;
        $pdf->rect(0, 0, $pw * 0.86, 1.2, self::rgb('green-deep'));
        $pdf->rect($pw * 0.86, 0, $pw * 0.14, 1.2, self::rgb('gold'));
        $y = self::TOP;
        $logoH = 15.0;
        $jpeg = Brand::logoJpeg(480);
        if ($jpeg !== null) {
            $pdf->image($jpeg, $x, $y, $logoH * Brand::LOGO_RATIO, $logoH);
        } else {
            $pdf->text('Africa GATES', $x, $y + 10.0, 'serifb', 16.0, self::rgb('green-deep'));
        }
        $lead = 12 * self::PT * 1.35;
        foreach ([(string) $v['date'], 'Ref ' . $v['reference']] as $i => $meta) {
            $base = $y + $logoH - (1 - $i) * $lead - 1.2;
            $pdf->text($meta, $x + $w - $pdf->width($meta, 'sans', 12.0), $base, 'sans', 12.0, self::rgb('soft'));
        }
    }

    private static function footHeight(): float
    {
        return 4.0 + 12 * self::PT * 1.35;
    }

    /** The foot, pinned: a hairline, the reference left, the host right. */
    private static function foot(Pdf $pdf, array $v, float $pw, float $ph, int $page = 1): void
    {
        $x = self::SIDE;
        $w = $pw - self::SIDE * 2;
        $ruleY = $ph - self::FOOT - self::footHeight();
        $pdf->line($x, $ruleY, $x + $w, $ruleY, self::rgb('line-3'), 0.5 * self::PT);
        $base = $ruleY + 4.0 + 12 * self::PT * 1.05;
        $left = 'Issued electronically · ' . $v['reference'] . ($page > 1 ? ' · page ' . $page : '');
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
