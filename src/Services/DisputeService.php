<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Support\Carbon;

/**
 * Answering a chargeback, end to end, before the 16 hours run out.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Until now a dispute reached {@see DisputeAlert}, which told a person and named the
 * deadline — and then the only way to act was the Paystack dashboard, by hand, having
 * first found the receipt for a payment made weeks ago. Under a 16-hour clock, with
 * the alert possibly read at 2am, that is a dispute conceded by logistics rather than
 * on the facts. Paystack accepts on the merchant's behalf when the window closes and
 * refunds the customer out of the merchant's balance, so the default outcome of doing
 * nothing is paying.
 *
 * The four steps the API needs are fiddly in a way that punishes improvisation: the
 * signed upload URL lives for 30 minutes, the successful upload returns an EMPTY
 * BODY so success is a status code, a `declined` resolution is rejected outright
 * without an `uploaded_filename`, and a fraud claim needs structured evidence on top
 * of the file. Getting any one of them wrong looks like "Paystack refused" and burns
 * hours of a 16-hour budget.
 *
 * ── THE ONE DECISION IT WILL NOT MAKE ────────────────────────────────────────
 *
 * Whether to contest or concede. That is a judgement about a person and their money,
 * and it is the sort of thing an automated system gets confidently wrong at scale: a
 * platform that auto-contested every chargeback would be fighting genuine fraud
 * victims with a form letter. So this prepares everything, states plainly what the
 * records show, and stops. A human presses one of two buttons.
 *
 * What it WILL do without being asked is gather the evidence, because that part has
 * no judgement in it and is the part that runs out of time.
 */
final class DisputeService
{
    /** Paystack accepts the dispute for you after this long. */
    public const RESPOND_WITHIN_HOURS = 16;

    /** The only status with a clock on it. */
    public const AWAITING = 'awaiting-merchant-feedback';

    public function __construct(private readonly ?PaymentService $payments = null) {}

    private function gw(): PaymentService
    {
        return $this->payments ?? new PaymentService();
    }

    /**
     * Disputes waiting on us, newest first, each with the facts needed to decide.
     *
     * The hours remaining are computed from the dispute's own creation time rather
     * than from when this page was opened, so a queue left open in a tab does not
     * quietly become optimistic.
     *
     * @return array{ok:bool, message:string, disputes:list<array<string,mixed>>}
     */
    public function queue(int $days = 30): array
    {
        $r = $this->gw()->disputes([
            'status'  => self::AWAITING,
            'from'    => Carbon::now()->subDays(max(1, $days))->toDateString(),
            'to'      => Carbon::now()->addDay()->toDateString(),
            'perPage' => 100,
        ]);
        if (!$r['ok']) return ['ok' => false, 'message' => $r['message'], 'disputes' => []];

        $out = [];
        foreach ($r['disputes'] as $d) {
            $d['hours_left'] = self::hoursLeft($d['created_at'] ?? '');
            $d['deadline']   = self::deadline($d['created_at'] ?? '');
            // Our own record of what the payment bought, shown BEFORE anybody acts.
            // Uploading one set of numbers while displaying another would be its own
            // kind of dishonesty.
            $d['evidence']   = DisputeEvidence::facts((string) ($d['reference'] ?? ''));
            $out[] = $d;
        }
        usort($out, static fn($a, $b) => ($a['hours_left'] ?? 99) <=> ($b['hours_left'] ?? 99));

        return ['ok' => true, 'message' => '', 'disputes' => $out];
    }

    /**
     * Contest it: attach the receipt and decline.
     *
     * ── THE ORDER OF THESE STEPS IS NOT NEGOTIABLE ──────────────────────────
     *
     * 1. Build the evidence FIRST. If the receipt cannot be produced there is nothing
     *    to contest with, and finding that out after fetching a 30-minute URL wastes
     *    the URL.
     * 2. Get the signed URL, then upload IMMEDIATELY. It expires in 30 minutes and
     *    is single-use.
     * 3. A fraud claim also needs structured evidence — a file alone does not answer
     *    "this was not me", which is a question about a person, not a transaction.
     * 4. Resolve last, because it is the irreversible step. Anything that failed
     *    above must stop the flow BEFORE this, or the dispute is declined with no
     *    evidence attached — which Paystack rejects, wasting the attempt.
     *
     * @return array{ok:bool, step:string, message:string, filename?:string}
     */
    public function contest(string $disputeId, string $reference, string $message = ''): array
    {
        $fail = static fn(string $step, string $m): array => ['ok' => false, 'step' => $step, 'message' => $m];

        if (trim($disputeId) === '') return $fail('input', 'A dispute id is required.');

        // 1 · the evidence
        $bytes = DisputeEvidence::jpeg($reference);
        if ($bytes === null) {
            return $fail('evidence',
                'No receipt could be produced for ' . ($reference !== '' ? $reference : 'that reference')
                . '. Either the order is not on record here, or the bundled fonts are missing. '
                . 'Do not decline without evidence — Paystack rejects it, and the attempt is spent.');
        }
        $filename = DisputeEvidence::filename($reference);

        // 2 · a URL, used at once
        $url = $this->gw()->disputeUploadUrl($disputeId, $filename);
        if (!$url['ok']) return $fail('upload-url', $url['message']);

        $put = $this->gw()->putSignedFile($url['url'], $bytes, 'image/jpeg');
        if (!$put['ok']) return $fail('upload', $put['message']);

        // 3 · a fraud claim needs a person described, not just a transaction
        $evidenceId = null;
        $d = $this->gw()->dispute($disputeId);
        $kind = (string) ($d['dispute']['kind'] ?? 'chargeback');
        if ($kind === 'fraud') {
            $f = DisputeEvidence::facts($reference);
            $add = $this->gw()->disputeAddEvidence($disputeId, [
                'customer_email'   => (string) $f['email'],
                'customer_name'    => (string) $f['name'],
                // No physical delivery exists — the product is votes on a public
                // tally. Saying so, with the URL, is the honest answer to "prove
                // delivery" for something that has no address.
                'service_details'  => $f['say'] . ($f['proof_url'] !== ''
                                        ? ' Verifiable at ' . $f['proof_url'] : ''),
                'delivery_address' => 'Digital delivery — votes credited to a public tally, no physical address',
                'delivery_date'    => (string) ($f['minted_at'] ?: $f['paid_at']),
            ]);
            // Not fatal. The file is already uploaded and a decline with a receipt is
            // still far better than conceding because one extra field was refused.
            if ($add['ok']) $evidenceId = $add['evidence_id'];
        }

        // 4 · the irreversible step
        $res = $this->gw()->disputeResolve($disputeId, [
            'resolution'        => 'declined',
            'message'           => $message !== '' ? $message : $this->defaultDefence($reference),
            'refund_amount'     => 0,
            'uploaded_filename' => $url['filename'],
            'evidence'          => $evidenceId,
        ]);
        if (!$res['ok']) return $fail('resolve', $res['message']);

        return ['ok' => true, 'step' => 'done', 'filename' => $url['filename'],
                'message' => 'Contested, with the receipt attached.'];
    }

    /**
     * Concede it. The customer is refunded from our balance.
     *
     * Offered as a first-class action rather than left as "do nothing until it times
     * out", because the outcomes differ in a way that matters: conceding deliberately
     * is a decision with a record and a message, while letting the clock run out is
     * the same financial result with nobody's name on it, and no reviewer able to
     * tell the two apart later.
     *
     * @param int|null $refundNaira null = the full amount
     * @return array{ok:bool, step:string, message:string}
     */
    public function concede(string $disputeId, ?int $refundNaira = null, string $message = ''): array
    {
        if (trim($disputeId) === '') {
            return ['ok' => false, 'step' => 'input', 'message' => 'A dispute id is required.'];
        }
        $payload = [
            'resolution' => 'merchant-accepted',
            'message'    => $message !== '' ? $message : 'Accepted. The payment is refunded in full.',
        ];
        // Kobo. The API takes the subunit and a naira figure here would refund a
        // hundredth of what was intended.
        if ($refundNaira !== null && $refundNaira > 0) $payload['refund_amount'] = $refundNaira * 100;

        $r = $this->gw()->disputeResolve($disputeId, $payload);
        return ['ok' => (bool) $r['ok'], 'step' => $r['ok'] ? 'done' : 'resolve', 'message' => $r['message']];
    }

    /**
     * What we say when nobody writes their own message.
     *
     * Facts and a link, no adjectives. A reviewer reading a hundred of these is
     * looking for something checkable, and indignation reads as weakness.
     */
    private function defaultDefence(string $reference): string
    {
        $f = DisputeEvidence::facts($reference);
        if (!$f['found']) {
            return 'The attached receipt is our record of this payment.';
        }
        $s = 'Payment ' . $f['reference'] . ' of NGN ' . number_format((float) $f['amount'])
           . ($f['paid_at'] !== '' ? ' settled at ' . $f['paid_at'] : '') . '. ' . $f['say'] . '.';
        if ($f['proof_url'] !== '') {
            $s .= ' The credited votes can be verified by anyone, without an account, at ' . $f['proof_url'] . '.';
        }
        return mb_substr($s . ' The attached receipt records the same.', 0, 1000);
    }

    /** Hours left of the 16, or null when the dispute carries no usable timestamp. */
    public static function hoursLeft(string $createdAt): ?float
    {
        if (trim($createdAt) === '') return null;
        try {
            $left = Carbon::parse($createdAt)->addHours(self::RESPOND_WITHIN_HOURS)
                        ->diffInMinutes(Carbon::now(), false) / -60;
        } catch (\Throwable) { return null; }
        return round($left, 1);
    }

    /** The moment Paystack decides for us, as a timestamp rather than a duration. */
    public static function deadline(string $createdAt): string
    {
        if (trim($createdAt) === '') return '';
        try {
            return Carbon::parse($createdAt)->addHours(self::RESPOND_WITHIN_HOURS)->toDateTimeString();
        } catch (\Throwable) { return ''; }
    }
}
