<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What the platform is doing, as opposed to what it has been paid.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE GAP THIS FILLS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The admin console could tell you eleven counts (profiles, votes, nominations…),
 * a 14-day vote line and a top-nominee list. Every one of those is a level: how
 * many things exist right now. None of them is a RATE, a RATIO or a FUNNEL, and
 * those are the shapes that answer the questions somebody running an award cycle
 * actually has:
 *
 *   Is voting growing, or is one nominee's campaign masking a flat week?
 *   How many nominations survive to become a nominee, and where do the rest die?
 *   Do voters come back, or is every week a fresh crowd?
 *   Which states are we absent from?
 *   How much of the vote is paid, and is that share moving?
 *   Is the support desk keeping up, or is the backlog quietly growing?
 *
 * A level cannot answer any of them. That is the whole reason this class exists,
 * and it is why nothing here returns a bare count without something to divide it
 * by or a series to sit in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * RULES THIS FILE HOLDS TO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * SERIES ARE GAP-FILLED. A `GROUP BY date` returns only the days something
 * happened. Charted straight, three votes in three separate weeks become a
 * smooth rising line. Every series here emits a row per day including the zeroes,
 * for the same reason {@see FinanceService::daily()} does.
 *
 * EVERY TABLE IS OPTIONAL. This schema drifts — the community feed, claims and
 * attachments all shipped as migrations after the core, so a given deployment may
 * be missing any of them. A section with no table returns an empty result and the
 * page renders without it. An analytics screen that 500s is worth less than one
 * that admits a gap.
 *
 * NOTHING HERE IS PERSONAL DATA. Voters are counted through `voter_email_hash`,
 * which is what the votes table stores; no address, name or phone number is read
 * by anything in this file. Analytics is the surface most likely to be screen-
 * shotted into a group chat, so it holds no identity to leak.
 */
final class AnalyticsService
{
    /** Longest window any single call will look back over. */
    public const MAX_DAYS = 365;

    private static function has(string $table): bool
    {
        try { return DB::schema()->hasTable($table); } catch (\Throwable) { return false; }
    }

    private static function hasCol(string $table, string $col): bool
    {
        try { return DB::schema()->hasColumn($table, $col); } catch (\Throwable) { return false; }
    }

    private static function clampDays(int $days): int
    {
        return max(7, min(self::MAX_DAYS, $days));
    }

    /**
     * A date-keyed map turned into a dense, ordered series.
     *
     * @param array<string,int|float> $sums
     * @return list<array{date:string, value:int|float}>
     */
    private static function fill(array $sums, int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = ['date' => $d, 'value' => $sums[$d] ?? 0];
        }
        return $out;
    }

    /** `substr(created_at,1,10)` works identically on SQLite and MySQL. */
    private static function dayExpr(string $col): string
    {
        return "substr({$col},1,10)";
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AUDIENCE
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Accounts: how many, how fast, how many actually verified and came back.
     *
     * ── WHY "VERIFIED" AND "ACTIVE" ARE REPORTED AS SHARES ───────────────────
     *
     * "12,000 users" is the number that goes in a deck and it is nearly content-
     * free: if 9,000 of them never confirmed an email address, the real audience
     * is 3,000 and every per-user average computed off the headline is wrong by
     * a factor of four. The share is the honest figure, so the share is what this
     * returns alongside the count.
     *
     * @return array{total:int,verified:int,verified_pct:int,active_30d:int,
     *                active_pct:int,new_series:list<array>,new_in_window:int,
     *                growth_pct:?float}
     */
    public static function audience(int $days = 30): array
    {
        $days  = self::clampDays($days);
        $empty = ['total' => 0, 'verified' => 0, 'verified_pct' => 0, 'active_30d' => 0,
                  'active_pct' => 0, 'new_series' => self::fill([], $days),
                  'new_in_window' => 0, 'growth_pct' => null];

        if (!self::has('gates_users')) return $empty;

        try {
            $total    = (int) DB::table('gates_users')->count();
            $verified = self::hasCol('gates_users', 'email_verified')
                ? (int) DB::table('gates_users')->where('email_verified', 1)->count()
                : 0;
            $active = self::hasCol('gates_users', 'last_login_at')
                ? (int) DB::table('gates_users')
                    ->where('last_login_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))->count()
                : 0;

            $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
            $sums = [];
            foreach (DB::table('gates_users')->where('created_at', '>=', $from . ' 00:00:00')
                ->selectRaw(self::dayExpr('created_at') . ' AS d, COUNT(*) AS n')
                ->groupBy('d')->get() as $r) {
                $sums[(string) $r->d] = (int) $r->n;
            }
            $series = self::fill($sums, $days);
            $newNow = array_sum(array_column($series, 'value'));

            // Against the equal window before it — the same rule FinanceInsights
            // uses, and for the same reason: a percentage across two windows of
            // different lengths is not a growth rate.
            $prevFrom = date('Y-m-d', strtotime($from . ' -' . $days . ' days'));
            $newPrev  = (int) DB::table('gates_users')
                ->where('created_at', '>=', $prevFrom . ' 00:00:00')
                ->where('created_at', '<', $from . ' 00:00:00')->count();

            return [
                'total'         => $total,
                'verified'      => $verified,
                'verified_pct'  => $total > 0 ? (int) round($verified * 100 / $total) : 0,
                'active_30d'    => $active,
                'active_pct'    => $total > 0 ? (int) round($active * 100 / $total) : 0,
                'new_series'    => $series,
                'new_in_window' => $newNow,
                'growth_pct'    => $newPrev > 0 ? round((($newNow - $newPrev) / $newPrev) * 100, 1) : null,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // VOTING
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The vote, split exactly the way the integrity model splits it — three ways.
     *
     * ── WHY THREE SERIES AND NOT "FREE VS PAID" ──────────────────────────────
     *
     * `gates_votes.vote_type` has three values and they mean three different
     * things about where a vote came from:
     *
     *   standard  somebody voted. This is the only kind {@see CollusionService}
     *             analyses and the only kind the CPI counts as organic support.
     *   bonus     minted by {@see PointsService} when a member redeems points.
     *             Nobody paid money for it, but nobody freely chose it in the
     *             moment either — it is a loyalty payout, and it is explicitly
     *             CPI-EXCLUDED.
     *   paid      bought, via {@see PaidVoteService}.
     *
     * Collapsing this to a binary is the tempting simplification and it is wrong
     * in a way that matters: folding `bonus` into "free" reports a points
     * redemption as organic support, which is the precise claim the integrity
     * page promises the platform does not make. So all three are carried, and
     * `organic_pct` — not "free" — is the headline.
     *
     * `donation_id` is the fallback for rows written before `vote_type` existed.
     * A row with neither reads as standard: miscounting a paid vote as organic
     * would overstate organic support, so the fallback is checked FIRST and only
     * an absent donation link lands in standard.
     *
     * @return array{total:int,standard:int,bonus:int,paid:int,organic_pct:int,
     *                paid_pct:int,bonus_pct:int,voters:int,per_voter:float,
     *                standard_series:list<array>,paid_series:list<array>,
     *                bonus_series:list<array>,by_hour:array<int,int>,
     *                flagged:int,flagged_pct:int}
     */
    public static function voting(int $days = 30): array
    {
        $days  = self::clampDays($days);
        $empty = ['total' => 0, 'standard' => 0, 'bonus' => 0, 'paid' => 0,
                  'organic_pct' => 0, 'paid_pct' => 0, 'bonus_pct' => 0,
                  'voters' => 0, 'per_voter' => 0.0,
                  'standard_series' => self::fill([], $days),
                  'paid_series' => self::fill([], $days),
                  'bonus_series' => self::fill([], $days),
                  'by_hour' => array_fill(0, 24, 0), 'flagged' => 0, 'flagged_pct' => 0];

        if (!self::has('gates_votes')) return $empty;

        $tsCol = self::hasCol('gates_votes', 'voted_at') ? 'voted_at' : 'created_at';
        $from  = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        try {
            $cols = [$tsCol];
            foreach (['vote_type', 'donation_id', 'voter_email_hash', 'fraud_flag'] as $c) {
                if (self::hasCol('gates_votes', $c)) $cols[] = $c;
            }

            $series = ['standard' => [], 'bonus' => [], 'paid' => []];
            $n      = ['standard' => 0, 'bonus' => 0, 'paid' => 0];
            $hours  = array_fill(0, 24, 0);
            $voters = []; $flagged = 0;

            foreach (DB::table('gates_votes')->where($tsCol, '>=', $from . ' 00:00:00')
                ->get($cols) as $r) {
                $ts = strtotime((string) ($r->{$tsCol} ?? ''));
                if ($ts === false) continue;
                $d = date('Y-m-d', $ts);

                $type = strtolower(trim((string) ($r->vote_type ?? '')));
                if ($type === '') {
                    // Pre-`vote_type` row: a donation link is the only evidence.
                    $type = !empty($r->donation_id ?? null) ? 'paid' : 'standard';
                }
                if (!isset($n[$type])) $type = 'standard';

                $series[$type][$d] = ($series[$type][$d] ?? 0) + 1;
                $n[$type]++;

                $hours[(int) date('G', $ts)]++;
                $h = (string) ($r->voter_email_hash ?? '');
                if ($h !== '') $voters[$h] = true;
                if (!empty($r->fraud_flag ?? null)) $flagged++;
            }

            $total   = array_sum($n);
            $nVoters = count($voters);
            $pct     = static fn (int $x): int => $total > 0 ? (int) round($x * 100 / $total) : 0;

            return [
                'total'           => $total,
                'standard'        => $n['standard'],
                'bonus'           => $n['bonus'],
                'paid'            => $n['paid'],
                'organic_pct'     => $pct($n['standard']),
                'bonus_pct'       => $pct($n['bonus']),
                'paid_pct'        => $pct($n['paid']),
                'voters'          => $nVoters,
                'per_voter'       => $nVoters > 0 ? round($total / $nVoters, 2) : 0.0,
                'standard_series' => self::fill($series['standard'], $days),
                'bonus_series'    => self::fill($series['bonus'], $days),
                'paid_series'     => self::fill($series['paid'], $days),
                'by_hour'         => $hours,
                'flagged'         => $flagged,
                'flagged_pct'     => $total > 0 ? (int) round($flagged * 100 / $total) : 0,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Do voters come back? Weekly cohorts, each followed forward.
     *
     * ── WHY COHORTS AND NOT A SINGLE "RETURNING %" ───────────────────────────
     *
     * One retention number averages a cycle's launch week against its dead weeks
     * and lands somewhere describing neither. A cohort table shows the thing that
     * matters: whether the people who arrived in week 3 behaved like the people
     * who arrived in week 1. If retention is collapsing cohort over cohort, the
     * headline average keeps looking fine for about a month.
     *
     * A voter is a `voter_email_hash`. Rows without one are skipped rather than
     * counted as an anonymous cohort of one each, which would report near-zero
     * retention forever.
     *
     * @return array{weeks:list<string>, rows:list<array{cohort:string,size:int,
     *                retained:list<?int>}>}
     */
    public static function retention(int $weeks = 8): array
    {
        $weeks = max(2, min(26, $weeks));
        if (!self::has('gates_votes') || !self::hasCol('gates_votes', 'voter_email_hash')) {
            return ['weeks' => [], 'rows' => []];
        }

        $tsCol = self::hasCol('gates_votes', 'voted_at') ? 'voted_at' : 'created_at';
        // Monday of the first cohort week.
        $start = strtotime('monday this week -' . ($weeks - 1) . ' weeks');

        $labels = [];
        for ($i = 0; $i < $weeks; $i++) $labels[] = date('Y-m-d', strtotime("+{$i} weeks", $start));

        try {
            $seen = [];   // hash => [weekIndex => true]
            foreach (DB::table('gates_votes')
                ->where($tsCol, '>=', date('Y-m-d', $start) . ' 00:00:00')
                ->get([$tsCol, 'voter_email_hash']) as $r) {
                $h = (string) ($r->voter_email_hash ?? '');
                if ($h === '') continue;
                $ts = strtotime((string) $r->{$tsCol});
                if ($ts === false) continue;
                $idx = (int) floor(($ts - $start) / (7 * 86400));
                if ($idx < 0 || $idx >= $weeks) continue;
                $seen[$h][$idx] = true;
            }

            // Cohort = the week a voter FIRST appears inside this span. Anybody
            // who voted before the span is excluded rather than folded into week
            // 0, where they would inflate the first cohort's retention with
            // behaviour that started earlier.
            $cohorts = [];
            foreach ($seen as $h => $wk) {
                $first = min(array_keys($wk));
                $cohorts[$first][$h] = $wk;
            }

            $rows = [];
            for ($c = 0; $c < $weeks; $c++) {
                $members = $cohorts[$c] ?? [];
                $size    = count($members);
                $ret     = [];
                for ($o = 0; $o < $weeks - $c; $o++) {
                    if ($size === 0) { $ret[] = null; continue; }
                    $n = 0;
                    foreach ($members as $wk) if (isset($wk[$c + $o])) $n++;
                    $ret[] = (int) round($n * 100 / $size);
                }
                $rows[] = ['cohort' => $labels[$c], 'size' => $size, 'retained' => $ret];
            }

            return ['weeks' => $labels, 'rows' => $rows];
        } catch (\Throwable) {
            return ['weeks' => [], 'rows' => []];
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // FUNNELS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Nomination → decision → nominee → claimed profile.
     *
     * Each stage reports what reached it and what fell out, because the drop
     * between two stages is the only actionable number in a funnel. "1,400
     * nominations" is a vanity figure; "1,400 in, 300 approved, 40 claimed" says
     * where to spend the next week.
     *
     * @return array{stages:list<array{key:string,label:string,n:int,pct:?int,note:string}>,
     *                rejected:int, pending:int}
     */
    public static function nominationFunnel(): array
    {
        $out = ['stages' => [], 'rejected' => 0, 'pending' => 0];
        if (!self::has('gates_nominations')) return $out;

        try {
            $total    = (int) DB::table('gates_nominations')->count();
            $approved = (int) DB::table('gates_nominations')->where('status', 'approved')->count();
            $rejected = (int) DB::table('gates_nominations')->where('status', 'rejected')->count();
            $pending  = (int) DB::table('gates_nominations')->where('status', 'pending')->count();

            $nominees = self::has('gates_nominees')
                ? (int) DB::table('gates_nominees')->whereIn('status', ['approved', 'winner', 'runner_up'])->count()
                : 0;

            // Claiming shipped as a later migration; absent is not zero, it is
            // "this deployment does not have the feature", and the stage is
            // dropped rather than drawn as a cliff to nothing.
            $claimed = null;
            if (self::has('gates_nominee_claims')) {
                $claimed = (int) DB::table('gates_nominee_claims')->where('status', 'approved')->count();
            }

            $pct = static fn (int $n): ?int => $total > 0 ? (int) round($n * 100 / $total) : null;

            $stages = [
                ['key' => 'submitted', 'label' => 'Nominations submitted', 'n' => $total,
                 'pct' => $total > 0 ? 100 : null, 'note' => 'Everything the public sent in.'],
                ['key' => 'approved', 'label' => 'Approved by moderation', 'n' => $approved,
                 'pct' => $pct($approved), 'note' => $rejected . ' rejected, ' . $pending . ' still waiting.'],
                ['key' => 'nominee', 'label' => 'Live on a ballot', 'n' => $nominees,
                 'pct' => $pct($nominees), 'note' => 'Approved, published and votable.'],
            ];
            if ($claimed !== null) {
                $stages[] = ['key' => 'claimed', 'label' => 'Profile claimed by the nominee', 'n' => $claimed,
                             'pct' => $pct($claimed), 'note' => 'The nominee proved who they are and took the page.'];
            }

            return ['stages' => $stages, 'rejected' => $rejected, 'pending' => $pending];
        } catch (\Throwable) {
            return $out;
        }
    }

    /**
     * The instrumented ballot funnel, if anything has been recording it.
     *
     * `gates_funnel_events` is written by the vote flow. Steps are returned in
     * observed-volume order rather than a hardcoded sequence: hardcoding the
     * stages here would mean a step added to the flow silently vanishes from the
     * report, which is the failure mode that makes funnel dashboards lie.
     *
     * @return array{steps:list<array{step:string,sessions:int,pct:int}>, sessions:int}
     */
    /**
     * What the platform actually recorded happening, from `gates_events`.
     *
     * ── WHY THIS PANEL EXISTS AT ALL ─────────────────────────────────────────
     *
     * `gates_events` had been written on four paths since the day it was added — a vote
     * cast, a milestone reached, a fraud score flagged, an OTP requested — and read by
     * NOTHING. Rows accumulated for the life of every install so a question could be
     * answered, and nothing ever asked it. The audit that found it recorded the same shape
     * five times over in this codebase; `gates_status_log.components_json` was the closest
     * twin, stored every fifteen minutes so the status page could say "something broke on
     * the 14th" and not which thing.
     *
     * ── AND WHY IT IS NOT JUST A COUNT ───────────────────────────────────────
     *
     * A count of `vote.submitted` is already on this page, better, from the votes table.
     * What this log holds that no domain table does is the ACTOR beside the action — an
     * email hash, an IP hash, a device hash — which is what makes "eleven OTP requests and
     * one vote from one device" a sentence somebody can act on. So the panel reports the
     * distinct actors and devices alongside the volume, because that ratio is the whole
     * reason these rows are worth keeping.
     *
     * @return array{rows:list<array{name:string,count:int,actors:int,devices:int,last:string}>,
     *                total:int}
     */
    public static function platformEvents(int $days = 30): array
    {
        $days  = self::clampDays($days);
        $empty = ['rows' => [], 'total' => 0];

        if (!self::has('gates_events')) return $empty;

        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00';

        try {
            $agg = [];
            // Aggregated in PHP rather than with COUNT(DISTINCT …) per column: the hashes
            // are nullable, MySQL and SQLite disagree about NULL inside DISTINCT, and this
            // table is small — it is one row per notable action, not per request.
            foreach (DB::table('gates_events')->where('created_at', '>=', $from)
                        ->get(['name', 'actor_hash', 'device_hash', 'created_at']) as $r) {
                $name = trim((string) ($r->name ?? ''));
                if ($name === '') continue;

                $agg[$name] ??= ['count' => 0, 'actors' => [], 'devices' => [], 'last' => ''];
                $agg[$name]['count']++;

                $a = (string) ($r->actor_hash ?? '');
                $d = (string) ($r->device_hash ?? '');
                if ($a !== '') $agg[$name]['actors'][$a]   = true;
                if ($d !== '') $agg[$name]['devices'][$d]  = true;

                $at = (string) ($r->created_at ?? '');
                if ($at > $agg[$name]['last']) $agg[$name]['last'] = $at;
            }
        } catch (\Throwable) {
            return $empty;
        }

        $rows  = [];
        $total = 0;
        foreach ($agg as $name => $a) {
            $total += $a['count'];
            $rows[] = [
                'name'    => $name,
                'count'   => (int) $a['count'],
                'actors'  => count($a['actors']),
                'devices' => count($a['devices']),
                'last'    => (string) $a['last'],
            ];
        }

        // Busiest first: the row somebody is on this page about is the one with volume.
        usort($rows, static fn (array $x, array $y): int => $y['count'] <=> $x['count']);

        return ['rows' => $rows, 'total' => $total];
    }

    public static function ballotFunnel(int $days = 30): array
    {
        $days = self::clampDays($days);
        if (!self::has('gates_funnel_events')) return ['steps' => [], 'sessions' => 0];

        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        try {
            $bySt = [];
            $all  = [];
            foreach (DB::table('gates_funnel_events')->where('created_at', '>=', $from . ' 00:00:00')
                ->get(['step', 'session_id']) as $r) {
                $step = (string) ($r->step ?? '');
                $sid  = (string) ($r->session_id ?? '');
                if ($step === '' || $sid === '') continue;
                // Distinct SESSIONS, not events: one person refreshing the ballot
                // six times is one person, and counting events would show a step
                // with more traffic than the step before it.
                $bySt[$step][$sid] = true;
                $all[$sid] = true;
            }

            $sessions = count($all);
            $steps = [];
            foreach ($bySt as $step => $set) {
                $steps[] = ['step' => $step, 'sessions' => count($set),
                            'pct' => $sessions > 0 ? (int) round(count($set) * 100 / $sessions) : 0];
            }
            usort($steps, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);

            return ['steps' => $steps, 'sessions' => $sessions];
        } catch (\Throwable) {
            return ['steps' => [], 'sessions' => 0];
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // GEOGRAPHY
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Where the votes are cast for, and where nominations come from.
     *
     * ── THE USEFUL HALF IS THE ABSENCES ──────────────────────────────────────
     *
     * A top-ten list of states tells you where you already are. The count of
     * states with NOTHING is the number that decides outreach, so it is returned
     * as its own figure rather than left to be inferred from a list that, by
     * construction, cannot show a zero.
     *
     * @return array{vote_countries:list<array>, nomination_states:list<array>,
     *                states_covered:int, states_silent:int}
     */
    public static function geography(int $limit = 12): array
    {
        $out = ['vote_countries' => [], 'nomination_states' => [],
                'states_covered' => 0, 'states_silent' => 0];

        if (self::has('gates_votes') && self::hasCol('gates_votes', 'nominee_country')) {
            try {
                $rows = DB::table('gates_votes')
                    ->whereNotNull('nominee_country')->where('nominee_country', '!=', '')
                    ->selectRaw('nominee_country AS c, COUNT(*) AS n')
                    ->groupBy('c')->orderByDesc('n')->limit($limit)->get();
                $total = (int) DB::table('gates_votes')->count();
                foreach ($rows as $r) {
                    $out['vote_countries'][] = [
                        'code' => (string) $r->c, 'votes' => (int) $r->n,
                        'pct'  => $total > 0 ? (int) round(((int) $r->n) * 100 / $total) : 0,
                    ];
                }
            } catch (\Throwable) {}
        }

        if (self::has('gates_nominations') && self::hasCol('gates_nominations', 'nominee_state')) {
            try {
                $rows = DB::table('gates_nominations')
                    ->whereNotNull('nominee_state')->where('nominee_state', '!=', '')
                    ->selectRaw('nominee_state AS s, COUNT(*) AS n')
                    ->groupBy('s')->orderByDesc('n')->get();
                $covered = count($rows);
                foreach (array_slice($rows->all(), 0, $limit) as $r) {
                    $out['nomination_states'][] = ['state' => (string) $r->s, 'n' => (int) $r->n];
                }
                $out['states_covered'] = $covered;
                // 36 states plus the FCT. Nigeria-specific and deliberately so —
                // this is where the platform's nominations come from, and a
                // generic "regions we have" count would not be actionable.
                $out['states_silent'] = max(0, 37 - $covered);
            } catch (\Throwable) {}
        }

        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // COMMUNITY
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The feed: posts, replies, cheers, and how much of it is one person.
     *
     * ── PARTICIPATION IS THE FIGURE, NOT VOLUME ──────────────────────────────
     *
     * A feed with a thousand posts from four accounts is not a community, and
     * "1,000 posts" reports it as one. `authors` and the top-author share are
     * returned next to the volume so that shape is visible immediately.
     *
     * @return array{posts:int,replies:int,cheers:int,authors:int,
     *                top_author_pct:int,series:list<array>,pending_moderation:int}
     */
    public static function community(int $days = 30): array
    {
        $days  = self::clampDays($days);
        $empty = ['posts' => 0, 'replies' => 0, 'cheers' => 0, 'authors' => 0,
                  'top_author_pct' => 0, 'series' => self::fill([], $days),
                  'pending_moderation' => 0];

        if (!self::has('gates_threads')) return $empty;
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        try {
            $byDay = []; $authors = []; $posts = 0;
            foreach (DB::table('gates_threads')->where('created_at', '>=', $from . ' 00:00:00')
                ->get(['created_at', 'author_email_hash']) as $r) {
                $d = substr((string) $r->created_at, 0, 10);
                $byDay[$d] = ($byDay[$d] ?? 0) + 1;
                $posts++;
                $h = (string) ($r->author_email_hash ?? '');
                if ($h !== '') $authors[$h] = ($authors[$h] ?? 0) + 1;
            }

            $replies = self::has('gates_comments')
                ? (int) DB::table('gates_comments')->where('created_at', '>=', $from . ' 00:00:00')->count()
                : 0;
            $cheers = self::has('gates_cheers')
                ? (int) DB::table('gates_cheers')->where('created_at', '>=', $from . ' 00:00:00')->count()
                : 0;
            $pending = self::hasCol('gates_comments', 'status') && self::has('gates_comments')
                ? (int) DB::table('gates_comments')->where('status', 'pending')->count()
                : 0;

            return [
                'posts'   => $posts,
                'replies' => $replies,
                'cheers'  => $cheers,
                'authors' => count($authors),
                'top_author_pct' => $posts > 0 && $authors !== []
                    ? (int) round(max($authors) * 100 / $posts) : 0,
                'series'  => self::fill($byDay, $days),
                'pending_moderation' => $pending,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SUPPORT DESK
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Is the desk keeping up?
     *
     * ── BACKLOG DIRECTION IS THE ALARM ───────────────────────────────────────
     *
     * Open-ticket count on its own is ambiguous: forty open tickets is fine on a
     * results day and terrible on a quiet Tuesday. Opened minus resolved over the
     * window says whether the queue is growing, and that is the number that
     * should make somebody act.
     *
     * ── AND WHY MEDIAN FIRST RESPONSE, NOT MEAN ──────────────────────────────
     *
     * One ticket answered after nine days drags a mean past a day and hides that
     * the typical reply came in twenty minutes. The median is what a person
     * writing in actually experiences.
     *
     * @return array{opened:int,resolved:int,open_now:int,backlog_delta:int,
     *                median_first_reply_mins:?int,unanswered:int,series:list<array>}
     */
    public static function support(int $days = 30): array
    {
        $days  = self::clampDays($days);
        $empty = ['opened' => 0, 'resolved' => 0, 'open_now' => 0, 'backlog_delta' => 0,
                  'median_first_reply_mins' => null, 'unanswered' => 0,
                  'series' => self::fill([], $days)];

        if (!self::has('gates_support_tickets')) return $empty;
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        try {
            $byDay = []; $opened = 0; $ids = [];
            foreach (DB::table('gates_support_tickets')->where('created_at', '>=', $from . ' 00:00:00')
                ->get(['id', 'created_at']) as $r) {
                $d = substr((string) $r->created_at, 0, 10);
                $byDay[$d] = ($byDay[$d] ?? 0) + 1;
                $opened++;
                $ids[(int) $r->id] = (string) $r->created_at;
            }

            $resolved = self::hasCol('gates_support_tickets', 'resolved_at')
                ? (int) DB::table('gates_support_tickets')
                    ->whereNotNull('resolved_at')->where('resolved_at', '>=', $from . ' 00:00:00')->count()
                : 0;
            $openNow = (int) DB::table('gates_support_tickets')->whereIn('status', ['open', 'pending'])->count();

            // ── median first reply ───────────────────────────────────────────
            $waits = []; $answered = [];
            if ($ids !== [] && self::has('gates_support_messages')) {
                foreach (DB::table('gates_support_messages')
                    ->whereIn('ticket_id', array_keys($ids))
                    ->where('author_type', '!=', 'user')
                    ->orderBy('id')
                    ->get(['ticket_id', 'created_at']) as $m) {
                    $tid = (int) $m->ticket_id;
                    if (isset($answered[$tid])) continue;      // first reply only
                    $answered[$tid] = true;
                    $delta = strtotime((string) $m->created_at) - strtotime($ids[$tid]);
                    if ($delta >= 0) $waits[] = (int) round($delta / 60);
                }
            }
            sort($waits);
            $median = null;
            if ($waits !== []) {
                $n = count($waits);
                $median = $n % 2 === 1
                    ? $waits[intdiv($n, 2)]
                    : (int) round(($waits[$n / 2 - 1] + $waits[$n / 2]) / 2);
            }

            return [
                'opened'   => $opened,
                'resolved' => $resolved,
                'open_now' => $openNow,
                'backlog_delta' => $opened - $resolved,
                'median_first_reply_mins' => $median,
                'unanswered' => max(0, $opened - count($answered)),
                'series' => self::fill($byDay, $days),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // DELIVERABILITY
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Did the email actually go out?
     *
     * On this platform email is not a nicety: a vote code that does not arrive is
     * a vote that does not happen, and the failure is silent on every other
     * screen. `gates_mail_log` records the attempt, so the send-failure rate
     * belongs on the analytics page next to the numbers it silently depresses.
     *
     * @return array{sent:int,failed:int,failure_pct:int,by_category:list<array>}
     */
    public static function deliverability(int $days = 30): array
    {
        $days = self::clampDays($days);
        $out  = ['sent' => 0, 'failed' => 0, 'failure_pct' => 0, 'by_category' => []];
        if (!self::has('gates_mail_log')) return $out;

        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        try {
            $cats = [];
            $sent = 0; $failed = 0;
            foreach (DB::table('gates_mail_log')->where('created_at', '>=', $from . ' 00:00:00')
                ->get(['status', 'category']) as $r) {
                $ok  = in_array(strtolower((string) $r->status), ['sent', 'ok', 'queued'], true);
                $cat = (string) ($r->category ?? '') ?: 'uncategorised';
                if (!isset($cats[$cat])) $cats[$cat] = ['category' => $cat, 'sent' => 0, 'failed' => 0];
                if ($ok) { $sent++; $cats[$cat]['sent']++; }
                else     { $failed++; $cats[$cat]['failed']++; }
            }
            $total = $sent + $failed;
            foreach ($cats as &$c) {
                $n = $c['sent'] + $c['failed'];
                $c['failure_pct'] = $n > 0 ? (int) round($c['failed'] * 100 / $n) : 0;
            }
            unset($c);
            usort($cats, static fn (array $a, array $b): int => $b['failed'] <=> $a['failed']);

            return ['sent' => $sent, 'failed' => $failed,
                    'failure_pct' => $total > 0 ? (int) round($failed * 100 / $total) : 0,
                    'by_category' => array_values($cats)];
        } catch (\Throwable) {
            return $out;
        }
    }
}
