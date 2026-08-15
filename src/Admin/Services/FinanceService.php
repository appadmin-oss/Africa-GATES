<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What the platform has actually been paid — read from every place money lands.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * The answer to "how much has come in?" was not available anywhere in the admin.
 * The dashboard counts profiles, votes and nominations; the data explorer lists
 * `gates_donations` as rows you can page through. Neither adds anything up, and
 * neither knows that money arrives through THREE unrelated tables with three
 * different shapes and three different words for "paid":
 *
 *   • gates_donations         — donations AND paid votes AND anything
 *                               PaymentController takes. status confirmed/pending/failed.
 *   • gates_orders            — the shop. subtotal_naira, status paid/pending/failed.
 *   • gates_event_registrations — ticketed events. amount_naira, no status column at
 *                               all: a row exists or it does not.
 *
 * So every figure here had to be assembled by hand from the database, which in
 * practice meant it was never assembled at all.
 *
 * ── WHAT COUNTS AS REVENUE ───────────────────────────────────────────────────
 *
 * Confirmed and not refunded. Stated because the alternatives are tempting and
 * wrong: counting pending inflates the total with checkouts nobody completed, and
 * ignoring `refunded_at` reports money the organisation no longer has. Both are the
 * kind of error that is only discovered when the figure is reconciled against a bank
 * statement, which is exactly the moment it must not be wrong.
 *
 * Pending is reported SEPARATELY and prominently rather than hidden, because on this
 * platform a pending row is not simply an abandoned cart: a bank transfer to
 * Paystack settles minutes after the buyer has left the site, so some pending rows
 * are real money that has not been reconciled yet. {@see uncredited()} is that queue.
 *
 * ── EVERY METHOD DEGRADES TO ZERO, NEVER TO A FATAL ──────────────────────────
 *
 * A missing table (a deployment mid-migration, a driver hiccup) returns an empty
 * result rather than a 500. An admin looking at a finance page during an incident is
 * probably looking at it BECAUSE of the incident.
 */
final class FinanceService
{
    /**
     * The revenue sources, and how a `gates_donations.tier` maps onto them.
     *
     * PaymentController writes `"{purpose}:{tier}"` while DonationController and
     * PaidVoteController write a bare word, so the column holds both shapes and the
     * classifier has to read the prefix. Anything unrecognised lands in `other`
     * rather than being dropped — a total that silently omits a row is worse than a
     * bucket called "other" that prompts someone to look.
     */
    public const SOURCES = ['donation', 'paid-vote', 'shop', 'event', 'other'];

    public const LABELS = [
        'donation'  => 'Donations',
        'paid-vote' => 'Paid votes',
        'shop'      => 'Shop orders',
        'event'     => 'Event tickets',
        'other'     => 'Other payments',
    ];

    /** Classify a gates_donations.tier into one of {@see SOURCES}. */
    public static function sourceForTier(?string $tier): string
    {
        $t = strtolower(trim((string) $tier));
        if ($t === '') return 'other';
        // "shop:standard" → shop. The colon form is PaymentController's.
        $head = explode(':', $t)[0];
        return match (true) {
            $head === 'paid-vote' || $head === 'paid_vote' => 'paid-vote',
            $head === 'donation'                            => 'donation',
            $head === 'shop'                                => 'shop',
            $head === 'event' || $head === 'ticket'         => 'event',
            default                                         => 'other',
        };
    }

    /** Money in the door: confirmed, not refunded, by source. @return array<string,array{gross:int,count:int}> */
    public static function bySource(?string $since = null, ?string $until = null): array
    {
        $out = array_fill_keys(self::SOURCES, ['gross' => 0, 'count' => 0]);

        // ── gates_donations: donations, paid votes, and PaymentController's traffic
        try {
            $q = DB::table('gates_donations')->where('status', 'confirmed')->whereNull('refunded_at');
            self::window($q, 'created_at', $since, $until);
            foreach ($q->get(['tier', 'amount_naira']) as $r) {
                $s = self::sourceForTier($r->tier ?? null);
                $out[$s]['gross'] += (int) $r->amount_naira;
                $out[$s]['count']++;
            }
        } catch (\Throwable) {}

        // ── gates_orders: the shop says 'paid', not 'confirmed'
        try {
            $q = DB::table('gates_orders')->where('status', 'paid');
            self::window($q, 'created_at', $since, $until);
            $r = $q->selectRaw('COALESCE(SUM(subtotal_naira),0) AS gross, COUNT(*) AS n')->first();
            $out['shop']['gross'] += (int) ($r->gross ?? 0);
            $out['shop']['count'] += (int) ($r->n ?? 0);
        } catch (\Throwable) {}

        // ── gates_event_registrations ────────────────────────────────────────
        //
        // This said "no status column, so a row IS the payment". That was true when tickets
        // were free RSVPs and stopped being true the day they could be bought: the table has
        // carried `status` since the ticketing migration, and a row is `pending` from the
        // moment somebody presses Buy until their payment lands — if it ever does.
        //
        // So this counted abandoned checkouts, cancelled bookings and refunded tickets as
        // REVENUE. The same defect that made unpaid people appear as registered on the event
        // page, arriving here as money the organisation does not have, on the one screen
        // whose numbers get compared against a bank statement.
        //
        // Zero-amount rows are free RSVPs and are still excluded: "412 payments" when 400 of
        // them were free tickets is a wrong number, not a generous one.
        // NULL counts as confirmed, deliberately. The ticketing migration backfilled every
        // pre-existing row to `confirmed` on the stated grounds that "every row that predates
        // this is a free RSVP that was accepted" — so a null here is either a row from before
        // that migration ran or one written by something that bypasses reserve(), and in both
        // cases treating it as unpaid would DELETE revenue from the report. Excluding money
        // that exists is a worse error than the one being fixed.
        try {
            $q = DB::table('gates_event_registrations')
                ->where(static fn ($w) => $w->where('status', 'confirmed')->orWhereNull('status'))
                ->where('amount_naira', '>', 0);
            self::window($q, 'created_at', $since, $until);
            $r = $q->selectRaw('COALESCE(SUM(amount_naira),0) AS gross, COUNT(*) AS n')->first();
            $out['event']['gross'] += (int) ($r->gross ?? 0);
            $out['event']['count'] += (int) ($r->n ?? 0);
        } catch (\Throwable) {}

        return $out;
    }

    /**
     * Headline figures.
     *
     * `pending` and `failed` come from gates_donations and gates_orders only —
     * event registrations have no such state to be in.
     *
     * @return array{confirmed:int, transactions:int, pending:int, pending_count:int,
     *               failed_count:int, refunded:int, refunded_count:int, average:int}
     */
    public static function totals(?string $since = null, ?string $until = null): array
    {
        $by        = self::bySource($since, $until);
        $confirmed = array_sum(array_column($by, 'gross'));
        $count     = array_sum(array_column($by, 'count'));

        $pending = 0; $pendingCount = 0; $failed = 0;
        try {
            $q = DB::table('gates_donations')->where('status', 'pending');
            self::window($q, 'created_at', $since, $until);
            $r = $q->selectRaw('COALESCE(SUM(amount_naira),0) AS gross, COUNT(*) AS n')->first();
            $pending += (int) ($r->gross ?? 0); $pendingCount += (int) ($r->n ?? 0);

            $q = DB::table('gates_donations')->where('status', 'failed');
            self::window($q, 'created_at', $since, $until);
            $failed += (int) ($q->count());
        } catch (\Throwable) {}
        try {
            $q = DB::table('gates_orders')->where('status', 'pending');
            self::window($q, 'created_at', $since, $until);
            $r = $q->selectRaw('COALESCE(SUM(subtotal_naira),0) AS gross, COUNT(*) AS n')->first();
            $pending += (int) ($r->gross ?? 0); $pendingCount += (int) ($r->n ?? 0);

            $q = DB::table('gates_orders')->where('status', 'failed');
            self::window($q, 'created_at', $since, $until);
            $failed += (int) ($q->count());
        } catch (\Throwable) {}

        $refunded = 0; $refundedCount = 0;
        try {
            $q = DB::table('gates_donations')->whereNotNull('refunded_at');
            self::window($q, 'created_at', $since, $until);
            $r = $q->selectRaw('COALESCE(SUM(amount_naira),0) AS gross, COUNT(*) AS n')->first();
            $refunded = (int) ($r->gross ?? 0); $refundedCount = (int) ($r->n ?? 0);
        } catch (\Throwable) {}

        return [
            'confirmed'      => $confirmed,
            'transactions'   => $count,
            'pending'        => $pending,
            'pending_count'  => $pendingCount,
            'failed_count'   => $failed,
            'refunded'       => $refunded,
            'refunded_count' => $refundedCount,
            'average'        => $count > 0 ? (int) round($confirmed / $count) : 0,
        ];
    }

    /**
     * Confirmed revenue per day, gap-filled.
     *
     * GAP-FILLED ON PURPOSE. A GROUP BY only returns days that had a payment, and a
     * chart drawn straight from that silently closes the gaps — three sales on three
     * separate weeks render as a continuous rising line. Days with nothing must be
     * zero, visibly.
     *
     * @return list<array{date:string, naira:int}>
     */
    public static function daily(int $days = 30): array
    {
        $days  = max(1, min(365, $days));
        $from  = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $sums  = [];

        $add = static function (string $d, int $n) use (&$sums): void {
            $sums[$d] = ($sums[$d] ?? 0) + $n;
        };

        try {
            $rows = DB::table('gates_donations')->where('status', 'confirmed')->whereNull('refunded_at')
                ->where('created_at', '>=', $from)
                ->selectRaw('substr(created_at,1,10) AS d, COALESCE(SUM(amount_naira),0) AS n')
                ->groupBy('d')->get();
            foreach ($rows as $r) $add((string) $r->d, (int) $r->n);
        } catch (\Throwable) {}
        try {
            $rows = DB::table('gates_orders')->where('status', 'paid')
                ->where('created_at', '>=', $from)
                ->selectRaw('substr(created_at,1,10) AS d, COALESCE(SUM(subtotal_naira),0) AS n')
                ->groupBy('d')->get();
            foreach ($rows as $r) $add((string) $r->d, (int) $r->n);
        } catch (\Throwable) {}
        try {
            $rows = DB::table('gates_event_registrations')->where('amount_naira', '>', 0)
                ->where('created_at', '>=', $from)
                ->selectRaw('substr(created_at,1,10) AS d, COALESCE(SUM(amount_naira),0) AS n')
                ->groupBy('d')->get();
            foreach ($rows as $r) $add((string) $r->d, (int) $r->n);
        } catch (\Throwable) {}

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = ['date' => $d, 'naira' => (int) ($sums[$d] ?? 0)];
        }
        return $out;
    }

    /**
     * The most recent payments across every source, newest first.
     *
     * Deliberately NOT a UNION in SQL: the three tables have different columns,
     * different status vocabularies and — on SQLite versus MySQL — different string
     * functions, and a hand-written UNION over that is where a source quietly stops
     * appearing. Three small queries and a sort in PHP is slower and correct.
     *
     * @return list<array{when:string, source:string, who:string, email:string,
     *                    naira:int, status:string, ref:string}>
     */
    public static function recent(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $rows  = [];

        try {
            foreach (DB::table('gates_donations')->orderByDesc('id')->limit($limit)
                ->get(['created_at', 'tier', 'donor_name', 'donor_email', 'amount_naira', 'status', 'payment_ref', 'refunded_at']) as $r) {
                $rows[] = [
                    'when'   => (string) $r->created_at,
                    'source' => self::sourceForTier($r->tier ?? null),
                    'who'    => (string) ($r->donor_name ?: 'Supporter'),
                    'email'  => (string) $r->donor_email,
                    'naira'  => (int) $r->amount_naira,
                    'status' => $r->refunded_at !== null ? 'refunded' : (string) $r->status,
                    'ref'    => (string) ($r->payment_ref ?? ''),
                ];
            }
        } catch (\Throwable) {}

        try {
            foreach (DB::table('gates_orders')->orderByDesc('id')->limit($limit)
                ->get(['created_at', 'name', 'email', 'subtotal_naira', 'status', 'reference']) as $r) {
                $rows[] = [
                    'when'   => (string) $r->created_at,
                    'source' => 'shop',
                    'who'    => (string) $r->name,
                    'email'  => (string) $r->email,
                    'naira'  => (int) $r->subtotal_naira,
                    // The shop says 'paid' where everything else says 'confirmed'.
                    // Normalised here so one chip vocabulary covers the whole table.
                    'status' => ((string) $r->status) === 'paid' ? 'confirmed' : (string) $r->status,
                    'ref'    => (string) ($r->reference ?? ''),
                ];
            }
        } catch (\Throwable) {}

        try {
            foreach (DB::table('gates_event_registrations')->where('amount_naira', '>', 0)
                ->orderByDesc('id')->limit($limit)
                ->get(['created_at', 'name', 'email', 'amount_naira', 'reference']) as $r) {
                $rows[] = [
                    'when'   => (string) $r->created_at,
                    'source' => 'event',
                    'who'    => (string) $r->name,
                    'email'  => (string) $r->email,
                    'naira'  => (int) $r->amount_naira,
                    'status' => 'confirmed',
                    'ref'    => (string) ($r->reference ?? ''),
                ];
            }
        } catch (\Throwable) {}

        usort($rows, static fn (array $a, array $b): int => strcmp($b['when'], $a['when']));
        return array_slice($rows, 0, $limit);
    }

    /**
     * PENDING PAYMENTS OLD ENOUGH TO BE SUSPICIOUS — the "I paid and nothing happened"
     * queue, and the most operationally useful thing on this page.
     *
     * A checkout that is still pending a minute later is someone mid-flow. One that is
     * still pending an hour later is either an abandoned cart or a bank transfer that
     * settled after the callback — and the second kind is real money sitting
     * uncredited. The two are indistinguishable from the row alone, which is why the
     * answer is not to guess but to re-ask the gateway: `Maintenance::run('payments')`,
     * wired to the "Credit paid votes now" button in Settings.
     *
     * @return list<array{id:int, when:string, source:string, who:string, email:string, naira:int, ref:string, age_h:int}>
     */
    public static function uncredited(int $olderThanMinutes = 60, int $limit = 50): array
    {
        $cut  = date('Y-m-d H:i:s', strtotime('-' . max(1, $olderThanMinutes) . ' minutes'));
        $out  = [];
        try {
            foreach (DB::table('gates_donations')->where('status', 'pending')
                ->where('created_at', '<=', $cut)->orderByDesc('id')->limit($limit)
                ->get(['id', 'created_at', 'tier', 'donor_name', 'donor_email', 'amount_naira', 'payment_ref']) as $r) {
                $out[] = [
                    'id'     => (int) $r->id,
                    'when'   => (string) $r->created_at,
                    'source' => self::sourceForTier($r->tier ?? null),
                    'who'    => (string) ($r->donor_name ?: 'Supporter'),
                    'email'  => (string) $r->donor_email,
                    'naira'  => (int) $r->amount_naira,
                    'ref'    => (string) ($r->payment_ref ?? ''),
                    'age_h'  => (int) floor((time() - strtotime((string) $r->created_at)) / 3600),
                ];
            }
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * CONFIRMED PAID-VOTE ORDERS THAT NEVER MINTED — money taken, votes owed.
     *
     * `votes_used = 0` on a confirmed 'paid-vote' row is the platform's existing
     * "paid but never minted" signal ({@see \AfricaGates\Services\PaidVoteService::mint()}
     * leaves it that way when voting closed between checkout and confirmation). It is
     * already reported to operators by `cycles:audit` and already reversible by the
     * clawback path — but it was not visible anywhere an admin actually looks, and it
     * is the one figure on this page that represents a refund the organisation owes.
     *
     * @return array{count:int, naira:int, rows:list<array<string,mixed>>}
     */
    public static function owedRefunds(int $limit = 25): array
    {
        try {
            $q = DB::table('gates_donations')
                ->where('tier', 'paid-vote')->where('status', 'confirmed')
                ->where('votes_used', 0)->whereNull('refunded_at');
            $agg = (clone $q)->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_naira),0) AS gross')->first();
            $rows = (clone $q)->orderByDesc('id')->limit($limit)
                ->get(['id', 'created_at', 'donor_name', 'donor_email', 'amount_naira', 'bonus_votes', 'payment_ref'])
                ->map(static fn ($r) => (array) $r)->all();
            return ['count' => (int) ($agg->n ?? 0), 'naira' => (int) ($agg->gross ?? 0), 'rows' => $rows];
        } catch (\Throwable) {
            return ['count' => 0, 'naira' => 0, 'rows' => []];
        }
    }

    /**
     * Which nominees paid votes were bought for, and how much each raised.
     *
     * The single question the awards team asks that no existing page answers.
     *
     * @return list<array{nominee:string, category:string, orders:int, votes:int, naira:int}>
     */
    public static function paidVotesByNominee(int $limit = 20): array
    {
        try {
            return DB::table('gates_donations as d')
                ->join('gates_nominees as n', 'n.id', '=', 'd.intent_nominee_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('d.tier', 'paid-vote')->where('d.status', 'confirmed')->whereNull('d.refunded_at')
                ->groupBy('n.id', 'n.name', 'c.title')
                ->orderByDesc(DB::raw('SUM(d.amount_naira)'))
                ->limit($limit)
                ->get([
                    'n.name as nominee',
                    DB::raw('COALESCE(c.title, \'—\') as category'),
                    DB::raw('COUNT(*) as orders'),
                    DB::raw('COALESCE(SUM(d.bonus_votes),0) as votes'),
                    DB::raw('COALESCE(SUM(d.amount_naira),0) as naira'),
                ])
                ->map(static fn ($r) => [
                    'nominee'  => (string) $r->nominee,
                    'category' => (string) $r->category,
                    'orders'   => (int) $r->orders,
                    'votes'    => (int) $r->votes,
                    'naira'    => (int) $r->naira,
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * How each gateway is performing — and, more usefully, how often it FAILS.
     *
     * The provider is not stored on gates_donations, only on gates_orders, so this
     * reports what the data can actually support rather than inventing a split.
     *
     * @return array{orders:list<array{provider:string, paid:int, failed:int, naira:int}>}
     */
    public static function byProvider(): array
    {
        try {
            $rows = DB::table('gates_orders')
                ->whereNotNull('provider')
                ->groupBy('provider')
                ->get([
                    'provider',
                    DB::raw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid"),
                    DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
                    DB::raw("COALESCE(SUM(CASE WHEN status = 'paid' THEN subtotal_naira ELSE 0 END),0) as naira"),
                ])
                ->map(static fn ($r) => [
                    'provider' => (string) $r->provider,
                    'paid'     => (int) $r->paid,
                    'failed'   => (int) $r->failed,
                    'naira'    => (int) $r->naira,
                ])->all();
            return ['orders' => $rows];
        } catch (\Throwable) {
            return ['orders' => []];
        }
    }

    /** Apply an inclusive date window to a query builder, when one was given. */
    private static function window(mixed $q, string $col, ?string $since, ?string $until): void
    {
        if ($since !== null && $since !== '') $q->where($col, '>=', $since . ' 00:00:00');
        if ($until !== null && $until !== '') $q->where($col, '<=', $until . ' 23:59:59');
    }

    /**
     * Where the money that actually arrived was SETTLED — per revenue stream.
     *
     * ══════════════════════════════════════════════════════════════════════════════
     * THE FEATURE HAD NO OUTPUT
     * ══════════════════════════════════════════════════════════════════════════════
     *
     * `gates_payment_routes` was written on every routed payment and read by nothing. Not one
     * screen, service or export touched it. So the question the whole subaccount feature was
     * built to answer — "how much of this is ticket money" — still could not be answered from
     * this platform, which is exactly the state its own design note describes as the problem:
     * "can only be answered by exporting the platform's own records and hoping they agree
     * with the bank."
     *
     * The routing worked. The reflection did not exist.
     *
     * ══════════════════════════════════════════════════════════════════════════════
     * WHY THIS JOINS AND DOES NOT SIMPLY SUM THE ROUTE TABLE
     * ══════════════════════════════════════════════════════════════════════════════
     *
     * A route row is written at INITIALISE — the moment a buyer is sent to the gateway, which
     * is before they have paid and regardless of whether they ever do. Summing the table
     * would therefore report every abandoned checkout as settled income, and it would do so
     * on the screen most likely to be read against a bank statement.
     *
     * So each stream is counted from ITS OWN ledger, filtered to what actually completed, and
     * only then matched against the attribution. A confirmed payment with no route row is not
     * missing data: it settled to the main account, which is what an absent row means.
     *
     * @return list<array{stream:string, label:string, configured:string, bearer:string,
     *                    routed_count:int, routed_naira:int, main_count:int, main_naira:int,
     *                    subaccounts:array<string,int>}>
     */
    public static function settlement(?string $since = null, ?string $until = null): array
    {
        // stream => [table, reference column, amount column, the filter that means "paid"]
        $ledgers = [
            'shop'   => ['gates_orders', 'reference', 'subtotal_naira',
                         static fn ($q) => $q->where('status', 'paid')],
            // NULL reads as confirmed here for the same reason as in bySource() above: the
            // ticketing migration backfilled pre-existing rows on exactly that basis.
            'events' => ['gates_event_registrations', 'reference', 'amount_naira',
                         static fn ($q) => $q
                             ->where(static fn ($w) => $w->where('status', 'confirmed')->orWhereNull('status'))
                             ->where('amount_naira', '>', 0)],
            'votes'  => ['gates_donations', 'payment_ref', 'amount_naira',
                         static fn ($q) => $q->where('status', 'confirmed')->whereNull('refunded_at')],
        ];

        $out = [];
        foreach ($ledgers as $stream => [$table, $refCol, $amtCol, $paid]) {
            $row = [
                'stream'     => $stream,
                'label'      => \AfricaGates\Services\PaymentDestination::STREAMS[$stream] ?? $stream,
                'configured' => \AfricaGates\Services\PaymentDestination::forStream($stream),
                'bearer'     => \AfricaGates\Services\PaymentDestination::bearerFor($stream),
                'routed_count' => 0, 'routed_naira' => 0,
                'main_count'   => 0, 'main_naira'   => 0,
                // Keyed by the code actually used, so a stream whose subaccount CHANGED
                // mid-period shows both — which is the one case a single figure would hide,
                // and the reason the code is stored per payment rather than looked up now.
                'subaccounts'  => [],
            ];

            try {
                $q = DB::table($table);
                $paid($q);
                self::window($q, 'created_at', $since, $until);
                $rows = $q->get([$refCol . ' as ref', $amtCol . ' as naira']);
            } catch (\Throwable) {
                $out[] = $row;
                continue;
            }

            // One lookup for the whole window rather than a query per payment: a busy month
            // is thousands of rows, and this runs on a dashboard load.
            $refs   = array_values(array_filter(array_map(
                static fn ($r): string => (string) ($r->ref ?? ''), $rows->all())));
            $routes = [];
            if ($refs !== []) {
                try {
                    foreach (array_chunk($refs, 500) as $chunk) {
                        foreach (DB::table('gates_payment_routes')->whereIn('reference', $chunk)
                                    ->get(['reference', 'subaccount']) as $r) {
                            $routes[(string) $r->reference] = (string) $r->subaccount;
                        }
                    }
                } catch (\Throwable) { $routes = []; }   // unmigrated: everything reads as main
            }

            foreach ($rows as $r) {
                $naira = (int) ($r->naira ?? 0);
                $code  = $routes[(string) ($r->ref ?? '')] ?? '';
                if ($code === '') {
                    $row['main_count']++;
                    $row['main_naira'] += $naira;
                    continue;
                }
                $row['routed_count']++;
                $row['routed_naira'] += $naira;
                $row['subaccounts'][$code] = ($row['subaccounts'][$code] ?? 0) + $naira;
            }

            arsort($row['subaccounts']);
            $out[] = $row;
        }

        return $out;
    }
}
