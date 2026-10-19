<?php
declare(strict_types=1);
namespace AfricaGates\Services;

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
 *   · no TYPED figure. How the vote works is in the annex, and every number there (the
 *     panel and public shares, the people-versus-votes split, the quorum, the vote price)
 *     is read from the rules for this edition by NomineeVotingNotice::howVotingWorks().
 *     A figure typed into a letter people keep for years is the shape this codebase has
 *     paid for four times, so the annex is dated and names the published rules page.
 *   · not "shortlisted". The ballot holds approved nominees; whether a programme also
 *     publishes a shortlist is a separate fact, and a letter must not claim one.
 *   · no rotating code or vote count — anything that is false a minute after the file
 *     is written.
 *
 * It DOES say that nobody is ever asked to pay to be nominated, to stay on the ballot or
 * to receive an award (plan D34). A letter like this is exactly what a "pay to be
 * honoured" scheme forges, so the genuine one says, on the page, what a forgery asks for.
 *
 * ── THE LETTER ON ONE PAGE, A4, MEASURED; THE ANNEX ON THE NEXT ───────────────────────────────────────────────────
 *
 * The particulars and the close are anchored to the page foot and the body is measured
 * against the real face before it is drawn, tightening its leading rather than pushing the
 * signature off the page — the invitation's discipline, for the same reason: the name and
 * the category are the variables, and the long one is the letter nobody previewed.
 * {@see \Tests\Unit\NomineeVotingNoticeTest} renders the longest inputs and checks.
 */
final class NomineeVotingLetter
{
    private const X     = Letterhead::MARGIN;
    private const W     = Letterhead::PAGE_W - Letterhead::MARGIN * 2;
    /** The measure: 155mm of 10.5pt rather than the full 166mm. See InviteLetter. */
    private const MW    = 155.0;
    private const INK   = Letterhead::INK;
    private const MUTE  = Letterhead::MUTE;
    private const RULE  = Letterhead::RULE;

    /** Top of the particulars block and of the close, from the page foot up. */
    private const PART_Y = Letterhead::PAGE_H - Letterhead::MARGIN - 88.0;
    private const SIGN_Y = Letterhead::PAGE_H - Letterhead::MARGIN - 40.0;

    /**
     * @param array<string,mixed> $v the view {@see NomineeVotingNotice::view()} builds:
     *        name, category, award, reference, date, opens, closes, vote_url, rules_url,
     *        signatory, signatory_title, and `how` (the annex; omitted, no annex is drawn)
     * @return string the PDF bytes
     */
    public static function render(array $v): string
    {
        $pdf = Letterhead::pdf();
        $pdf->addPage();
        $x = self::X;
        $w = self::W;
        $host = Letterhead::host();

        // ── 1 · letterhead ───────────────────────────────────────────────────
        $y = Letterhead::draw($pdf);

        // ── 2 · reference and date, right; the addressee, left ───────────────
        $y += 12.0;
        $ref = 'Our ref: ' . $v['reference'];
        $pdf->text($ref, $x + $w - $pdf->width($ref, 'text', 9.0), $y, 'text', 9.0, self::MUTE);
        $pdf->text($v['date'], $x + $w - $pdf->width($v['date'], 'text', 9.0), $y + 5.2, 'text', 9.0, self::INK);

        // What this platform knows of the addressee: who they are and what for. There is no
        // postal address to set, and a blank address block is worse than an honest one.
        // 110mm: the reference and date close the same lines on the right, so a long name
        // wraps rather than running into them.
        $y = $pdf->paragraph($v['name'], $x, $y, 110.0, 'semi', 11.0, 5.4, self::INK, 2);
        $y = $pdf->paragraph('Nominee, ' . $v['category'], $x, $y, 110.0, 'text', 9.0, 4.4, self::MUTE, 2);
        $y = $pdf->paragraph($v['award'], $x, $y, 110.0, 'text', 9.0, 4.4, self::MUTE, 2);
        $y += 10.0;

        // ── 3 · the subject line ─────────────────────────────────────────────
        $y = $pdf->paragraph(
            mb_strtoupper('Notification of admission to the public vote: ' . $v['award']),
            $x, $y, self::MW, 'bold', 10.0, 5.2, self::INK, 3
        );
        $y += 7.0;

        // ── 4 · the letter ───────────────────────────────────────────────────
        $y = $pdf->paragraph('Dear ' . $v['name'] . ',', $x, $y, self::MW, 'text', 10.5, 5.4, self::INK, 2);

        $body = self::body($v);

        // Measured before it is drawn: the body runs toward the anchored particulars, so its
        // leading gives way before anything collides. Bounded by the floors, never cut.
        $lead = 5.3;
        $gap  = 4.4;
        $room = self::PART_Y - 9.0 - $y;
        $rows = 0;
        foreach ($body as $para) $rows += $pdf->lines($para, self::MW, 'text', 10.5, 8);
        while ($rows * $lead + count($body) * $gap > $room) {
            if ($lead > 4.6) { $lead = max(4.6, $lead - 0.1); continue; }
            if ($gap  > 2.6) { $gap  = max(2.6, $gap  - 0.2); continue; }
            break;
        }
        foreach ($body as $para) {
            $y = $pdf->paragraph($para, $x, $y + $gap, self::MW, 'text', 10.5, $lead, self::INK, 8);
        }

        // ── 5 · the particulars ──────────────────────────────────────────────
        //
        // The record somebody checks the letter against, set as a ruled schedule rather
        // than a box: label left in the muted voice, value right of it, hairlines between.
        // The QR beside it opens the same voting page as the printed address, for the
        // copy that is pinned to a notice board rather than read on a phone.
        $y = self::PART_Y;
        $pdf->text('Particulars of nomination', $x, $y, 'semi', 9.5, self::INK);
        $y += 3.0;
        $pdf->line($x, $y, $x + $w, $y, self::INK, 0.35);

        $qrSize = 28.0;
        $matrix = Qr::encodeBytes($v['vote_url']);
        $colW   = $matrix !== null ? $w - $qrSize - 6.0 : $w;
        // The award and the nominee are already the subject line and the addressee.
        $rowsP  = [
            ['Category',         $v['category']],
            ['Reference',        $v['reference']],
            ['Public voting',    $v['opens'] . ' to ' . $v['closes']],
            ['Your voting page', $v['vote_url']],
        ];
        $ry = $y;
        foreach ($rowsP as [$label, $value]) {
            $pdf->text($label, $x, $ry + 5.0, 'text', 8.5, self::MUTE);
            if ($label === 'Your voting page') {
                // An address has no spaces to wrap at, so it would run on into the QR. It is
                // printed without its scheme (a browser adds it) and set smaller until it
                // fits; never cut, because a truncated address is one nobody can type.
                $value = (string) preg_replace('~^https?://~', '', $value);
                $size  = 9.5;
                while ($size > 6.5 && $pdf->width($value, 'text', $size) > $colW - 34.0) $size -= 0.25;
                $pdf->text($value, $x + 34.0, $ry + 5.0, 'text', $size, self::INK);
                $ry += 7.6;
                $pdf->line($x, $ry, $x + $colW, $ry, self::RULE, 0.25);
                continue;
            }
            $alias = $label === 'Reference' ? 'mono' : 'text';
            // paragraph() returns where the NEXT line's baseline would be, so the rule sits
            // under the last line drawn, not a whole line below it.
            $ny = $pdf->paragraph($value, $x + 34.0, $ry + 5.0, $colW - 34.0, $alias,
                                  $alias === 'mono' ? 9.0 : 9.5, 4.4, self::INK, 2);
            $ry = $ny - 4.4 + 2.6;
            $pdf->line($x, $ry, $x + $colW, $ry, self::RULE, 0.25);
        }
        if ($matrix !== null) {
            // Four modules of quiet zone: the specification, and part of the symbol.
            $pdf->qr($matrix, $x + $w - $qrSize, $y + 3.0, $qrSize, 4);
            $cap = 'Scan to vote';
            $pdf->text($cap, $x + $w - $qrSize / 2 - $pdf->width($cap, 'text', 7.5) / 2,
                       $y + 3.0 + $qrSize + 3.6, 'text', 7.5, self::MUTE);
        }

        // ── 6 · the close, and somewhere to sign ─────────────────────────────
        $signY = self::SIGN_Y;
        $pdf->text('Yours sincerely,', $x, $signY, 'text', 10.5, self::INK);
        $pdf->line($x, $signY + 13.0, $x + 62.0, $signY + 13.0, self::RULE, 0.3);
        $sy = $signY + 18.0;
        if ($v['signatory'] !== '') {
            $pdf->text($v['signatory'], $x, $sy, 'semi', 10.0, self::INK);
            $sy += 5.0;
            $pdf->text($v['signatory_title'], $x, $sy, 'text', 9.0, self::MUTE);
            $sy += 4.8;
            $pdf->text('For and on behalf of Africa GATES', $x, $sy, 'text', 9.0, self::MUTE);
        } else {
            $pdf->text($v['signatory_title'], $x, $sy, 'semi', 10.0, self::INK);
            $sy += 5.0;
            $pdf->text('For and on behalf of Africa GATES', $x, $sy, 'text', 9.0, self::MUTE);
        }

        // ── 7 · the foot ─────────────────────────────────────────────────────
        $footY = Letterhead::PAGE_H - Letterhead::MARGIN;
        $pdf->line($x, $footY - 6.0, $x + $w, $footY - 6.0, self::RULE, 0.3);
        $pdf->text('Issued electronically by Africa GATES under reference ' . $v['reference'] . '.',
                   $x, $footY, 'text', 7.5, self::MUTE);
        $pdf->text($host, $x + $w - $pdf->width($host, 'text', 7.5), $footY, 'text', 7.5, self::MUTE);

        self::annex($pdf, $v);

        return $pdf->output();
    }

    /**
     * Page two: how the public vote works, as this edition runs it.
     *
     * A page of its own rather than squeezed into the letter, because it is the part a
     * nominee forwards to the people they ask for votes, and the letter's one-page rule is
     * about the CLOSE landing in the same place on every copy, which a second page does not
     * disturb. Headed as an annex to the letter's reference, as an enclosure is.
     *
     * The text is {@see NomineeVotingNotice::howVotingWorks()}, read from the rules; nothing
     * here is typed. Measured like the letter: the leading gives before anything runs off.
     *
     * @param array<string,mixed> $v
     */
    private static function annex(Pdf $pdf, array $v): void
    {
        $how = (array) ($v['how'] ?? []);
        if ($how === []) return;

        $pdf->addPage();
        $x = self::X;
        $w = self::W;

        $y = Letterhead::draw($pdf);
        $y += 12.0;
        $ref = 'Annex to our ref: ' . $v['reference'];
        $pdf->text($ref, $x + $w - $pdf->width($ref, 'text', 9.0), $y, 'text', 9.0, self::MUTE);
        $y += 10.0;

        $y = $pdf->paragraph('How the public vote works', $x, $y, self::MW, 'display', 17.0, 7.0, self::INK, 2);
        $y = $pdf->paragraph($v['award'], $x, $y - 1.0, self::MW, 'text', 9.5, 4.8, self::MUTE, 2);
        $y += 4.0;
        $pdf->line($x, $y, $x + $w, $y, self::INK, 0.35);
        $y += 3.0;

        $footY = Letterhead::PAGE_H - Letterhead::MARGIN;
        $room  = $footY - 22.0 - $y;
        $lead  = 5.3;
        $gap   = 6.0;
        $rows  = 0;
        foreach ($how as $h) $rows += 1 + $pdf->lines((string) $h['text'], self::MW - 10.0, 'text', 10.5, 10);
        while ($rows * $lead + count($how) * $gap > $room) {
            if ($lead > 4.6) { $lead = max(4.6, $lead - 0.1); continue; }
            if ($gap  > 3.0) { $gap  = max(3.0, $gap  - 0.2); continue; }
            break;
        }

        foreach (array_values($how) as $i => $h) {
            $y += $gap;
            $num = ($i + 1) . '.';
            $pdf->text($num, $x, $y + 4.0, 'semi', 10.5, self::INK);
            $pdf->text((string) $h['title'], $x + 10.0, $y + 4.0, 'semi', 10.5, self::INK);
            $y = $pdf->paragraph((string) $h['text'], $x + 10.0, $y + 4.0 + $lead, self::MW - 10.0,
                                 'text', 10.5, $lead, self::INK, 10) - $lead;
        }

        // Dated, because the figures are the edition's rules on the day the letter was
        // written, and the published page is where any later change would be.
        $y += 10.0;
        $pdf->paragraph('The figures above are the rules in force for this edition on ' . $v['date']
                        . '. The published rules at ' . $v['rules_url'] . ' are authoritative.',
                        $x, $y, self::MW, 'text', 9.0, 4.6, self::MUTE, 3);

        $pdf->line($x, $footY - 6.0, $x + $w, $footY - 6.0, self::RULE, 0.3);
        $pdf->text('Annex to the letter issued under reference ' . $v['reference'] . '.',
                   $x, $footY, 'text', 7.5, self::MUTE);
        $host = Letterhead::host();
        $pdf->text($host, $x + $w - $pdf->width($host, 'text', 7.5), $footY, 'text', 7.5, self::MUTE);
    }

    /**
     * The paragraphs, shared with the email's plain part so the two cannot disagree.
     *
     * @param array<string,mixed> $v
     * @return list<string>
     */
    public static function body(array $v): array
    {
        return [
            'On behalf of the organising committee of the ' . $v['award'] . ', we are pleased '
                . 'to inform you that your nomination in the ' . $v['category'] . ' category has '
                . 'been verified and admitted to the public vote, which is open from '
                . $v['opens'] . ' to ' . $v['closes'] . '.',

            'The enclosed annex sets out how the public vote works and how the outcome is '
                . 'decided. The rules are published in full for this edition at '
                . $v['rules_url'] . '.',

            'You may share your voting page freely. Africa GATES never asks a nominee to pay '
                . 'to be nominated, to remain on the ballot or to receive an award; any such '
                . 'request does not come from us and should be reported to us.',

            'Please accept our congratulations on this recognition.',
        ];
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
