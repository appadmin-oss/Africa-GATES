<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What CACENTRE finance needs to read off a payment: its kind, and the labels a sharing plan
 * matches on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY EVERY PAYMENT CARRIES `cfis_kind`
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Finance pulls Paystack's settlements itself and reads each payment's kind out of its
 * metadata — `cfis_kind`, then `kind`, then `purpose`. Nothing here ever sent the first two,
 * so finance read the PURPOSE: `paid-vote`, `vote`, `event`, `shop`, `stand`. Only `donation`
 * is a stream finance knows, so every other settlement would have been refused as an unknown
 * kind, days after the money was taken. This is the one translation, applied on the one road
 * every Paystack payment takes ({@see PaymentService::initialize()}).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * LABELS ARE FOR SHARING, AND ARE ONLY WHAT IS KNOWN AT CHECKOUT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A paid vote names its award, edition and category; a ticket or a stand names its event.
 * Finance groups a payout by kind and labels, and a revenue-sharing plan takes the lines it
 * matches. The community return on a payment is NOT here: it is worked out after the payment
 * is confirmed and can be reversed later, so {@see CfisReturns} reports it separately.
 *
 * Never throws. This runs inside a checkout; a label it cannot find is a label left off.
 */
final class CfisTags
{
    /** This platform's purposes, as finance's stream codes. */
    public const KINDS = [
        'paid-vote' => 'voting',
        'vote'      => 'voting',
        'ticket'    => 'ticket_sales',
        'event'     => 'ticket_sales',
        'stand'     => 'sponsorship',
        'child'     => 'donation',
        'donation'  => 'donation',
        'shop'      => 'merchandise',
    ];

    /**
     * The tags for one payment, to merge into the gateway's metadata. A caller that already
     * set `cfis_kind` or `cfis_labels` keeps its own.
     *
     * @return array{cfis_kind?:string, cfis_labels?:array<string,string>}
     */
    public static function for(string $reference, array $meta): array
    {
        $out = [];
        $kind = self::KINDS[(string) ($meta['purpose'] ?? '')] ?? null;
        if ($kind !== null) $out['cfis_kind'] = $kind;

        try {
            $labels = self::labels($reference, $meta);
        } catch (\Throwable) {
            $labels = [];
        }
        if ($labels !== []) $out['cfis_labels'] = $labels;

        return $out;
    }

    /** @return array<string,string> */
    private static function labels(string $reference, array $meta): array
    {
        switch ((string) ($meta['purpose'] ?? '')) {
            case 'paid-vote':
                $nominee = (int) DB::table('gates_donations')->where('payment_ref', $reference)->value('intent_nominee_id');
                return $nominee > 0 ? self::award($nominee) : [];
            case 'event':
                $slug = trim((string) ($meta['event'] ?? ''));
                return $slug !== '' ? ['event' => $slug] : [];
            case 'stand':
                $slug = (string) DB::table('gates_site_events')->where('id', (int) ($meta['event'] ?? 0))->value('slug');
                return $slug !== '' ? ['event' => $slug] : [];
        }
        return [];
    }

    /**
     * A nominee's award, edition and category. The edition is the programme's slug and the
     * year (`teachers-2026`), the shape a plan's "edition = …" condition is written in.
     *
     * @return array<string,string>
     */
    public static function award(int $nomineeId): array
    {
        $r = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'y.programme_id')
            ->where('n.id', $nomineeId)
            ->select('p.slug as programme', 'y.year', 'c.slug as category')->first();
        if (!$r) return [];

        return array_filter([
            'programme' => (string) $r->programme,
            'edition'   => $r->year ? $r->programme . '-' . $r->year : '',
            'category'  => (string) $r->category,
        ], static fn ($v) => $v !== '');
    }
}
