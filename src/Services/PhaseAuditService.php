<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A read-only reckoning of what the broken lifecycle already did to the data.
 *
 * Everything else built here fixes the FUTURE: {@see CyclePolicy} computes the
 * phase so voting closes on time, and {@see BallotGuard} refuses writes outside
 * the window. Neither touches the rows written during the years when nothing
 * closed — votes cast after a deadline, nominations taken after nominations
 * ended, money charged for votes that either never landed or landed too late.
 *
 * That backlog is the reason strict enforcement cannot simply be switched on and
 * declared done. Three questions have to be answered against real data first, and
 * this class answers exactly those three and nothing else:
 *
 *   1. WHAT IS ALREADY WRONG? Votes and nominations outside their declared
 *      windows, per cycle, with the first and last offending timestamp — so the
 *      damage can be sized before deciding whether to void, annotate or accept it.
 *   2. WHO IS OWED MONEY? Confirmed 'paid-vote' orders that never minted, and
 *      paid votes that minted after voting closed. The first group paid and got
 *      nothing; the second got weighted votes they should never have had. Both
 *      are cash decisions, so both are reported in naira.
 *   3. WHAT IS UNCROWNED? Categories in a finished cycle with approved nominees
 *      and no winner — the historic 'results' backlog.
 *
 * It also reports the DB↔PHP clock skew, because the answer to "are these
 * timestamps even comparable?" decides whether the rest of the report can be
 * trusted at all. A SQLite `CURRENT_TIMESTAMP` default is UTC while MySQL's is
 * session-local, and this codebase's tables mix DATETIME and TIMESTAMP, so a
 * non-zero skew here means some of these findings are timezone artefacts rather
 * than real offences. Read that section first.
 *
 * DELIBERATELY READ-ONLY. Not one statement here writes. What to do about a
 * finding — refund, void, crown, or accept and move on — is an operator's call
 * with money and reputation attached, and the existing tooling already covers
 * the doing: {@see \AfricaGates\Console\Commands\PaymentClawbackCommand} for
 * reversing a paid order, {@see CycleMaterialiser} for promoting winners. This
 * only tells the truth about the current state.
 *
 * HOW WINDOWS ARE JUDGED. Half-open, `[open, close)`, matching CyclePolicy: a
 * vote AT the closing instant is late. Only DECLARED boundaries are used — a
 * cycle with no `voting_close` is reported as unjudgeable rather than assigned an
 * inferred window, because inventing a deadline in an audit would manufacture
 * offences that no operator ever announced. Those cycles appear in `undated`.
 */
final class PhaseAuditService
{
    /**
     * The whole report.
     *
     * @return array{
     *   generated_at:string,
     *   clock:array<string,mixed>,
     *   cycles:list<array<string,mixed>>,
     *   undated:list<array<string,mixed>>,
     *   votes_after_close:list<array<string,mixed>>,
     *   votes_before_open:list<array<string,mixed>>,
     *   nominations_outside_window:list<array<string,mixed>>,
     *   paid_unminted:array<string,mixed>,
     *   paid_minted_late:array<string,mixed>,
     *   results_backlog:list<array<string,mixed>>,
     *   totals:array<string,int>
     * }
     */
    public static function run(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        $report = [
            'generated_at'               => $now->toDateTimeString(),
            'clock'                      => self::clock($now),
            'cycles'                     => self::cycles($now),
            'undated'                    => self::undated(),
            'votes_after_close'          => self::votesOutside('after'),
            'votes_before_open'          => self::votesOutside('before'),
            'nominations_outside_window' => self::nominationsOutside(),
            'paid_unminted'              => self::paidUnminted($now),
            'paid_minted_late'           => self::paidMintedLate(),
            'results_backlog'            => self::resultsBacklog($now),
        ];

        $report['totals'] = [
            'drifted_cycles'    => count(array_filter($report['cycles'], fn ($c) => $c['drifted'])),
            'undated_cycles'    => count($report['undated']),
            'late_votes'        => (int) array_sum(array_column($report['votes_after_close'], 'votes')),
            'early_votes'       => (int) array_sum(array_column($report['votes_before_open'], 'votes')),
            'late_nominations'  => (int) array_sum(array_column($report['nominations_outside_window'], 'nominations')),
            'unminted_orders'   => (int) $report['paid_unminted']['orders'],
            'late_paid_orders'  => (int) $report['paid_minted_late']['orders'],
            'uncrowned'         => count($report['results_backlog']),
        ];

        return $report;
    }

    /** True when the report found nothing an operator needs to act on. */
    public static function isClean(array $report): bool
    {
        foreach ($report['totals'] ?? [] as $n) {
            if ((int) $n > 0) return false;
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Clock
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Whether the database's idea of "now" agrees with PHP's.
     *
     * Every finding below compares a stored timestamp against a stored boundary,
     * and rows written by a DB-side `CURRENT_TIMESTAMP` default are only
     * comparable to rows written by PHP's `Carbon::now()` if these two agree.
     * SQLite's `CURRENT_TIMESTAMP` is always UTC; MySQL's follows the session
     * `time_zone`. A skew of one whole timezone offset is the signature of the
     * unresolved DATETIME-vs-TIMESTAMP question, not of a slow clock — so it is
     * reported in seconds AND in hours, and flagged past a minute.
     *
     * @return array<string,mixed>
     */
    private static function clock(Carbon $now): array
    {
        $driver = 'unknown';
        $dbNow  = null;
        try {
            $driver = DB::connection()->getDriverName();
            $row    = DB::selectOne('SELECT CURRENT_TIMESTAMP AS t');
            $dbNow  = $row === null ? null : (string) (is_array($row) ? $row['t'] : $row->t);
        } catch (\Throwable) { /* reported as unknown below */ }

        $skew = null;
        if ($dbNow !== null) {
            $ts = strtotime($dbNow);
            if ($ts !== false) $skew = $ts - $now->getTimestamp();
        }

        return [
            'driver'        => $driver,
            'php_timezone'  => date_default_timezone_get(),
            'php_now'       => $now->toDateTimeString(),
            'db_now'        => $dbNow,
            'skew_seconds'  => $skew,
            'skew_hours'    => $skew === null ? null : round($skew / 3600, 2),
            // A whole-hour skew is a timezone mismatch; a few seconds is latency.
            'suspicious'    => $skew !== null && abs($skew) > 60,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cycles
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every cycle with its stored status beside its computed phase.
     *
     * {@see CycleMaterialiser::divergences()} answers the narrower operational
     * question — is the materialiser behind RIGHT NOW — by scanning only cycles
     * whose indexed boundary has passed. This is the audit view: no index
     * shortcut, every cycle, including the ones whose `next_boundary_at` was
     * never backfilled, because a cycle missing from the fast path is precisely
     * the one that has been drifting unnoticed.
     *
     * @return list<array<string,mixed>>
     */
    private static function cycles(Carbon $now): array
    {
        try {
            $rows = DB::table('gates_award_cycles as c')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->orderBy('c.programme_id')->orderByDesc('c.year')
                ->get(['c.*', 'p.title as programme']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $c) {
            $computed = CyclePolicy::phaseFor($c, $now);
            $stored   = CyclePhase::fromStored($c->status ?? null);
            $out[] = [
                'cycle_id'       => (int) $c->id,
                'programme'      => (string) ($c->programme ?? '—'),
                'year'           => (int) $c->year,
                'stored_status'  => $stored->value,
                'computed_phase' => $computed->value,
                'drifted'        => $computed !== $stored,
                // Signed: a stored phase AHEAD of the computed one means an
                // operator advanced it by hand, which is legitimate. BEHIND
                // means the engine never caught up, which is the bug.
                'direction'      => $computed === $stored ? 'agreed'
                    : ($stored->ordinal() < $computed->ordinal() ? 'behind' : 'ahead'),
                'next_boundary'  => $c->next_boundary_at === null ? null : (string) $c->next_boundary_at,
                'boundary_stale' => $c->next_boundary_at !== null
                    && strtotime((string) $c->next_boundary_at) !== false
                    && strtotime((string) $c->next_boundary_at) <= $now->getTimestamp(),
            ];
        }
        return $out;
    }

    /**
     * Cycles whose voting window was never fully declared.
     *
     * These are unjudgeable rather than clean: with no `voting_close` there is no
     * instant at which a vote became late, so no offence can be attributed — but
     * equally, nothing was ever going to close on its own. Reporting them
     * separately keeps "we checked and found nothing" distinct from "we could not
     * check", which is the difference between a clean audit and a blind one.
     *
     * @return list<array<string,mixed>>
     */
    private static function undated(): array
    {
        try {
            $rows = DB::table('gates_award_cycles')
                ->where(function ($q) {
                    $q->whereNull('voting_close')->orWhereNull('nominations_close');
                })
                ->where('status', '!=', 'archived')
                ->orderByDesc('year')->get();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(fn ($c) => [
            'cycle_id'          => (int) $c->id,
            'year'              => (int) $c->year,
            'stored_status'     => (string) ($c->status ?? ''),
            'missing'           => array_values(array_filter([
                $c->nominations_close === null ? 'nominations_close' : null,
                $c->voting_close === null ? 'voting_close' : null,
            ])),
        ], $rows->all()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Votes and nominations outside their windows
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Votes recorded outside the declared voting window, grouped by cycle and
     * vote type.
     *
     * $side 'after' uses `voted_at >= voting_close` — half-open, so a vote at the
     * closing instant counts as late, matching how CyclePolicy decides the phase.
     * 'before' uses `voted_at < voting_open`, which catches a different bug: a
     * window edited backwards after voting had begun.
     *
     * Weight is reported alongside the row count because a single paid vote can
     * carry a weight of hundreds — the count says how many offences, the weight
     * says how much the standings moved.
     *
     * @return list<array<string,mixed>>
     */
    private static function votesOutside(string $side): array
    {
        $boundary  = $side === 'after' ? 'cy.voting_close' : 'cy.voting_open';
        $predicate = $side === 'after' ? "v.voted_at >= {$boundary}" : "v.voted_at < {$boundary}";

        try {
            $rows = DB::table('gates_votes as v')
                ->join('gates_award_categories as cat', 'cat.id', '=', 'v.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'cat.cycle_id')
                ->whereNotNull($boundary)
                ->whereRaw($predicate)
                ->groupBy('cy.id', 'cy.year', 'v.vote_type')
                ->orderByDesc('cy.year')
                ->selectRaw(
                    'cy.id AS cycle_id, cy.year AS year, v.vote_type AS vote_type,'
                    . ' COUNT(*) AS votes, COALESCE(SUM(v.weight),0) AS weight,'
                    . ' MIN(v.voted_at) AS first_at, MAX(v.voted_at) AS last_at,'
                    . " MAX({$boundary}) AS boundary_at"
                )
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(fn ($r) => [
            'cycle_id'    => (int) $r->cycle_id,
            'year'        => (int) $r->year,
            'vote_type'   => (string) $r->vote_type,
            'votes'       => (int) $r->votes,
            'weight'      => (int) $r->weight,
            'boundary_at' => (string) $r->boundary_at,
            'first_at'    => (string) $r->first_at,
            'last_at'     => (string) $r->last_at,
        ], $rows->all()));
    }

    /**
     * Nominations accepted after nominations closed.
     *
     * These are the rows {@see AwardService::submitNomination()} would now refuse.
     * They are not deletable damage — a nomination taken late may still be a
     * genuine one, and several may already be approved and voted on — so the
     * report notes how many reached each status. An operator deciding what to do
     * needs to know whether the late intake is 40 pending rows or 40 finalists.
     *
     * @return list<array<string,mixed>>
     */
    private static function nominationsOutside(): array
    {
        try {
            $rows = DB::table('gates_nominations as n')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'n.cycle_id')
                ->whereNotNull('cy.nominations_close')
                ->whereRaw('n.created_at >= cy.nominations_close')
                ->groupBy('cy.id', 'cy.year', 'n.status')
                ->orderByDesc('cy.year')
                ->selectRaw(
                    'cy.id AS cycle_id, cy.year AS year, n.status AS status, COUNT(*) AS nominations,'
                    . ' MIN(n.created_at) AS first_at, MAX(n.created_at) AS last_at,'
                    . ' MAX(cy.nominations_close) AS boundary_at'
                )
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(fn ($r) => [
            'cycle_id'    => (int) $r->cycle_id,
            'year'        => (int) $r->year,
            'status'      => (string) $r->status,
            'nominations' => (int) $r->nominations,
            'boundary_at' => (string) $r->boundary_at,
            'first_at'    => (string) $r->first_at,
            'last_at'     => (string) $r->last_at,
        ], $rows->all()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Money
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Confirmed paid-vote orders that never minted their votes — money taken,
     * nothing delivered.
     *
     * This is the population {@see PaidVoteService::mint()}'s new phase gate
     * creates on purpose: rather than minting weighted votes into a closed
     * cycle it refuses and leaves `votes_used = 0`, making a CONFIRMED
     * 'paid-vote' row with `votes_used = 0` the queryable "refund owed" signal.
     * Already-refunded orders are excluded, since those are settled.
     *
     * The list is split by whether the target's voting window is open again,
     * because the remedy differs: a still-open target can simply be re-minted
     * (mint() is idempotent and its gate is not yet armed on these rows), while a
     * closed one needs a refund via the existing clawback path.
     *
     * $now is threaded through to the votability check rather than letting
     * BallotGuard default to the wall clock: a report that stamps itself
     * `generated_at` and then judges one of its sections against a different
     * instant is not reproducible, and this is the section that decides whether
     * someone gets their money back.
     *
     * @return array<string,mixed>
     */
    private static function paidUnminted(Carbon $now): array
    {
        try {
            $rows = DB::table('gates_donations as d')
                ->leftJoin('gates_nominees as nm', 'nm.id', '=', 'd.intent_nominee_id')
                ->where('d.tier', 'paid-vote')
                ->where('d.status', 'confirmed')
                ->where('d.votes_used', 0)
                ->whereNull('d.refunded_at')
                ->orderBy('d.id')
                ->get(['d.id', 'd.amount_naira', 'd.bonus_votes', 'd.payment_ref',
                       'd.created_at', 'd.intent_nominee_id', 'nm.category_id', 'nm.name as nominee']);
        } catch (\Throwable) {
            return ['orders' => 0, 'naira' => 0, 'votes' => 0, 'rows' => []];
        }

        $out = [];
        foreach ($rows as $d) {
            $catId    = (int) ($d->category_id ?? 0);
            $reMintable = $catId > 0 && BallotGuard::isVotable($catId, $now);
            $out[] = [
                'donation_id' => (int) $d->id,
                'payment_ref' => (string) ($d->payment_ref ?? ''),
                'naira'       => (int) $d->amount_naira,
                'votes'       => (int) $d->bonus_votes,
                'nominee'     => (string) ($d->nominee ?? '(missing nominee)'),
                'created_at'  => (string) $d->created_at,
                // 're-mint' — the window is open, so delivering is still possible.
                // 'refund'  — it is not, and the buyer cannot get what they paid for.
                'remedy'      => $catId === 0 ? 'investigate' : ($reMintable ? 're-mint' : 'refund'),
            ];
        }

        return [
            'orders' => count($out),
            'naira'  => (int) array_sum(array_column($out, 'naira')),
            'votes'  => (int) array_sum(array_column($out, 'votes')),
            'rows'   => $out,
        ];
    }

    /**
     * Paid votes that DID mint after voting closed — the mirror image, and the
     * worse one.
     *
     * Here the platform kept the money and added weighted votes to a public tally
     * for a competition that had already ended. Unlike the unminted case there is
     * no clean remedy: voiding the votes changes a published standing, and
     * leaving them means a closed result was bought after the fact. Sized here so
     * the choice is made with the number in front of the operator.
     *
     * Uses the vote row's own `voted_at`, not the order date, because the order
     * may legitimately predate the deadline — it is the MINT that was late.
     *
     * @return array<string,mixed>
     */
    private static function paidMintedLate(): array
    {
        try {
            $rows = DB::table('gates_votes as v')
                ->join('gates_award_categories as cat', 'cat.id', '=', 'v.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'cat.cycle_id')
                ->leftJoin('gates_donations as d', 'd.id', '=', 'v.donation_id')
                ->where('v.vote_type', 'paid')
                ->whereNotNull('cy.voting_close')
                ->whereRaw('v.voted_at >= cy.voting_close')
                ->orderBy('v.id')
                ->get(['v.id', 'v.weight', 'v.voted_at', 'v.nominee_id', 'v.donation_id',
                       'cy.id as cycle_id', 'cy.year', 'cy.voting_close',
                       'd.amount_naira', 'd.payment_ref', 'd.refunded_at']);
        } catch (\Throwable) {
            return ['orders' => 0, 'weight' => 0, 'naira' => 0, 'rows' => []];
        }

        $out = [];
        foreach ($rows as $v) {
            $out[] = [
                'vote_id'      => (int) $v->id,
                'donation_id'  => (int) ($v->donation_id ?? 0),
                'payment_ref'  => (string) ($v->payment_ref ?? ''),
                'cycle_id'     => (int) $v->cycle_id,
                'year'         => (int) $v->year,
                'nominee_id'   => (int) $v->nominee_id,
                'weight'       => (int) $v->weight,
                'naira'        => (int) ($v->amount_naira ?? 0),
                'voted_at'     => (string) $v->voted_at,
                'closed_at'    => (string) $v->voting_close,
                'days_late'    => CycleMaterialiser::daysLate($v->voting_close, Carbon::parse((string) $v->voted_at)),
                'refunded'     => $v->refunded_at !== null,
            ];
        }

        return [
            'orders' => count($out),
            'weight' => (int) array_sum(array_column($out, 'weight')),
            'naira'  => (int) array_sum(array_column($out, 'naira')),
            'rows'   => $out,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The uncrowned
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Categories in a finished cycle that have approved nominees and no winner.
     *
     * The historic backlog. A cycle reaches 'results' by date, but winners are
     * only promoted when the materialiser claims that transition — and for every
     * cycle that finished while nothing was closing, that claim never happened.
     *
     * An empty category is not a finding: nobody was ever going to win it. A
     * category with approved nominees and no winner is one whose result the
     * platform silently never announced.
     *
     * @return list<array<string,mixed>>
     */
    private static function resultsBacklog(Carbon $now): array
    {
        try {
            $cycles = DB::table('gates_award_cycles')->orderByDesc('year')->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($cycles as $c) {
            if (!CyclePolicy::phaseFor($c, $now)->isFinished()) continue;

            try {
                $cats = DB::table('gates_award_categories as cat')
                    ->where('cat.cycle_id', (int) $c->id)
                    ->leftJoin('gates_nominees as n', function ($j) {
                        $j->on('n.category_id', '=', 'cat.id')->whereNull('n.merged_into');
                    })
                    ->groupBy('cat.id', 'cat.title')
                    ->selectRaw(
                        'cat.id AS category_id, cat.title AS title,'
                        . " SUM(CASE WHEN n.status IN ('approved','winner','runner_up') THEN 1 ELSE 0 END) AS eligible,"
                        . " SUM(CASE WHEN n.status = 'winner' THEN 1 ELSE 0 END) AS winners"
                    )
                    ->get();
            } catch (\Throwable) {
                continue;
            }

            foreach ($cats as $cat) {
                if ((int) $cat->eligible < 1 || (int) $cat->winners > 0) continue;
                $out[] = [
                    'cycle_id'    => (int) $c->id,
                    'year'        => (int) $c->year,
                    'category_id' => (int) $cat->category_id,
                    'category'    => (string) $cat->title,
                    'eligible'    => (int) $cat->eligible,
                    'closed_at'   => $c->voting_close === null ? null : (string) $c->voting_close,
                    'days_late'   => CycleMaterialiser::daysLate($c->results_date ?? $c->voting_close, $now),
                ];
            }
        }
        return $out;
    }
}
