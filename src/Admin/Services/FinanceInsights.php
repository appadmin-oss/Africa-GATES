<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The questions the finance page could not answer.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SECOND CLASS AND NOT MORE OF FinanceService
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see FinanceService} answers "how much, from where" — a ledger question. Every
 * method there sums a column over a window, and it is already four hundred lines
 * of doing exactly that, carefully.
 *
 * These are different questions with a different shape: they compare two windows,
 * count people rather than transactions, and measure the payments that did NOT
 * happen. They need per-row iteration, a notion of identity, and in one case the
 * absence of a row to be the finding. Bolting them onto a class whose whole
 * contract is "sum this column" would blur what either class promises.
 *
 * The classification of a `tier` into a revenue source stays in FinanceService and
 * is reused here — two answers to "is this a paid vote" is the one duplication
 * that would actually cost money.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT COUNTS AS A PERSON
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A supporter is a lowercased, trimmed email. Not a user id: most people who pay
 * here never make an account, and counting only the ones who did would report a
 * fraction of the audience and call it the audience. Not a name either — "Ada
 * Obi", "ada obi" and "A. Obi" are three strings and one person, and the email is
 * the only field the payment flow validates.
 *
 * This is stated because it has a consequence the reader must know: somebody who
 * pays twice from two addresses counts twice. There is no way to fix that without
 * inventing identity, and inventing identity in a finance report is worse than
 * a slightly high count that is honestly defined.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY METHOD DEGRADES TO ZERO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Same rule as FinanceService: a missing table returns an empty result, never a
 * fatal. An admin opening this page during an incident is opening it BECAUSE of
 * the incident, and a 500 is the least useful thing it could do.
 */
final class FinanceInsights
{
    /**
     * Confirmed and not reversed — the definition of "money we actually kept".
     *
     * Columns are TABLE-QUALIFIED, which looks like noise on an unjoined query and
     * is not. {@see byProgramme()} joins this to `gates_nominees`, which also has
     * a `status`, and a bare `status` made the whole query throw "ambiguous column
     * name" — into the catch, which returns empty, which the page rendered as "no
     * paid votes in this window" over a window full of them. Qualifying here means
     * no future join can reintroduce that.
     */
    private static function kept(): \Illuminate\Database\Query\Builder
    {
        return DB::table('gates_donations')
            ->where('gates_donations.status', 'confirmed')
            ->whereNull('gates_donations.refunded_at');
    }

    private static function window(mixed $q, string $col, ?string $since, ?string $until): void
    {
        if ($since !== null && $since !== '') $q->where($col, '>=', $since . ' 00:00:00');
        if ($until !== null && $until !== '') $q->where($col, '<=', $until . ' 23:59:59');
    }

    /** The identity rule, in one place. @see the class note on what counts as a person. */
    public static function personKey(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1 · THIS PERIOD AGAINST THE LAST ONE
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The same figures for this window and the window immediately before it.
     *
     * ── WHY THE PREVIOUS WINDOW IS DERIVED, NOT ASKED FOR ────────────────────
     *
     * "Last 30 days" is only meaningful next to the 30 days before it, and if the
     * operator had to choose the comparison window by hand they would sometimes
     * choose one of a different length — at which point the percentage is
     * arithmetic on two incomparable numbers and reads as a collapse or a boom
     * that never happened. So the previous window is always exactly the same
     * number of days, ending the day before this one starts.
     *
     * ── AND WHY A DELTA CAN BE null ──────────────────────────────────────────
     *
     * Growth from zero is not "+100%", it is undefined, and printing a number
     * there invites somebody to put it in a board pack. `null` means "there is no
     * honest percentage here"; the template says "no prior activity".
     *
     * @return array{
     *   current:array{gross:int,count:int,people:int,average:int},
     *   previous:array{gross:int,count:int,people:int,average:int},
     *   delta:array{gross:?float,count:?float,people:?float,average:?float},
     *   window:array{days:int,from:string,to:string,prev_from:string,prev_to:string}
     * }
     */
    public static function comparison(?string $from, ?string $to): array
    {
        $to   = $to ?: date('Y-m-d');
        $from = $from ?: date('Y-m-d', strtotime($to . ' -29 days'));

        $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
        $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));

        $cur  = self::slice($from, $to);
        $prev = self::slice($prevFrom, $prevTo);

        $pct = static function (int $now, int $before): ?float {
            if ($before === 0) return null;             // see the note above
            return round((($now - $before) / $before) * 100, 1);
        };

        return [
            'current'  => $cur,
            'previous' => $prev,
            'delta'    => [
                'gross'   => $pct($cur['gross'], $prev['gross']),
                'count'   => $pct($cur['count'], $prev['count']),
                'people'  => $pct($cur['people'], $prev['people']),
                'average' => $pct($cur['average'], $prev['average']),
            ],
            'window' => [
                'days' => $days, 'from' => $from, 'to' => $to,
                'prev_from' => $prevFrom, 'prev_to' => $prevTo,
            ],
        ];
    }

    /** @return array{gross:int,count:int,people:int,average:int} */
    private static function slice(string $from, string $to): array
    {
        $gross = 0; $count = 0; $people = [];

        try {
            $q = self::kept();
            self::window($q, 'created_at', $from, $to);
            foreach ($q->get(['amount_naira', 'donor_email']) as $r) {
                $gross += (int) $r->amount_naira;
                $count++;
                $k = self::personKey($r->donor_email ?? null);
                if ($k !== '') $people[$k] = true;
            }
        } catch (\Throwable) {}

        // The shop is money too. Its status vocabulary is 'paid', not 'confirmed'.
        try {
            $q = DB::table('gates_orders')->where('status', 'paid');
            self::window($q, 'created_at', $from, $to);
            foreach ($q->get(['subtotal_naira', 'email']) as $r) {
                $gross += (int) $r->subtotal_naira;
                $count++;
                $k = self::personKey($r->email ?? null);
                if ($k !== '') $people[$k] = true;
            }
        } catch (\Throwable) {}

        return [
            'gross'   => $gross,
            'count'   => $count,
            'people'  => count($people),
            'average' => $count > 0 ? (int) round($gross / $count) : 0,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2 · PEOPLE, NOT TRANSACTIONS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Who paid, how many of them are new, and how concentrated the money is.
     *
     * ── THE MEDIAN IS HERE BECAUSE THE MEAN LIES ─────────────────────────────
     *
     * One ₦500,000 sponsor among two hundred ₦500 supporters produces a mean of
     * about ₦3,000 — a figure that describes nobody in the dataset and that will
     * be quoted as "our average supporter gives ₦3,000". The median says ₦500,
     * which is true of the actual middle person. Both are reported so the gap
     * between them is visible; the gap IS the finding.
     *
     * ── AND WHY TOP-DECILE SHARE IS REPORTED ─────────────────────────────────
     *
     * Concentration is a risk, not a vanity metric. If the top 10% of supporters
     * are 80% of the revenue, then a campaign that loses twenty people loses most
     * of the income, and that is worth knowing before it happens rather than
     * after.
     *
     * "New" means: this address has no kept payment before the window opened.
     * Computed against ALL history, not against the previous window — somebody
     * who gave once last year and again today is returning, not new, and calling
     * them new would overstate acquisition every single period.
     *
     * @return array{people:int,new:int,returning:int,average:int,median:int,
     *                largest:int,top_decile_pct:int,repeat_rate:int,
     *                top:list<array{email:string,name:string,naira:int,payments:int}>}
     */
    public static function supporters(?string $from, ?string $to, int $topN = 10): array
    {
        $spend = [];   // key => ['naira'=>int,'n'=>int,'name'=>string]

        $add = static function (array &$spend, ?string $email, ?string $name, int $naira): void {
            $k = self::personKey($email);
            if ($k === '') return;
            if (!isset($spend[$k])) $spend[$k] = ['naira' => 0, 'n' => 0, 'name' => trim((string) $name)];
            $spend[$k]['naira'] += $naira;
            $spend[$k]['n']++;
            if ($spend[$k]['name'] === '' && trim((string) $name) !== '') $spend[$k]['name'] = trim((string) $name);
        };

        try {
            $q = self::kept();
            self::window($q, 'created_at', $from, $to);
            foreach ($q->get(['donor_email', 'donor_name', 'amount_naira']) as $r) {
                $add($spend, $r->donor_email ?? null, $r->donor_name ?? null, (int) $r->amount_naira);
            }
        } catch (\Throwable) {}
        try {
            $q = DB::table('gates_orders')->where('status', 'paid');
            self::window($q, 'created_at', $from, $to);
            foreach ($q->get(['email', 'name', 'subtotal_naira']) as $r) {
                $add($spend, $r->email ?? null, $r->name ?? null, (int) $r->subtotal_naira);
            }
        } catch (\Throwable) {}

        $empty = ['people' => 0, 'new' => 0, 'returning' => 0, 'average' => 0, 'median' => 0,
                  'largest' => 0, 'top_decile_pct' => 0, 'repeat_rate' => 0, 'top' => []];
        if ($spend === []) return $empty;

        // ── new vs returning, against all history before the window ──────────
        $priorSeen = [];
        if ($from !== null && $from !== '') {
            try {
                foreach (self::kept()->where('created_at', '<', $from . ' 00:00:00')
                    ->get(['donor_email']) as $r) {
                    $k = self::personKey($r->donor_email ?? null);
                    if ($k !== '') $priorSeen[$k] = true;
                }
            } catch (\Throwable) {}
            try {
                foreach (DB::table('gates_orders')->where('status', 'paid')
                    ->where('created_at', '<', $from . ' 00:00:00')->get(['email']) as $r) {
                    $k = self::personKey($r->email ?? null);
                    if ($k !== '') $priorSeen[$k] = true;
                }
            } catch (\Throwable) {}
        }

        $amounts = array_map(static fn (array $v): int => $v['naira'], $spend);
        sort($amounts);
        $n     = count($amounts);
        $total = array_sum($amounts);

        $median = $n % 2 === 1
            ? $amounts[intdiv($n, 2)]
            : (int) round(($amounts[$n / 2 - 1] + $amounts[$n / 2]) / 2);

        // Top decile, at least one person — with nine supporters, "the top 10%"
        // rounding to zero people would report a 0% concentration on a set that is
        // as concentrated as it gets.
        $decile = max(1, (int) ceil($n / 10));
        $topSum = array_sum(array_slice($amounts, -$decile));

        $newCount = 0;
        foreach (array_keys($spend) as $k) if (!isset($priorSeen[$k])) $newCount++;

        $repeat = 0;
        foreach ($spend as $v) if ($v['n'] > 1) $repeat++;

        uasort($spend, static fn (array $a, array $b): int => $b['naira'] <=> $a['naira']);
        $top = [];
        foreach (array_slice($spend, 0, max(1, $topN), true) as $email => $v) {
            $top[] = ['email' => $email, 'name' => $v['name'], 'naira' => $v['naira'], 'payments' => $v['n']];
        }

        return [
            'people'         => $n,
            'new'            => $newCount,
            'returning'      => $n - $newCount,
            'average'        => (int) round($total / $n),
            'median'         => $median,
            'largest'        => $amounts[$n - 1],
            'top_decile_pct' => $total > 0 ? (int) round($topSum * 100 / $total) : 0,
            'repeat_rate'    => (int) round($repeat * 100 / $n),
            'top'            => $top,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3 · WHICH PROGRAMME AND CATEGORY THE MONEY CAME THROUGH
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Paid-vote revenue attributed up the tree: nominee → category → cycle → programme.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * `paidVotesByNominee()` already ranks individuals, which answers "who is
     * winning the fundraising". It cannot answer "which programme is worth running
     * again", and that is the question that decides next year's calendar. A
     * category with four nominees and steady revenue is a different proposition
     * from one with forty nominees and none.
     *
     * Orders whose `intent_nominee_id` no longer resolves — the nominee was merged
     * away or deleted — are counted under a null programme rather than dropped, so
     * the parts still sum to the paid-vote total. A breakdown that does not add up
     * to its own header is a breakdown nobody can use.
     *
     * @return array{programmes:list<array>, categories:list<array>, unattributed:int}
     */
    public static function byProgramme(?string $from, ?string $to, int $limit = 12): array
    {
        $progs = [];   // programme label => ['naira'=>, 'count'=>, 'votes'=>]
        $cats  = [];   // "prog · cat"    => ['naira'=>, 'count'=>, 'votes'=>, 'programme'=>, 'title'=>]
        $orphan = 0;

        try {
            $q = self::kept()
                ->leftJoin('gates_nominees AS n', 'n.id', '=', 'gates_donations.intent_nominee_id')
                ->leftJoin('gates_award_categories AS c', 'c.id', '=', 'n.category_id')
                ->leftJoin('gates_award_cycles AS cy', 'cy.id', '=', 'c.cycle_id')
                ->leftJoin('gates_award_programmes AS p', 'p.id', '=', 'cy.programme_id');
            self::window($q, 'gates_donations.created_at', $from, $to);

            $rows = $q->get([
                'gates_donations.amount_naira AS naira',
                'gates_donations.bonus_votes AS votes',
                'gates_donations.tier AS tier',
                'c.title AS cat',
                // `title`, not `name`. gates_award_programmes has no `name` column,
                // and the whole query threw on it — silently, because the catch
                // below returns empty. The panel then said "no paid votes in this
                // window" over a window full of them.
                'p.title AS prog',
                'cy.year AS year',
            ]);

            foreach ($rows as $r) {
                if (FinanceService::sourceForTier($r->tier ?? null) !== 'paid-vote') continue;

                $naira = (int) $r->naira;
                $votes = (int) ($r->votes ?? 0);
                $prog  = trim((string) ($r->prog ?? ''));
                $cat   = trim((string) ($r->cat ?? ''));

                if ($prog === '' && $cat === '') { $orphan += $naira; continue; }

                $plabel = $prog !== '' ? $prog : 'Unlinked programme';
                if (!isset($progs[$plabel])) $progs[$plabel] = ['naira' => 0, 'count' => 0, 'votes' => 0, 'label' => $plabel];
                $progs[$plabel]['naira'] += $naira;
                $progs[$plabel]['count']++;
                $progs[$plabel]['votes'] += $votes;

                $ckey = $plabel . "\x00" . ($cat !== '' ? $cat : 'Uncategorised');
                if (!isset($cats[$ckey])) {
                    $cats[$ckey] = ['naira' => 0, 'count' => 0, 'votes' => 0,
                                    'programme' => $plabel, 'title' => $cat !== '' ? $cat : 'Uncategorised',
                                    'year' => (string) ($r->year ?? '')];
                }
                $cats[$ckey]['naira'] += $naira;
                $cats[$ckey]['count']++;
                $cats[$ckey]['votes'] += $votes;
            }
        } catch (\Throwable) {
            return ['programmes' => [], 'categories' => [], 'unattributed' => 0];
        }

        $sort = static function (array $a): array {
            usort($a, static fn (array $x, array $y): int => $y['naira'] <=> $x['naira']);
            return $a;
        };

        return [
            'programmes'   => $sort(array_values($progs)),
            'categories'   => array_slice($sort(array_values($cats)), 0, max(1, $limit)),
            'unattributed' => $orphan,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4 · WHEN THE MONEY ARRIVES
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Confirmed revenue as a day-of-week × hour-of-day matrix.
     *
     * Operationally this is the most actionable thing on the page: it says when to
     * send the campaign email, when to schedule a close, and when it is safe to
     * deploy. Guessing at it is how a voting deadline lands at 09:00 on a Monday
     * and takes a third of what it would have taken on a Sunday evening.
     *
     * ── ONE TIMEZONE, STATED ─────────────────────────────────────────────────
     *
     * Rows are read in whatever the database stores, which is the application's
     * clock (WAT). No conversion happens here. That is correct for the decision
     * this table informs — "when do I press send", asked by someone in Lagos —
     * and it would be wrong to silently normalise to UTC and hand somebody a peak
     * an hour off.
     *
     * @return array{grid:array<int,array<int,int>>, peak:int, total:int,
     *                by_day:array<int,int>, by_hour:array<int,int>}
     */
    public static function rhythm(int $days = 90): array
    {
        $days = max(7, min(365, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $grid = [];
        for ($d = 0; $d < 7; $d++) $grid[$d] = array_fill(0, 24, 0);
        $byDay = array_fill(0, 7, 0);
        $byHour = array_fill(0, 24, 0);
        $total = 0;

        $eat = static function (?string $when, int $naira) use (&$grid, &$byDay, &$byHour, &$total): void {
            $ts = strtotime((string) $when);
            if ($ts === false) return;
            $d = (int) date('w', $ts);   // 0 = Sunday
            $h = (int) date('G', $ts);
            $grid[$d][$h] += $naira;
            $byDay[$d]    += $naira;
            $byHour[$h]   += $naira;
            $total        += $naira;
        };

        try {
            foreach (self::kept()->where('created_at', '>=', $from . ' 00:00:00')
                ->get(['created_at', 'amount_naira']) as $r) {
                $eat($r->created_at ?? null, (int) $r->amount_naira);
            }
        } catch (\Throwable) {}
        try {
            foreach (DB::table('gates_orders')->where('status', 'paid')
                ->where('created_at', '>=', $from . ' 00:00:00')
                ->get(['created_at', 'subtotal_naira']) as $r) {
                $eat($r->created_at ?? null, (int) $r->subtotal_naira);
            }
        } catch (\Throwable) {}

        $peak = 0;
        foreach ($grid as $row) foreach ($row as $v) if ($v > $peak) $peak = $v;

        return ['grid' => $grid, 'peak' => $peak, 'total' => $total,
                'by_day' => $byDay, 'by_hour' => $byHour];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5 · THE MONEY THAT DID NOT ARRIVE
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Checkouts started against checkouts that paid — and where the rest went.
     *
     * ── WHY THIS IS THE MOST IMPORTANT PANEL ON THE PAGE ─────────────────────
     *
     * Every other figure here measures revenue that exists. This measures the
     * revenue that was one step from existing: somebody wanted to pay, opened
     * checkout, and did not finish. A conversion rate that drops from 80% to 40%
     * overnight is a broken payment page, and nothing else on this dashboard would
     * move — gross would just be lower, which reads as a quiet week.
     *
     * ── THE BUCKETS ARE MUTUALLY EXCLUSIVE, AND THEY SUM ─────────────────────
     *
     * confirmed + refunded + failed + expired + still_open = started. A funnel
     * whose stages overlap is a funnel that can report 130% conversion, so each
     * row is tested in priority order and lands in exactly one bucket:
     *
     *   refunded   it did pay, and then it went back — counted as neither
     *              revenue nor loss, because it is its own event
     *   confirmed  it paid and stayed paid
     *   failed     the gateway said no
     *   expired    the checkout window closed unpaid (a voting gate shut, or the
     *              hold ran out) — distinct from abandonment, because the platform
     *              is what ended it
     *   still_open pending and still inside its window; not yet a loss and must
     *              not be counted as one
     *   abandoned  pending, past its window, and nothing ended it. THIS is the
     *              number to attack.
     *
     * @return array{started:int,confirmed:int,refunded:int,failed:int,expired:int,
     *                abandoned:int,still_open:int,conversion:?int,
     *                lost_naira:int,recoverable_naira:int}
     */
    public static function leakage(?string $from, ?string $to): array
    {
        $out = ['started' => 0, 'confirmed' => 0, 'refunded' => 0, 'failed' => 0,
                'expired' => 0, 'abandoned' => 0, 'still_open' => 0,
                'conversion' => null, 'lost_naira' => 0, 'recoverable_naira' => 0];

        // Anything younger than this may simply still be at the gateway; a bank
        // transfer settles when the bank feels like it. Matches
        // PaymentService::IN_FLIGHT_MINUTES so two screens cannot disagree about
        // whether a payment is late or merely slow.
        $liveBefore = date('Y-m-d H:i:s', time() - \AfricaGates\Services\PaymentService::IN_FLIGHT_MINUTES * 60);

        try {
            $q = DB::table('gates_donations');
            self::window($q, 'created_at', $from, $to);

            $cols = ['status', 'amount_naira', 'refunded_at', 'created_at'];
            // Optional columns: this schema drifts, and a deployment without the
            // checkout-expiry work must still get a funnel rather than a 500.
            foreach (['expired_at', 'checkout_expires_at'] as $c) {
                if (DB::schema()->hasColumn('gates_donations', $c)) $cols[] = $c;
            }

            foreach ($q->get($cols) as $r) {
                $out['started']++;
                $naira  = (int) $r->amount_naira;
                $status = (string) ($r->status ?? '');

                if (!empty($r->refunded_at))        { $out['refunded']++;  continue; }
                if ($status === 'confirmed')        { $out['confirmed']++; continue; }
                if ($status === 'failed')           { $out['failed']++;  $out['lost_naira'] += $naira; continue; }

                $expiredAt = $r->expired_at ?? null;
                $expiresAt = $r->checkout_expires_at ?? null;
                $closed    = !empty($expiredAt)
                          || (!empty($expiresAt) && strtotime((string) $expiresAt) < time());

                if ($closed) { $out['expired']++; $out['lost_naira'] += $naira; continue; }

                if ((string) ($r->created_at ?? '') > $liveBefore) { $out['still_open']++; continue; }

                $out['abandoned']++;
                $out['lost_naira']        += $naira;
                // Abandonment is the only bucket a nudge can still reach: nothing
                // refused it and nothing closed it, the person just stopped.
                $out['recoverable_naira'] += $naira;
            }
        } catch (\Throwable) {
            return $out;
        }

        // Conversion excludes the still-open ones from BOTH sides. Counting a
        // checkout somebody opened ninety seconds ago as a failure to convert
        // makes the rate drop every time the site gets busy.
        $decided = $out['started'] - $out['still_open'];
        $out['conversion'] = $decided > 0
            ? (int) round(($out['confirmed'] + $out['refunded']) * 100 / $decided)
            : null;

        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6 · THE RUNNING TOTAL
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cumulative confirmed revenue per day.
     *
     * A daily bar chart shows volatility and hides trajectory; a cumulative line
     * shows trajectory and hides volatility. Both are on the page because a
     * campaign is judged on the second and operated on the first.
     *
     * @return list<array{date:string, naira:int, total:int}>
     */
    public static function cumulative(int $days = 90): array
    {
        $daily = FinanceService::daily($days);
        $run   = 0;
        $out   = [];
        foreach ($daily as $d) {
            $run += (int) $d['naira'];
            $out[] = ['date' => $d['date'], 'naira' => (int) $d['naira'], 'total' => $run];
        }
        return $out;
    }
}
