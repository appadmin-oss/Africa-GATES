<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Paystack's books against ours, read from Paystack's side first.
 *
 * ── THE QUESTION THIS ANSWERS THAT NOTHING ELSE COULD ────────────────────────
 *
 * {@see PaymentTriage} asks "which of OUR orders did the gateway actually
 * charge?" — it starts from `gates_donations` and calls `verify()` per row. That
 * finds a payment we started and then mishandled, which is the common failure.
 *
 * It cannot find the other one. If a charge exists at Paystack and there is no
 * row here at all — the insert failed, the request died between initialize and
 * insert, or the money came in through a Payment Page or a POS terminal that this
 * codebase never knew about — then there is nothing on our side to iterate, so no
 * amount of verifying our rows will ever surface it. The person paid and there is
 * no record on this side that they exist.
 *
 * That bucket has been invisible by construction since the platform launched,
 * which is why "I do not see the transactions from Paystack" was an accurate
 * observation and not a UI complaint. This class exists to make it visible: it
 * walks the GATEWAY's list for a window and asks our database about each one,
 * which is the only direction of the comparison that can find a stranger.
 *
 * ── WHY IT LOOKS IN FOUR TABLES AND NOT JUST gates_donations ─────────────────
 *
 * One Paystack account collects for several things here: paid votes and donations
 * (`gates_donations.payment_ref`), the shop (`gates_orders.reference` and its
 * `provider_ref`) and paid event registrations
 * (`gates_event_registrations.reference`). A reconciler that only knew about
 * donations would file every shop order under "money we never recorded" and the
 * page would cry wolf on its first run — at which point nobody reads it again,
 * and the one real orphan sitting in the same list goes unnoticed. So a reference
 * is resolved against every ledger that can legitimately own one, and the bucket
 * reports WHICH ledger claimed it.
 *
 * Tables are probed with `hasTable()` because this schema drifts: a deployment
 * without the shop must reconcile, not fatal.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────────
 *
 * Nothing here writes. It reads Paystack, reads our tables, and returns a
 * comparison. Confirming an order, minting votes or refunding a stranger's charge
 * are decisions with money attached, and they already have their own guarded paths
 * in {@see PaymentTriage} and {@see RefundService}. A screen that reconciled and
 * repaired in one motion would make "let me just look" a money-moving action.
 */
final class GatewayLedger
{
    /** Longest window a single pull may cover. Beyond this, page it by date. */
    public const MAX_DAYS = 180;

    public function __construct(private readonly ?PaymentService $payments = null) {}

    private function payments(): PaymentService
    {
        return $this->payments ?? new PaymentService();
    }

    /**
     * Pull a window from Paystack and compare it with every ledger we keep.
     *
     * Only `success` transactions are pulled. A failed or abandoned attempt at the
     * gateway with no row here is not a discrepancy — it is a person who opened
     * checkout and changed their mind, and there are far more of those than of
     * anything worth acting on. Including them would bury the four rows that
     * matter under four hundred that do not.
     *
     * @return array{
     *   ok:bool, message:string, from:string, to:string, days:int, truncated:bool,
     *   groups:array{agreed:list<array>, mismatch:list<array>, theirs:list<array>, ours:list<array>},
     *   counts:array<string,int>, naira:array<string,int>
     * }
     */
    public function pull(int $days = 30, ?string $to = null): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $end   = $to !== null && $to !== '' ? strtotime($to) : time();
        if ($end === false) $end = time();
        $start = $end - $days * 86400;

        $from = date('Y-m-d\TH:i:s\Z', $start);
        $till = date('Y-m-d\TH:i:s\Z', $end);

        $empty = [
            'ok' => false, 'message' => '', 'from' => $from, 'to' => $till, 'days' => $days,
            'truncated' => false,
            'groups' => ['agreed' => [], 'mismatch' => [], 'theirs' => [], 'ours' => []],
            'counts' => ['agreed' => 0, 'mismatch' => 0, 'theirs' => 0, 'ours' => 0, 'gateway' => 0],
            'naira'  => ['agreed' => 0, 'mismatch' => 0, 'theirs' => 0, 'ours' => 0, 'gateway' => 0],
        ];

        $res = $this->payments()->listTransactions('paystack', [
            'status' => 'success', 'from' => $from, 'to' => $till,
        ]);

        if (!$res['ok']) return array_merge($empty, ['message' => (string) $res['message']]);

        $groups = ['agreed' => [], 'mismatch' => [], 'theirs' => [], 'ours' => []];
        $matchedRefs = [];

        foreach ($res['transactions'] as $t) {
            $ref  = (string) $t['reference'];
            $ours = $ref === '' ? null : $this->findLocal($ref);

            if ($ours === null) {
                // Money at Paystack with nothing on this side. The bucket that
                // could not previously be reached from any query we owned.
                $groups['theirs'][] = ['gateway' => $t];
                continue;
            }

            $matchedRefs[$ref] = true;
            $problems = $this->disagreements($t, $ours);

            if ($problems === []) $groups['agreed'][]   = ['gateway' => $t, 'local' => $ours];
            else                  $groups['mismatch'][] = ['gateway' => $t, 'local' => $ours, 'why' => $problems];
        }

        // The other direction: a row of ours marked confirmed that the gateway's
        // own list for this window does not contain. Either it was confirmed
        // against evidence the gateway no longer stands behind, or it was
        // confirmed by hand. Both are worth a person's attention; neither is
        // findable by asking the gateway about references it already gave us.
        foreach ($this->localConfirmed($start, $end) as $row) {
            $ref = (string) ($row['reference'] ?? '');
            if ($ref !== '' && isset($matchedRefs[$ref])) continue;
            $groups['ours'][] = ['local' => $row];
        }

        $counts = ['gateway' => count($res['transactions'])];
        $naira  = ['gateway' => 0];
        foreach ($res['transactions'] as $t) $naira['gateway'] += (int) $t['amount'];

        foreach ($groups as $k => $rows) {
            $counts[$k] = count($rows);
            $naira[$k]  = 0;
            foreach ($rows as $r) {
                $naira[$k] += (int) ($r['gateway']['amount'] ?? $r['local']['amount'] ?? 0);
            }
        }

        return [
            'ok' => true,
            'message'   => $res['message'] === 'ok' ? '' : $res['message'],
            'from' => $from, 'to' => $till, 'days' => $days,
            'truncated' => (bool) $res['truncated'],
            'groups' => $groups, 'counts' => $counts, 'naira' => $naira,
        ];
    }

    /** Where the last comparison is kept, so a screen can read it without asking Paystack. */
    private const LAST_RUN_KEY = 'gateway_ledger_last';

    /**
     * Remember what the last comparison found.
     *
     * ── WHY THIS IS STORED AT ALL ────────────────────────────────────────────
     *
     * The one bucket the triage screen structurally CANNOT find is a charge at Paystack with
     * no row on this side: there is nothing local to iterate, so no query we own will ever
     * surface it. That is why "the ledger saw the misses and the triage did not" stayed true
     * even after triage was taught to re-ask its own written-off rows — the last gap is
     * money belonging to people this platform has no record of.
     *
     * Reaching it needs an outbound walk of Paystack's list, which is up to twenty paginated
     * calls and therefore cannot happen on page load. So the RESULT is kept: triage can then
     * say "3 charges at Paystack have no record here, found on the 14th" without making a
     * single request, and — more importantly — can say "this has never been compared", which
     * is the honest description of the state that made the misses invisible in the first
     * place.
     *
     * Counts only. The rows are not stored: they contain customer email addresses, they go
     * stale within minutes of being written, and a screen that presented a week-old list as
     * current would be worse than one that admits it needs a fresh look.
     *
     * @param array<string,mixed> $result as returned by {@see pull()}
     */
    public static function remember(array $result): void
    {
        if (!($result['ok'] ?? false)) return;

        $payload = [
            'at'      => Carbon::now()->toDateTimeString(),
            'days'    => (int) ($result['days'] ?? 0),
            'counts'  => array_map('intval', (array) ($result['counts'] ?? [])),
            'naira'   => array_map('intval', (array) ($result['naira'] ?? [])),
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];

        try {
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => self::LAST_RUN_KEY],
                ['value' => json_encode($payload, JSON_UNESCAPED_SLASHES)]
            );
        } catch (\Throwable $e) {
            error_log('[ledger] could not remember the last comparison: ' . $e->getMessage());
        }
    }

    /**
     * What the last comparison found, or null when there has never been one.
     *
     * Null is the interesting answer and the caller must say so out loud rather than
     * rendering a row of zeroes: a platform that has never compared its books with the
     * gateway does not have a clean window, it has an unexamined one.
     *
     * @return array{at:string, days:int, counts:array<string,int>, naira:array<string,int>,
     *               truncated:bool, stale:bool}|null
     */
    public static function lastRun(): ?array
    {
        try {
            $raw = DB::table('gates_settings')->where('key_name', self::LAST_RUN_KEY)->value('value');
        } catch (\Throwable) { return null; }

        $j = json_decode((string) $raw, true);
        if (!is_array($j) || ($j['at'] ?? '') === '') return null;

        $at = (string) $j['at'];
        return [
            'at'     => $at,
            'days'   => (int) ($j['days'] ?? 0),
            'counts' => array_map('intval', (array) ($j['counts'] ?? [])),
            'naira'  => array_map('intval', (array) ($j['naira'] ?? [])),
            'truncated' => (bool) ($j['truncated'] ?? false),
            // A comparison older than a week is a fact about last week. Said rather than
            // implied, because a stale clean bill reads exactly like a current one.
            'stale'  => strtotime($at) < time() - 7 * 86400,
        ];
    }

    /**
     * Where a gateway reference lives on our side, if anywhere.
     *
     * Returns a flat, ledger-agnostic row so the caller and the template do not
     * each need a branch per table. `ledger` names the table in words; `status`
     * is that ledger's own status vocabulary, not a translated one, because
     * flattening "paid" and "confirmed" into a single invented word is how an
     * operator ends up reading a status that exists nowhere in the database.
     */
    private function findLocal(string $ref): ?array
    {
        $schema = DB::schema();

        if ($schema->hasTable('gates_donations')) {
            $r = DB::table('gates_donations')->where('payment_ref', $ref)->first();
            if ($r) {
                return [
                    'ledger' => 'Votes / donations', 'table' => 'gates_donations',
                    'id' => (int) $r->id, 'reference' => $ref,
                    'amount' => (int) $r->amount_naira,
                    'status' => (string) $r->status,
                    'settled' => (string) $r->status === 'confirmed',
                    'email' => (string) ($r->donor_email ?? ''),
                    'name'  => (string) ($r->donor_name ?? ''),
                    'created_at' => (string) ($r->created_at ?? ''),
                    'note' => isset($r->refunded_at) && $r->refunded_at ? 'refunded ' . $r->refunded_at : '',
                ];
            }
        }

        if ($schema->hasTable('gates_orders')) {
            $r = DB::table('gates_orders')
                ->where('reference', $ref)->orWhere('provider_ref', $ref)->first();
            if ($r) {
                return [
                    'ledger' => 'Shop order', 'table' => 'gates_orders',
                    'id' => (int) $r->id, 'reference' => $ref,
                    'amount' => (int) $r->subtotal_naira,
                    'status' => (string) $r->status,
                    'settled' => in_array((string) $r->status, ['paid', 'confirmed', 'fulfilled'], true),
                    'email' => (string) ($r->email ?? ''),
                    'name'  => (string) ($r->name ?? ''),
                    'created_at' => (string) ($r->created_at ?? ''),
                    'note' => '',
                ];
            }
        }

        if ($schema->hasTable('gates_event_registrations')) {
            $r = DB::table('gates_event_registrations')->where('reference', $ref)->first();
            if ($r) {
                return [
                    'ledger' => 'Event registration', 'table' => 'gates_event_registrations',
                    'id' => (int) $r->id, 'reference' => $ref,
                    'amount' => (int) ($r->amount_naira ?? 0),
                    'status' => 'registered',
                    'settled' => true,
                    'email' => (string) ($r->email ?? ''),
                    'name'  => (string) ($r->name ?? ''),
                    'created_at' => (string) ($r->created_at ?? ''),
                    'note' => '',
                ];
            }
        }

        return null;
    }

    /**
     * What each side says that the other contradicts.
     *
     * Phrased as a list of sentences rather than a code, because the operator
     * acting on this is not reading an enum and the three cases want three
     * different actions: a pending row wants repair, a short payment wants a
     * decision, a refunded row wants nothing at all.
     *
     * @return list<string>
     */
    private function disagreements(array $t, array $ours): array
    {
        $why = [];

        if (!$ours['settled']) {
            $why[] = 'Paystack took ₦' . number_format((int) $t['amount'])
                   . ' but our row is still “' . $ours['status'] . '”.';
        }
        if ((int) $t['amount'] < (int) $ours['amount']) {
            $why[] = 'Short: Paystack collected ₦' . number_format((int) $t['amount'])
                   . ' against an order of ₦' . number_format((int) $ours['amount']) . '.';
        }
        if ((int) $t['amount'] > (int) $ours['amount']) {
            $why[] = 'Over: Paystack collected ₦' . number_format((int) $t['amount'])
                   . ' against an order of ₦' . number_format((int) $ours['amount']) . '.';
        }
        if (($ours['note'] ?? '') !== '') {
            $why[] = 'Our row is marked ' . $ours['note'] . '.';
        }

        return $why;
    }

    /**
     * Our settled rows in the same window, flattened the same way.
     *
     * Only settled ones. A pending row that the gateway has no successful charge
     * for is the ordinary end of an abandoned checkout — the platform creates one
     * every time somebody opens the payment page and walks away — and listing
     * those here would make the "we say paid, they do not" column meaningless.
     *
     * @return list<array>
     */
    private function localConfirmed(int $start, int $end): array
    {
        $schema = DB::schema();
        $from = date('Y-m-d H:i:s', $start);
        $to   = date('Y-m-d H:i:s', $end);
        $out  = [];

        if ($schema->hasTable('gates_donations')) {
            $rows = DB::table('gates_donations')
                ->where('status', 'confirmed')
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id')->get();
            foreach ($rows as $r) {
                $out[] = [
                    'ledger' => 'Votes / donations', 'table' => 'gates_donations',
                    'id' => (int) $r->id, 'reference' => (string) ($r->payment_ref ?? ''),
                    'amount' => (int) $r->amount_naira, 'status' => (string) $r->status,
                    'settled' => true,
                    'email' => (string) ($r->donor_email ?? ''), 'name' => (string) ($r->donor_name ?? ''),
                    'created_at' => (string) ($r->created_at ?? ''), 'note' => '',
                ];
            }
        }

        if ($schema->hasTable('gates_orders')) {
            $rows = DB::table('gates_orders')
                ->whereIn('status', ['paid', 'confirmed', 'fulfilled'])
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id')->get();
            foreach ($rows as $r) {
                $out[] = [
                    'ledger' => 'Shop order', 'table' => 'gates_orders',
                    'id' => (int) $r->id,
                    'reference' => (string) ($r->provider_ref ?: $r->reference),
                    'amount' => (int) $r->subtotal_naira, 'status' => (string) $r->status,
                    'settled' => true,
                    'email' => (string) ($r->email ?? ''), 'name' => (string) ($r->name ?? ''),
                    'created_at' => (string) ($r->created_at ?? ''), 'note' => '',
                ];
            }
        }

        return $out;
    }
}
