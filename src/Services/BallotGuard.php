<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE gate. Every path that writes a vote or a nomination asks this class, and
 * only this class, whether the cycle's phase permits it.
 *
 * Before this existed there were five independent gates — the OTP vote
 * transaction, points redemption, donation bonus votes, paid-vote checkout and
 * nomination submission — each comparing `gates_award_cycles.status` to a
 * literal string, plus a sixth path (minting paid votes after payment confirms)
 * with no check at all. Because they all read the stored column and none read
 * the clock, a scheduler that never ran meant voting stayed open indefinitely
 * past its published close date.
 *
 * The guard is deliberately fail-CLOSED: an unresolvable cycle refuses the
 * write. The old behaviour of several callers was to fall through on a missing
 * row, which is how an orphaned category stayed votable forever.
 *
 * ENFORCEMENT MODE. `phase_enforcement` in gates_settings selects:
 *   'strict' (default) — refuse the write, the computed phase is authoritative.
 *   'shadow'           — allow whatever the stored column allowed, but record
 *                        every divergence to gates_phase_drift so an operator
 *                        can see exactly which live cycles are mis-phased
 *                        BEFORE enforcement starts refusing real traffic.
 * Shadow mode is the migration safety net; strict is the destination.
 */
final class BallotGuard
{
    /** Refuse unless the category's cycle is in its voting window. */
    public static function assertVotable(int $categoryId, ?Carbon $now = null): void
    {
        $cycle = self::cycleForCategory($categoryId);
        if (!$cycle) throw PhaseError::noCycle('category');
        self::assertPhase($cycle, 'vote', $now);
    }

    /** Refuse unless the programme's current cycle is in its nominations window. */
    public static function assertNominable(int $programmeId, ?Carbon $now = null): void
    {
        $cycle = self::currentCycleForProgramme($programmeId);
        if (!$cycle) throw PhaseError::noCycle('programme');
        self::assertPhase($cycle, 'nominate', $now);
    }

    /** Non-throwing form, for read paths and templates. */
    public static function isVotable(int $categoryId, ?Carbon $now = null): bool
    {
        try { self::assertVotable($categoryId, $now); return true; }
        catch (PhaseError) { return false; }
    }

    /** Non-throwing form, for read paths and templates. */
    public static function isNominable(int $programmeId, ?Carbon $now = null): bool
    {
        try { self::assertNominable($programmeId, $now); return true; }
        catch (PhaseError) { return false; }
    }

    /**
     * The shared decision. $action is 'vote' or 'nominate'.
     *
     * @throws PhaseError when the phase forbids the action (strict mode)
     */
    private static function assertPhase(object $cycle, string $action, ?Carbon $now): void
    {
        $now   = $now ?? Carbon::now();
        $state = CyclePolicy::stateFor($cycle, $now);
        $phase = CyclePhase::from($state['phase']);

        $allowedByPhase  = $action === 'vote' ? $phase->isVotingOpen() : $phase->isNominationsOpen();
        $allowedByColumn = $action === 'vote'
            ? CyclePhase::fromStored($cycle->status ?? null)->isVotingOpen()
            : CyclePhase::fromStored($cycle->status ?? null)->isNominationsOpen();

        // Record the divergence whichever mode we are in — this is the data an
        // operator needs to reconcile a mis-configured cycle.
        if ($allowedByPhase !== $allowedByColumn) {
            self::recordDrift((int) ($cycle->id ?? 0), $action, $state, $allowedByPhase, $allowedByColumn);
        }

        if (self::mode() === 'shadow' ? $allowedByColumn : $allowedByPhase) {
            return;
        }

        if ($phase === CyclePhase::Archived) throw PhaseError::archived();

        if ($action === 'vote') {
            throw $phase->ordinal() < CyclePhase::Voting->ordinal()
                ? PhaseError::votingNotOpenYet($phase, $cycle->voting_open ?? null)
                : PhaseError::votingClosed($phase, $cycle->voting_close ?? null);
        }
        throw PhaseError::nominationsClosed($phase, $cycle->nominations_open ?? null);
    }

    /** 'strict' (default) or 'shadow'. */
    public static function mode(): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'phase_enforcement')->value('value');
            return strtolower(trim((string) $v)) === 'shadow' ? 'shadow' : 'strict';
        } catch (\Throwable) {
            return 'strict';
        }
    }

    /**
     * Append a divergence between the computed phase and the stored column.
     * Best-effort: a missing table or a failed insert must never block or
     * change a write decision.
     */
    private static function recordDrift(int $cycleId, string $action, array $state, bool $byPhase, bool $byColumn): void
    {
        try {
            DB::table('gates_phase_drift')->insert([
                'cycle_id'       => $cycleId,
                'action'         => $action,
                'computed_phase' => (string) $state['phase'],
                'stored_status'  => (string) $state['stored_status'],
                'would_allow'    => $byColumn ? 1 : 0,
                'phase_allows'   => $byPhase ? 1 : 0,
                'mode'           => self::mode(),
                'created_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) { /* best-effort telemetry */ }
    }

    /**
     * The cycle owning a category, with the window columns the policy needs.
     * Deliberately NOT memoised — a stale row here would mis-gate a write, and
     * these are indexed primary-key joins hit a handful of times per request.
     */
    private static function cycleForCategory(int $categoryId): ?object
    {
        try {
            $row = DB::table('gates_award_cycles as cy')
                ->join('gates_award_categories as c', 'c.cycle_id', '=', 'cy.id')
                ->where('c.id', $categoryId)
                ->select(['cy.id', 'cy.status', 'cy.year', 'cy.programme_id',
                          'cy.nominations_open', 'cy.nominations_close',
                          'cy.voting_open', 'cy.voting_close', 'cy.results_date'])
                ->first();
        } catch (\Throwable) {
            return null;
        }
        return $row;
    }

    /**
     * A programme's CURRENT cycle — an in-flight one first, then the most
     * recent. Deliberately NOT matched on the calendar year: the nomination
     * write path used `year = date('Y')` while every public page used this
     * status-priority pick, so a cycle tagged with a different year was
     * advertised as open and then rejected at submit.
     */
    public static function currentCycleForProgramme(int $programmeId): ?object
    {
        try {
            $row = DB::table('gates_award_cycles')
                ->where('programme_id', $programmeId)
                ->orderByRaw("CASE WHEN status IN ('nominations','voting','judging','results') THEN 0 ELSE 1 END")
                ->orderByDesc('year')->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }
        return $row;
    }

    /** The phase view-model for a category's cycle (null when unresolvable). */
    public static function stateForCategory(int $categoryId, ?Carbon $now = null): ?array
    {
        $cycle = self::cycleForCategory($categoryId);
        return $cycle ? CyclePolicy::stateFor($cycle, $now) : null;
    }

    /** The phase view-model for a programme's current cycle (null when none). */
    public static function stateForProgramme(int $programmeId, ?Carbon $now = null): ?array
    {
        $cycle = self::currentCycleForProgramme($programmeId);
        return $cycle ? CyclePolicy::stateFor($cycle, $now) : null;
    }
}
