<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Every webhook a payment gateway has ever sent us, and what we did about it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS HAD TO EXIST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_webhook_deliveries` logs the webhooks this platform SENDS. Nothing logged the ones it
 * RECEIVES — which is the direction money arrives from.
 *
 * The consequence was not academic. Paystack's own status history records an incident where
 * webhook delivery itself was degraded, and its retry schedule gives up after 72 hours. So the
 * question a stuck payment always raises — "did Paystack send it and we dropped it, or was it
 * never sent?" — could not be answered from this side at all. The gateway dashboard shows
 * deliveries and their HTTP codes; it does not show what our handler decided to do, which is
 * the half that was actually going wrong: an `AFG-SHP-…` reference used to be acknowledged
 * with a 200 and discarded, and a 200 is indistinguishable from success at the far end.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS AND IS NOT STORED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The event name, our reference, the stream it routed to, the outcome, and a short note. NOT
 * the payload: it carries the customer's email, their card's last four and their bank, it is
 * the largest thing in the request, and none of it is needed to answer the question this table
 * exists for. Paystack keeps the payload; we keep the decision.
 *
 * Best-effort throughout, and it never throws. A logging failure must not turn a good webhook
 * into a retry — the whole point is to make deliveries MORE reliable, and a log that can break
 * the handler would do the opposite.
 */
final class GatewayEventLog
{
    /** What the handler did with a delivery. Kept short — this is read as a column. */
    public const OUTCOMES = [
        'confirmed'   => 'A payment was confirmed',
        'already'     => 'Already confirmed — nothing to do',
        'reversed'    => 'Money went back; the order was reversed',
        'alerted'     => 'A dispute alert was raised',
        'mismatch'    => 'Amount or currency disagreed — refused',
        'unmatched'   => 'Signed, but no record here matches the reference',
        'ignored'     => 'A step event, or one we deliberately do not act on',
        'rejected'    => 'Signature or provider check failed',
        'error'       => 'The handler threw',
    ];

    /**
     * Record one delivery.
     *
     * @param string $provider  'paystack' | 'flutterwave'
     * @param string $event     the gateway's own event name
     * @param string $reference our reference, when one could be extracted
     * @param string $stream    'shop' | 'events' | 'votes' | ''
     * @param string $outcome   a key of {@see OUTCOMES}
     */
    public static function record(string $provider, string $event, string $reference,
                                  string $stream, string $outcome, string $note = '',
                                  ?string $domain = null): void
    {
        try {
            if (!DB::schema()->hasTable('gates_gateway_events')) {
                return;                                 // not migrated yet
            }
            DB::table('gates_gateway_events')->insert([
                'provider'   => mb_substr($provider, 0, 20),
                'event'      => mb_substr($event, 0, 60),
                'reference'  => $reference !== '' ? mb_substr($reference, 0, 80) : null,
                'stream'     => $stream !== '' ? mb_substr($stream, 0, 20) : null,
                // 'test' or 'live'. Paystack allows ONE webhook URL per account and both modes
                // share it, so this field is the only thing that distinguishes a rehearsal
                // from real money after the fact.
                'domain'     => $domain !== null && $domain !== '' ? mb_substr($domain, 0, 10) : null,
                'outcome'    => mb_substr($outcome, 0, 20),
                'note'       => $note !== '' ? mb_substr($note, 0, 300) : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // Not silent — but not fatal either.
            error_log('[gateway-log] could not record ' . $event . ': ' . $e->getMessage());
        }
    }

    /**
     * Recent deliveries, newest first — what an operator reads when a payment is missing.
     *
     * @return list<object>
     */
    public static function recent(int $limit = 100, string $outcome = ''): array
    {
        try {
            $q = DB::table('gates_gateway_events')->orderByDesc('id')->limit(max(1, min(500, $limit)));
            if ($outcome !== '') $q->where('outcome', $outcome);
            return $q->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Everything we have heard about one reference, oldest first. */
    public static function forReference(string $reference): array
    {
        try {
            return DB::table('gates_gateway_events')->where('reference', trim($reference))
                ->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Has ANY delivery ever arrived?
     *
     * The single most useful diagnostic on this platform, and it needs no gateway call: an
     * empty table after a week of live payments means the webhook URL or the signing secret in
     * the dashboard is wrong, which presents to a buyer as "I paid and nothing happened" and
     * to an operator as nothing at all.
     */
    public static function everReceived(): bool
    {
        try {
            return DB::table('gates_gateway_events')->where('outcome', '!=', 'rejected')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Trim the table. Ninety days is long past any retry schedule or dispute window. */
    public static function prune(int $days = 90): int
    {
        try {
            return (int) DB::table('gates_gateway_events')
                ->where('created_at', '<', Carbon::now()->subDays(max(7, $days))->toDateTimeString())
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }
}
