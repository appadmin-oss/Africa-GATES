<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Pdf;

/**
 * A wallet transaction's receipt as a FILE — /account/wallet/tx/{key}.png and .pdf.
 * Wallet Receipt.dc.html (revision 3, 21 Oct 2026): a ticket card 580 wide on the green
 * wash, the amount and its status above a perforation, the people and the money below it,
 * the numbers in one grey block, the support line, and when it was generated.
 *
 * ── ONE LAYOUT, TWO OUTPUTS ──────────────────────────────────────────────────
 *
 * {@see self::layout()} measures and places everything once, in CSS pixels on the 794×1123
 * A4 card, as a list of drawing operations. The PNG paints them at 2x with GD; the PDF
 * writes them in millimetres. Two renderers with their own geometry is how the shared
 * receipt and the downloaded one come to disagree about where the amount is.
 *
 * ── MASKED HERE, NEVER IN CSS ────────────────────────────────────────────────
 *
 * A receipt is forwarded: to a family chat, a landlord, a bank. So the account number shows
 * its first and last three digits (012****789), a session ID loses the four digits before
 * its last four, and no email or phone is drawn at all. The account NAME stays whole, as
 * the bank returned it, because that is what proves where the money went. Masking is done
 * before anything is drawn, so no renderer can print the whole number by mistake.
 *
 * ── ONLY WHAT HAS HAPPENED GETS A FILE ───────────────────────────────────────
 *
 * Paid and received. A withdrawal in review, a locked credit or a refused request has no
 * receipt file: a document saying "₦2,000" with a stamp is read as money moved, whatever
 * small word sits beside it. The page itself shows every status; the file is proof.
 */
final class WalletReceipt
{
    use FlierRaster;

    public const W = 794;
    public const H = 1123;
    private const CARD_W = 580;
    private const PAD = 44;

    /** A file is issued only for money that has actually moved. */
    public static function issuable(array $t): bool
    {
        return in_array((string) ($t['status'] ?? ''), [MemberWallet::ST_PAID, MemberWallet::ST_RECEIVED], true);
    }

    public static function maskAccount(string $n): string
    {
        $d = preg_replace('/\D+/', '', $n) ?? '';
        $l = strlen($d);
        if ($l === 0) return '';
        if ($l < 7) return '****' . substr($d, -2);
        return substr($d, 0, 3) . str_repeat('*', $l - 6) . substr($d, -3);
    }

    public static function maskSession(string $s): string
    {
        $s = trim($s);
        $l = strlen($s);
        if ($l <= 8) return str_repeat('*', max(0, $l - 4)) . substr($s, -4);
        return substr($s, 0, $l - 8) . '****' . substr($s, -4);
    }

    /**
     * What a receipt says, already masked.
     *
     * @return array{amount:string, status:string, ok:bool, line:string, key:string,
     *               rows:list<array{k:string, v:string, sub:string, green:bool}>,
     *               ids:list<array{k:string, v:string, soft:bool}>}
     */
    public static function facts(array $t, string $member): array
    {
        $pts = ($t['unit'] ?? 'naira') === 'pts';
        $naira = static fn (int $k): string => '₦' . number_format($k / 100, 2);
        $amount = $pts ? number_format((int) $t['amount_kobo']) . ' pts' : $naira((int) $t['amount_kobo']);
        $pocket = '';
        foreach ((array) ($t['details'] ?? []) as $d) {
            if (in_array($d[0] ?? '', ['Pocket', 'From'], true)) { $pocket = (string) $d[1]; break; }
        }
        $pocket = trim((string) preg_replace('/ · .*$/', '', $pocket));
        $wallet = 'Africa GATES wallet' . ($pocket !== '' ? ' · ' . $pocket : '');
        $member = trim(preg_replace('/\S+@\S+/', '', $member) ?? '') ?: 'Member';

        $rows = [];
        if (isset($t['payee'])) {
            $p = $t['payee'];
            $acct = self::maskAccount((string) $p['number']);
            $rows[] = ['k' => 'Recipient', 'v' => (string) $p['name'] ?: $member,
                       'sub' => trim((string) $p['bank']) . ($acct !== '' ? ' |' : ''), 'subm' => $acct, 'green' => false];
            $rows[] = ['k' => 'Sender', 'v' => $member, 'sub' => $wallet, 'green' => false];
            $fee = (int) ($t['fee_kobo'] ?? 0);
            $rows[] = ['k' => 'Withdrawal fee', 'v' => $naira($fee), 'sub' => '', 'green' => false];
            $rows[] = ['k' => 'Amount received', 'v' => $naira(max(0, (int) $t['amount_kobo'] - $fee)), 'sub' => '', 'green' => true];
        } elseif ($t['dir'] === 'in') {
            $rows[] = ['k' => 'Recipient', 'v' => $member, 'sub' => $wallet, 'green' => false];
            $rows[] = ['k' => 'From', 'v' => 'Africa GATES', 'sub' => (string) $t['sub'], 'green' => false];
            $rows[] = ['k' => 'Amount received', 'v' => $amount, 'sub' => '', 'green' => true];
        } else {
            $rows[] = ['k' => 'Paid by', 'v' => $member, 'sub' => $wallet, 'green' => false];
            $rows[] = ['k' => 'For', 'v' => (string) $t['title'], 'sub' => (string) $t['sub'], 'green' => false];
            $rows[] = ['k' => 'Amount', 'v' => $amount, 'sub' => '', 'green' => false];
        }

        $ids = [['k' => 'Transaction no.', 'v' => strtoupper((string) $t['key']), 'soft' => false]];
        if ((string) ($t['ref'] ?? '') !== '') $ids[] = ['k' => 'Reference', 'v' => (string) $t['ref'], 'soft' => false];
        if ((string) ($t['session'] ?? '') !== '') $ids[] = ['k' => 'Session ID', 'v' => self::maskSession((string) $t['session']), 'soft' => true];

        $ts = strtotime((string) $t['at']) ?: time();
        return [
            'amount' => $amount, 'status' => (string) $t['status_label'], 'ok' => self::issuable($t),
            'line' => (isset($t['payee']) ? 'Withdrawal to bank' : (string) $t['title']) . ' · ' . date('j M Y, g:i a', $ts),
            'key' => strtoupper((string) $t['key']), 'rows' => $rows, 'ids' => $ids,
        ];
    }

    // ══ the layout ══════════════════════════════════════════════════════════════

    /**
     * Every drawing operation, in CSS pixels on the 794×1123 card.
     *
     * Ops: ['rect', x, y, w, h, token, radius] · ['text', s, x, baseline, px, face, token, align]
     *      ['dash', x1, x2, y, token] · ['logo', x, y, size] · ['wm']
     * Faces: text, semi, bold, black, mono, monob.
     *
     * @param callable(string,float,string):float $measure width of a string at a px size in a face
     * @return list<array<int,mixed>>
     */
    public static function layout(array $f, string $host, string $generated, callable $measure): array
    {
        $cx = (self::W - self::CARD_W) / 2;
        $cr = $cx + self::CARD_W;
        $in = $cx + self::PAD; $inR = $cr - self::PAD;
        $ops = [];
        $y = 0.0;

        // Top half.
        $y += 40; $ops[] = ['logo', self::W / 2 - 32, $y, 64]; $y += 64 + 12;
        $ops[] = ['text', 'Transaction receipt', self::W / 2, $y + 13, 13, 'semi', 'soft', 'c']; $y += 18 + 22;
        $ops[] = ['text', $f['amount'], self::W / 2, $y + 46, 54, 'black', 'ink', 'c']; $y += 54 + 12;
        $pw = $measure($f['status'], 14, 'bold') + 28 + ($f['ok'] ? 24 : 0);
        $ops[] = ['rect', self::W / 2 - $pw / 2, $y, $pw, 32, $f['ok'] ? 'green-wash' : 'gold-wash', 16];
        if ($f['ok']) $ops[] = ['tick', self::W / 2 - $pw / 2 + 14, $y + 8, 16, 'green-deep'];
        $ops[] = ['text', $f['status'], self::W / 2 + ($f['ok'] ? 12 : 0), $y + 21, 14, 'bold', $f['ok'] ? 'green-deep' : 'gold-ink', 'c'];
        $y += 32 + 12;
        $ops[] = ['text', $f['line'], self::W / 2, $y + 13, 13.5, 'text', 'soft', 'c']; $y += 18 + 30;

        // The perforation.
        $ops[] = ['notch', $cx, $cr, $y + 14];
        $ops[] = ['dash', $cx + 26, $cr - 26, $y + 14, 'line'];
        $y += 28;

        // The people and the money.
        $y += 22;
        foreach ($f['rows'] as $r) {
            $ops[] = ['text', $r['k'], $in, $y + 14, 14, 'text', 'soft', 'l'];
            $ops[] = ['text', $r['v'], $inR, $y + 15, 15, $r['sub'] === '' && ($r['subm'] ?? '') === '' ? ($r['green'] ? 'bold' : 'semi') : 'bold', $r['green'] ? 'green-deep' : 'ink', 'r'];
            $h = 19;
            if ($r['sub'] !== '' || ($r['subm'] ?? '') !== '') {
                // The account number is a code, so it is set in mono beside the bank's name.
                $m = (string) ($r['subm'] ?? '');
                $mw = $m !== '' ? $measure($m, 13.5, 'mono') : 0.0;
                if ($m !== '') $ops[] = ['text', $m, $inR, $y + 19 + 3 + 13, 13.5, 'mono', 'ink-2', 'r'];
                $ops[] = ['text', $r['sub'], $inR - $mw - ($m !== '' ? 5 : 0), $y + 19 + 3 + 13, 13.5, 'text', 'ink-2', 'r'];
                $h += 20;
            }
            $y += $h + 18;
        }
        $y += 10 - 18;

        // The numbers.
        $y += 16;
        $bh = 18 * 2 + count($f['ids']) * 18 + (count($f['ids']) - 1) * 12;
        $ops[] = ['rect', $in, $y, $inR - $in, $bh, 'idblock', 16];
        $iy = $y + 18;
        foreach ($f['ids'] as $id) {
            $ops[] = ['text', $id['k'], $in + 20, $iy + 13, 13, 'text', 'soft', 'l'];
            $ops[] = ['text', $id['v'], $inR - 20, $iy + 13, $id['soft'] ? 13 : 13.5, $id['soft'] ? 'mono' : 'monob', $id['soft'] ? 'ink-2' : 'ink', 'r'];
            $iy += 18 + 12;
        }
        $y += $bh;

        // The support line.
        $y += 24;
        $help = 'Not received? Contact support' . ($host !== '' ? ' at ' . $host . '/support' : '') . ' and quote';
        $ops[] = ['text', $help, self::W / 2, $y + 13, 12.5, 'text', 'ink-2', 'c'];
        // The number on its own line, in mono, so it is copied exactly.
        $kw = $measure($f['key'], 12.5, 'monob'); $dw = $measure('.', 12.5, 'text');
        $ops[] = ['text', $f['key'], self::W / 2 - ($kw + $dw) / 2, $y + 13 + 20, 12.5, 'monob', 'ink', 'l'];
        $ops[] = ['text', '.', self::W / 2 - ($kw + $dw) / 2 + $kw, $y + 13 + 20, 12.5, 'text', 'ink-2', 'l'];
        $y += 40 + 36;

        // Centre the card in the page, then the generated line under it.
        $cardH = $y;
        $top = max(48.0, (self::H - $cardH - 22 - 16) / 2);
        $out = [['bg'], ['wm'], ['shadow', $cx, $top, self::CARD_W, $cardH], ['rect', $cx, $top, self::CARD_W, $cardH, 'surface', 28]];
        foreach ($ops as $o) {
            $o2 = $o;
            switch ($o[0]) {
                case 'logo': case 'tick': $o2[2] += $top; break;
                case 'text': $o2[3] += $top; break;
                case 'rect': $o2[2] += $top; break;
                case 'dash': case 'notch': $o2[3] += $top; break;
            }
            $out[] = $o2;
        }
        $out[] = ['text', 'Africa GATES · Afrovanguard · generated ' . $generated, self::W / 2, $top + $cardH + 22 + 12, 12, 'text', 'ink-2', 'c'];
        return $out;
    }

    // ══ the PNG ═════════════════════════════════════════════════════════════════

    public function png(array $f, string $host, string $generated): string
    {
        $s = 2;
        $im = imagecreatetruecolor(self::W * $s, self::H * $s);
        imagealphablending($im, true);
        imagesavealpha($im, false);
        $faces = self::faces();
        $col = static function (string $token) use ($im): int {
            [$r, $g, $b] = self::rgb($token);
            return (int) imagecolorallocate($im, $r, $g, $b);
        };
        $pt = static fn (float $px): float => $px * $s * 0.75;
        $measure = fn (string $t, float $px, string $face): float => $this->width($t, $px * 0.75, $faces[$face]);

        foreach (self::layout($f, $host, $generated, $measure) as $o) {
            switch ($o[0]) {
                case 'bg':
                    imagefilledrectangle($im, 0, 0, self::W * $s - 1, self::H * $s - 1, $col('green-wash'));
                    break;
                case 'wm':
                    $this->watermark($im, $s);
                    break;
                case 'shadow':
                    $this->shadow($im, $s, $o[1], $o[2], $o[3], $o[4]);
                    break;
                case 'rect':
                    $this->roundRect($im, $o[1] * $s, $o[2] * $s, $o[3] * $s, $o[4] * $s, $o[6] * $s, $col($o[5]));
                    break;
                case 'notch':
                    imagefilledellipse($im, (int) ($o[1] * $s), (int) ($o[3] * $s), 28 * $s, 28 * $s, $col('green-wash'));
                    imagefilledellipse($im, (int) ($o[2] * $s), (int) ($o[3] * $s), 28 * $s, 28 * $s, $col('green-wash'));
                    break;
                case 'dash':
                    $c = $col($o[4]);
                    for ($x = $o[1] * $s; $x < $o[2] * $s; $x += 10 * $s) {
                        imagefilledrectangle($im, (int) $x, (int) ($o[3] * $s - 1), (int) min($x + 6 * $s, $o[2] * $s), (int) ($o[3] * $s + 2), $c);
                    }
                    break;
                case 'tick':
                    $c = $col($o[4]);
                    imagesetthickness($im, 3 * $s);
                    $x0 = $o[1] * $s; $y0 = $o[2] * $s; $u = $o[3] * $s / 24;
                    imageline($im, (int) ($x0 + 5 * $u), (int) ($y0 + 12.5 * $u), (int) ($x0 + 10 * $u), (int) ($y0 + 17.5 * $u), $c);
                    imageline($im, (int) ($x0 + 10 * $u), (int) ($y0 + 17.5 * $u), (int) ($x0 + 19 * $u), (int) ($y0 + 7 * $u), $c);
                    imagesetthickness($im, 1);
                    break;
                case 'logo':
                    $logo = self::logo();
                    if ($logo) {
                        imagecopyresampled($im, $logo, (int) ($o[1] * $s), (int) ($o[2] * $s), 0, 0, $o[3] * $s, $o[3] * $s, imagesx($logo), imagesy($logo));
                    }
                    break;
                case 'text':
                    [, $str, $x, $base, $px, $face, $tok, $align] = $o;
                    $font = $faces[$face];
                    $w = $this->width($str, $pt($px), $font);
                    $x = $x * $s - ($align === 'c' ? $w / 2 : ($align === 'r' ? $w : 0));
                    $this->text($im, $str, $pt($px), $font, $col($tok), $x, $base * $s);
                    break;
            }
        }
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /**
     * The DC's watermark: the logo mark tiled every 120px, turned 18° anticlockwise, at five
     * per cent. Drawn on its own layer over the wash, turned once, then merged — so the
     * mark is a texture and never competes with a word.
     */
    private function watermark($im, int $s): void
    {
        $logo = self::logo();
        if (!$logo) return;
        $W = self::W * $s; $H = self::H * $s;
        $side = (int) ceil(sqrt($W * $W + $H * $H));
        $layer = imagecreatetruecolor($side, $side);
        [$r, $g, $b] = self::rgb('green-wash');
        $wash = (int) imagecolorallocate($layer, $r, $g, $b);
        imagefilledrectangle($layer, 0, 0, $side - 1, $side - 1, $wash);
        imagealphablending($layer, true);
        $t = 120 * $s;
        for ($y = 0; $y < $side; $y += $t) {
            for ($x = 0; $x < $side; $x += $t) imagecopyresampled($layer, $logo, $x, $y, 0, 0, $t, $t, imagesx($logo), imagesy($logo));
        }
        $turned = imagerotate($layer, 18, $wash);
        imagedestroy($layer);
        if ($turned === false) return;
        $ox = (int) ((imagesx($turned) - $W) / 2); $oy = (int) ((imagesy($turned) - $H) / 2);
        imagecopymerge($im, $turned, 0, 0, $ox, $oy, $W, $H, 6);
        imagedestroy($turned);
    }

    /**
     * The card's soft shadow (0 10px 40px ink at 8%): a blurred silhouette, blurred small
     * and scaled up because GD's blur is a 3×3 kernel, then merged under the card.
     */
    private function shadow($im, int $s, float $x, float $y, float $w, float $h): void
    {
        $q = 8; // the blur layer is an eighth of the canvas
        $lw = (int) ceil(self::W * $s / $q); $lh = (int) ceil(self::H * $s / $q);
        $l = imagecreatetruecolor($lw, $lh);
        [$r, $g, $b] = self::rgb('green-wash');
        imagefilledrectangle($l, 0, 0, $lw - 1, $lh - 1, (int) imagecolorallocate($l, $r, $g, $b));
        [$r, $g, $b] = self::rgb('ink');
        $this->roundRect($l, $x * $s / $q, ($y + 10) * $s / $q, $w * $s / $q, $h * $s / $q, 28 * $s / $q, (int) imagecolorallocate($l, $r, $g, $b));
        for ($i = 0; $i < 12; $i++) imagefilter($l, IMG_FILTER_GAUSSIAN_BLUR);
        $big = imagecreatetruecolor(self::W * $s, self::H * $s);
        imagecopyresampled($big, $l, 0, 0, 0, 0, self::W * $s, self::H * $s, $lw, $lh);
        imagecopymerge($im, $big, 0, 0, 0, 0, self::W * $s, self::H * $s, 8);
        imagedestroy($l); imagedestroy($big);
    }

    // ══ the PDF ═════════════════════════════════════════════════════════════════

    public function pdf(array $f, string $host, string $generated): string
    {
        $mm = 210 / self::W;
        $pdf = new Pdf(210.0, 297.0);
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';
        $pdf->font('text', $dir . 'AGText-Regular.ttf');
        $pdf->font('bold', $dir . 'AGText-Bold.ttf');
        $pdf->font('mono', $dir . 'AGMono-Bold.ttf');
        $pdf->addPage();
        $alias = ['text' => 'text', 'semi' => 'bold', 'bold' => 'bold', 'black' => 'bold', 'mono' => 'mono', 'monob' => 'mono'];
        $pt = static fn (float $px): float => $px * 0.75;
        $measure = static fn (string $t, float $px, string $face): float => $pdf->width($t, $alias[$face], $pt($px)) / $mm;
        $c = [self::class, 'rgb'];

        foreach (self::layout($f, $host, $generated, $measure) as $o) {
            switch ($o[0]) {
                case 'bg':
                    $pdf->rect(0, 0, 210, 297, $c('green-wash'));
                    break;
                case 'rect':
                    // No rounded corners: the PDF writer draws rectangles and rules only.
                    $pdf->rect($o[1] * $mm, $o[2] * $mm, $o[3] * $mm, $o[4] * $mm, $c($o[5]));
                    break;
                case 'dash':
                    $pdf->line($o[1] * $mm, $o[3] * $mm, $o[2] * $mm, $o[3] * $mm, $c($o[4]), 0.5, [1.6, 1.6]);
                    break;
                case 'logo':
                    $jpeg = self::logoJpeg();
                    if ($jpeg !== '') $pdf->image($jpeg, $o[1] * $mm, $o[2] * $mm, $o[3] * $mm, $o[3] * $mm);
                    break;
                case 'text':
                    [, $str, $x, $base, $px, $face, $tok, $align] = $o;
                    $w = $pdf->width($str, $alias[$face], $pt($px));
                    $xm = $x * $mm - ($align === 'c' ? $w / 2 : ($align === 'r' ? $w : 0));
                    $pdf->text($str, $xm, $base * $mm, $alias[$face], $pt($px), $c($tok));
                    break;
            }
        }
        return $pdf->output();
    }

    /**
     * A token's colour, and the one derived fill: the ID block is the DC's warm grey, which
     * is the neutral fill (`tint`) a third of the way into white — mixed here from the two
     * tokens rather than typed, as `color-mix()` would on a page.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function rgb(string $token): array
    {
        if ($token !== 'idblock') return WalletStatementPdf::c($token);
        $a = WalletStatementPdf::c('tint'); $b = WalletStatementPdf::c('surface');
        return [(int) round($a[0] * .32 + $b[0] * .68), (int) round($a[1] * .32 + $b[1] * .68), (int) round($a[2] * .32 + $b[2] * .68)];
    }

    // ══ assets ══════════════════════════════════════════════════════════════════

    /** @return array<string,string> */
    private static function faces(): array
    {
        $d = dirname(__DIR__, 2) . '/resources/fonts/';
        return ['text' => $d . 'DMSans-Regular.ttf', 'semi' => $d . 'DMSans-SemiBold.ttf', 'bold' => $d . 'DMSans-Bold.ttf',
                'black' => $d . 'DMSans-Bold.ttf', 'mono' => $d . 'JetBrainsMono-Medium.ttf', 'monob' => $d . 'JetBrainsMono-Bold.ttf'];
    }

    private static function logo(): mixed
    {
        static $im = null;
        if ($im === null) {
            $p = dirname(__DIR__, 2) . '/public/assets/img/logo-mark.png';
            $im = is_file($p) ? (@imagecreatefrompng($p) ?: false) : false;
        }
        return $im ?: null;
    }

    /** The mark flattened onto white, because the PDF writer takes JPEG only. */
    private static function logoJpeg(): string
    {
        $logo = self::logo();
        if (!$logo) return '';
        $w = imagesx($logo); $h = imagesy($logo);
        $flat = imagecreatetruecolor($w, $h);
        imagefilledrectangle($flat, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $logo, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($flat, null, 90);
        imagedestroy($flat);
        return (string) ob_get_clean();
    }
}
