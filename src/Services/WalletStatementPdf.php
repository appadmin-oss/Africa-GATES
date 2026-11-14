<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Pdf;

/**
 * A member's wallet statement: A4, white, hairlines, the rows the history page showed.
 *
 * Built from {@see WalletHistory::query()} with the page's own parameters, so a statement
 * downloaded from a filtered page is that filter's rows and says which filter it was. It
 * names the member and never their email or phone, because a statement is a document that
 * gets attached to things — a loan form, a dispute — and travels further than the account.
 *
 * Colours are asked of Accent by name, so the document and the page cannot drift apart.
 */
final class WalletStatementPdf
{
    private const W = 210.0;
    private const H = 297.0;
    private const M = 18.0;
    private const ROW = 9.0;

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{q:string, month:string, type:string, f:string} $p
     */
    public static function render(string $member, array $rows, array $p, int $inKobo, int $outKobo): string
    {
        $pdf = new Pdf(self::W, self::H);
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';
        $pdf->font('text', $dir . 'AGText-Regular.ttf');
        $pdf->font('bold', $dir . 'AGText-Bold.ttf');
        $pdf->font('mono', $dir . 'AGMono-Bold.ttf');
        $pdf->font('display', $dir . 'PlayfairDisplay-Bold.ttf', 'bold');

        $ink = self::c('ink'); $ink2 = self::c('ink-2'); $soft = self::c('soft');
        $line = self::c('line'); $green = self::c('green-deep'); $wash = self::c('green-wash');

        $period = WalletHistory::monthLabel($p['month']);
        $filters = [];
        if ($p['type'] !== 'all') $filters[] = WalletHistory::TYPES[$p['type']];
        if ($p['f'] !== 'all') $filters[] = WalletHistory::CHIPS[$p['f']];
        if ($p['q'] !== '') $filters[] = '“' . $p['q'] . '”';

        $page = 0;
        $y = 0.0;
        $head = static function () use ($pdf, &$page, &$y, $ink, $soft, $line, $member, $period): void {
            $pdf->addPage();
            $page++;
            $pdf->text('Africa GATES', self::M, self::M + 6, 'display', 15, $ink);
            $pdf->text('Wallet statement', self::M, self::M + 13, 'bold', 11, $ink);
            $right = 'Page ' . $page;
            $pdf->text($right, self::W - self::M - $pdf->width($right, 'text', 8.5), self::M + 6, 'text', 8.5, $soft);
            $pdf->text($member . ' · ' . $period, self::M, self::M + 19, 'text', 9, $soft);
            $pdf->line(self::M, self::M + 23, self::W - self::M, self::M + 23, $line, 0.25);
            $y = self::M + 31;
        };
        $head();

        // The summary: money in, money out, how many, and which filter made it.
        $boxW = (self::W - 2 * self::M - 6) / 2;
        foreach ([['Money in', $inKobo, $green], ['Money out', $outKobo, $ink]] as $i => [$l, $k, $c]) {
            $x = self::M + $i * ($boxW + 6);
            $pdf->rect($x, $y, $boxW, 18, $wash);
            $pdf->text($l, $x + 4, $y + 6.5, 'bold', 8.5, $c);
            $pdf->text(self::naira($k), $x + 4, $y + 14, 'bold', 13, $ink);
        }
        $y += 25;
        $count = count($rows) . (count($rows) === 1 ? ' transaction' : ' transactions')
            . ($filters !== [] ? ' · ' . implode(' · ', $filters) : '');
        $pdf->text($count, self::M, $y, 'text', 9, $ink2);
        $pdf->text('Generated ' . date('j M Y, g:i a'), self::M, $y + 5, 'text', 8, $soft);
        $y += 13;

        $cols = static function () use ($pdf, &$y, $soft, $line): void {
            $pdf->text('Date', self::M, $y, 'bold', 8, $soft);
            $pdf->text('Description', self::M + 28, $y, 'bold', 8, $soft);
            $pdf->text('Status', self::M + 118, $y, 'bold', 8, $soft);
            $a = 'Amount';
            $pdf->text($a, self::W - self::M - $pdf->width($a, 'bold', 8), $y, 'bold', 8, $soft);
            $pdf->line(self::M, $y + 2.5, self::W - self::M, $y + 2.5, $line, 0.25);
            $y += 8;
        };
        $cols();

        if ($rows === []) {
            $pdf->text('Nothing matches this statement’s filters.', self::M, $y + 2, 'text', 10, $ink2);
        }
        foreach ($rows as $t) {
            if ($y > self::H - self::M - 14) { $head(); $cols(); }
            $ts = strtotime((string) $t['at']) ?: time();
            $pdf->text(date('j M Y', $ts), self::M, $y, 'text', 8.5, $ink);
            $pdf->text(date('g:i a', $ts), self::M, $y + 3.8, 'text', 7, $soft);
            $pdf->paragraph((string) $t['title'], self::M + 28, $y, 86, 'bold', 8.5, 3.8, $ink, 1);
            $pdf->paragraph((string) $t['sub'] . ' · ' . (string) $t['key'], self::M + 28, $y + 3.8, 86, 'text', 7, 3.4, $soft, 1);
            $pdf->text((string) $t['status_label'], self::M + 118, $y, 'text', 8.5, $ink2);
            $pts = ($t['unit'] ?? 'naira') === 'pts';
            $amt = ($t['dir'] === 'in' ? '+' : '−') . ($pts ? number_format((int) $t['amount_kobo']) . ' pts' : self::naira((int) $t['amount_kobo']));
            $pdf->text($amt, self::W - self::M - $pdf->width($amt, 'bold', 9), $y, 'bold', 9, $t['dir'] === 'in' ? $green : $ink);
            $pdf->line(self::M, $y + 6, self::W - self::M, $y + 6, $line, 0.15);
            $y += self::ROW;
        }

        $host = (string) (parse_url(\AfricaGates\Support\SiteUrl::base(), PHP_URL_HOST) ?: '');
        $foot = 'Questions about a transaction? Quote its number' . ($host !== '' ? ' at ' . $host . '/support' : ' to our support team') . '.';
        $pdf->text($foot, self::M, self::H - self::M + 4, 'text', 7.5, $soft);
        return $pdf->output();
    }

    private static function naira(int $kobo): string
    {
        return '₦' . number_format($kobo / 100, 2);
    }

    /** @return array{0:int,1:int,2:int} */
    public static function c(string $token): array
    {
        $h = ltrim(Accent::hex($token), '#');
        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }
}
