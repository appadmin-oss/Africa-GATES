<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The receipt, as a file Paystack will accept as evidence.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS GENERATED AHEAD OF THE ARGUMENT, NOT DURING IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Paystack's own advice on disputes is to keep a receipts repository ready, because
 * the clock is 16 hours and the signed upload URL it hands you is valid for only 30
 * minutes. Anything that has to be built under those two constraints — a designer
 * asked for a PDF, a person asked to find an old email — is a dispute lost by
 * logistics rather than on the facts.
 *
 * So this renders from the order row on demand, in memory, in well under a second.
 * There is nothing to store and nothing to keep in sync.
 *
 * ── WHY A JPEG AND NOT A PDF ─────────────────────────────────────────────────
 *
 * Paystack accepts `.jpg`, `.jpeg` and `.pdf`. This codebase has no PDF library and
 * adding one to answer a chargeback would be a large dependency for one screen —
 * whereas GD is already present and {@see FlierService} already renders text with
 * the bundled faces for the share cards. A JPEG of the receipt is the same evidence
 * in a format the API takes, produced with what is already here.
 *
 * ── WHAT IT PUTS ON THE PAGE, AND WHY EACH LINE IS THERE ─────────────────────
 *
 * A chargeback says "I paid and got nothing". The answer is not a prettier receipt;
 * it is the specific, checkable facts that contradict that sentence:
 *
 *   · our reference AND the gateway's own transaction id, so the reviewer can line
 *     this document up against the charge in front of them
 *   · the amount and the exact time of payment
 *   · WHAT WAS DELIVERED — how many votes, to which nominee, and at what time they
 *     were written. This is the part that matters. The votes are timestamped rows in
 *     our tally, so "got nothing" is answerable with a record rather than an
 *     assertion.
 *   · the public URL where the same tally can be checked by anybody
 *
 * ── AND WHY IT NEVER INVENTS ANYTHING ────────────────────────────────────────
 *
 * Every line is read from the order and the vote rows. When a field is missing it is
 * printed as "not recorded" rather than guessed at or omitted, because a receipt
 * submitted as evidence in a dispute is a statement to a third party about somebody
 * else's money, and a plausible-looking gap is worse than an admitted one.
 */
final class DisputeEvidence
{
    private const W = 1000;
    private const H = 1414;   // A4 proportions, so it prints and reads as a document

    /**
     * Everything known about what this payment bought.
     *
     * Public because the admin screen shows the same facts on the page before
     * anybody submits them, and showing one set of numbers while uploading another
     * would be its own kind of dishonesty.
     *
     * @return array{found:bool, reference:string, gateway_ref:string, amount:int,
     *               paid_at:string, email:string, name:string, votes:int,
     *               nominee:string, minted_at:string, proof_url:string, say:string}
     */
    public static function facts(string $reference): array
    {
        $blank = ['found' => false, 'reference' => $reference, 'gateway_ref' => '', 'amount' => 0,
                  'paid_at' => '', 'email' => '', 'name' => '', 'votes' => 0, 'nominee' => '',
                  'minted_at' => '', 'proof_url' => '',
                  'say' => 'No order with that reference is on record here.'];

        // Resolve first: a dispute payload carries the GATEWAY's reference, which is
        // not necessarily the one we minted. See PaymentLookup.
        $ref = PaymentLookup::canonical(trim($reference));
        if ($ref === '') return $blank;

        try {
            $d = DB::table('gates_donations')->where('payment_ref', $ref)->first();
        } catch (\Throwable) { return $blank; }
        if ($d === null) return $blank;

        $out = [
            'found'       => true,
            'reference'   => (string) ($d->payment_ref ?? $ref),
            'gateway_ref' => trim((string) ($d->gateway_txn_id ?? '')) ?: trim((string) ($d->gateway_ref ?? '')),
            'amount'      => (int) ($d->amount_naira ?? 0),
            'paid_at'     => (string) ($d->confirmed_at ?? ($d->created_at ?? '')),
            'email'       => (string) ($d->donor_email ?? ''),
            'name'        => (string) ($d->donor_name ?? ''),
            'votes'       => 0,
            'nominee'     => '',
            'minted_at'   => '',
            'proof_url'   => '',
            'say'         => '',
        ];

        // WHAT WAS DELIVERED — read from the vote rows, not from the order's own
        // counter. The counter is our bookkeeping; the rows are the thing a reviewer
        // would want to see, and they can disagree.
        try {
            // `voted_at`, not `created_at` — gates_votes has no created_at, and reading
            // the wrong name silently produced a receipt with "Credited at: not
            // recorded" on it, which is the one line a reviewer would look at hardest.
            $votes = DB::table('gates_votes')->where('donation_id', (int) $d->id)
                ->orderBy('id')->get(['nominee_id', 'weight', 'voted_at']);
            $out['votes'] = (int) $votes->sum('weight');
            if ($votes->count() > 0) {
                $out['minted_at'] = (string) ($votes->first()->voted_at ?? '');
                $nid = (int) ($votes->first()->nominee_id ?? 0);
                if ($nid > 0) {
                    $out['nominee'] = (string) (DB::table('gates_nominees')->where('id', $nid)->value('name') ?? '');
                }
            }
        } catch (\Throwable) {}

        try {
            $out['proof_url'] = rtrim(SiteUrl::base(), '/') . '/vote/verify?ref=' . rawurlencode($out['reference']);
        } catch (\Throwable) {}

        $out['say'] = $out['votes'] > 0
            ? $out['votes'] . ' vote(s) were credited' . ($out['nominee'] !== '' ? ' to ' . $out['nominee'] : '')
              . ($out['minted_at'] !== '' ? ' at ' . $out['minted_at'] : '')
            : 'No votes were credited against this payment.';

        return $out;
    }

    /**
     * The receipt as JPEG bytes, or null when it cannot be drawn.
     *
     * Null rather than a blank image on purpose: an empty rectangle uploaded as
     * evidence is worse than no evidence, because it satisfies the API's requirement
     * for a file while telling the reviewer nothing, and the dispute is then lost
     * with a document attached.
     */
    public static function jpeg(string $reference): ?string
    {
        if (!function_exists('imagecreatetruecolor')) return null;
        $f = self::facts($reference);
        if (!$f['found']) return null;

        $regular  = self::font('DMSans-Regular.ttf');
        $bold     = self::font('DMSans-Bold.ttf');
        if ($regular === null || $bold === null) return null;

        $im = imagecreatetruecolor(self::W, self::H);
        $white = (int) imagecolorallocate($im, 255, 255, 255);
        $ink   = (int) imagecolorallocate($im, 16, 41, 44);
        $soft  = (int) imagecolorallocate($im, 106, 118, 116);
        $rule  = (int) imagecolorallocate($im, 225, 229, 228);
        $green = (int) imagecolorallocate($im, 35, 123, 34);
        imagefilledrectangle($im, 0, 0, self::W, self::H, $white);

        $x = 70;
        $y = 110;
        imagettftext($im, 30, 0, $x, $y, $ink, $bold, 'Africa GATES');
        $y += 34;
        imagettftext($im, 13, 0, $x, $y, $soft, $regular, 'Payment receipt and delivery record');
        $y += 40;
        imageline($im, $x, $y, self::W - $x, $y, $rule);
        $y += 50;

        // Every value is printed, including the missing ones. "not recorded" is a fact
        // about our records; a silently omitted row invites the reviewer to assume the
        // worst, and rightly.
        $na = static fn(string $v): string => trim($v) !== '' ? $v : 'not recorded';
        $rows = [
            ['Our reference',        $na($f['reference'])],
            ['Paystack transaction', $na($f['gateway_ref'])],
            ['Amount paid',          'NGN ' . number_format((float) $f['amount'])],
            ['Paid at',              $na($f['paid_at'])],
            ['Paid by',              $na($f['email'])],
            ['Account name given',   $na($f['name'])],
        ];
        foreach ($rows as [$k, $v]) {
            imagettftext($im, 12, 0, $x, $y, $soft, $regular, $k);
            imagettftext($im, 15, 0, $x + 300, $y, $ink, $bold, $v);
            $y += 44;
        }

        $y += 16;
        imageline($im, $x, $y, self::W - $x, $y, $rule);
        $y += 50;
        imagettftext($im, 16, 0, $x, $y, $ink, $bold, 'What was delivered');
        $y += 40;

        $delivered = [
            ['Votes credited',  $f['votes'] > 0 ? (string) $f['votes'] : 'none'],
            ['Credited to',     $na($f['nominee'])],
            ['Credited at',     $na($f['minted_at'])],
        ];
        foreach ($delivered as [$k, $v]) {
            imagettftext($im, 12, 0, $x, $y, $soft, $regular, $k);
            imagettftext($im, 15, 0, $x + 300, $y, $f['votes'] > 0 ? $green : $ink, $bold, $v);
            $y += 44;
        }

        $y += 30;
        foreach (self::wrap($f['votes'] > 0
            ? 'The votes above are timestamped records in this platform\'s public tally, credited '
            . 'automatically when the payment settled. They can be checked at the address below by '
            . 'anyone, without an account.'
            : 'No votes were credited against this payment. If the money was taken, it is owed back and '
            . 'this platform refunds it automatically.', 74) as $line) {
            imagettftext($im, 13, 0, $x, $y, $soft, $regular, $line);
            $y += 26;
        }

        if ($f['proof_url'] !== '') {
            $y += 22;
            imagettftext($im, 12, 0, $x, $y, $soft, $regular, 'Verify independently');
            $y += 28;
            foreach (self::wrap($f['proof_url'], 60) as $line) {
                imagettftext($im, 13, 0, $x, $y, $green, $bold, $line);
                $y += 24;
            }
        }

        // Stamped with when the document was produced, so a reviewer can tell this
        // receipt from an earlier one for the same payment.
        //
        // The host is READ, not written in. It was hardcoded as "africagates.org"
        // first time, and the live site is afg.afrovanguard.org.ng — a document sent
        // to a third party arguing about somebody's money is the last place to name a
        // domain that is not ours.
        $host = '';
        try { $host = (string) (parse_url(SiteUrl::base(), PHP_URL_HOST) ?: ''); } catch (\Throwable) {}
        imagettftext($im, 11, 0, $x, self::H - 70, $soft, $regular,
            'Generated ' . gmdate('Y-m-d H:i:s') . ' UTC' . ($host !== '' ? ' · ' . $host : ''));

        ob_start();
        imagejpeg($im, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes !== '' ? $bytes : null;
    }

    /** The filename Paystack is asked to store this under. */
    public static function filename(string $reference): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $reference) ?: 'receipt';
        return 'receipt-' . mb_substr($safe, 0, 60) . '.jpg';
    }

    /** A bundled face, or null when it is not readable — GD draws nothing without one. */
    private static function font(string $file): ?string
    {
        $p = dirname(__DIR__, 2) . '/resources/fonts/' . $file;
        return is_readable($p) ? $p : null;
    }

    /** @return list<string> */
    private static function wrap(string $text, int $cols): array
    {
        $w = wordwrap($text, $cols, "\n", true);
        return explode("\n", $w);
    }
}
