<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Support\Carbon;

/**
 * THE invariant of the award lifecycle:
 *
 *   A cycle's phase is a pure function of its date windows and the current time.
 *
 * Nothing here touches the database, the clock singleton, or the session. `$now`
 * is always injected, which is what makes the whole lifecycle testable at
 * arbitrary instants (the old implementation could only be tested by seeding
 * relative dates and hoping).
 *
 * WHY THIS EXISTS. Previously the phase lived in `gates_award_cycles.status`
 * and only a cron job converted a passed date into a phase change. On a host
 * where that job did not run — no crontab, or the opportunistic web-cron left
 * at its default OFF — the published close date passed and voting stayed open
 * indefinitely. Votes cast after the close were accepted and counted toward the
 * Cultural Power Index. Making the phase computed means voting closes on
 * schedule even if every scheduler on the box is dead; the cron is demoted to
 * materialising the cached column, sending phase mail and promoting winners.
 *
 * EVALUATION ORDER. Windows are tested from the LAST backwards, first match
 * wins. The old code walked forwards and let each later `if` overwrite the
 * previous one, which is how a cycle whose nominations had closed reported a
 * target of 'judging' while its voting window was still in the future.
 */
final class CyclePolicy
{
    /** A cycle is "closing soon" inside this many seconds of its close. */
    public const CLOSING_SOON_SECONDS = 48 * 3600;

    /**
     * The authoritative phase for a cycle at a given instant.
     *
     * A cycle with NO windows at all falls back to its stored status — legacy
     * rows and hand-made cycles keep working. An ARCHIVED cycle stays archived
     * whatever the dates say: archiving is a deliberate manual act, not a
     * consequence of the calendar.
     *
     * @param object|array<string,mixed> $cycle a gates_award_cycles row
     */
    public static function phaseFor(object|array $cycle, ?Carbon $now = null): CyclePhase
    {
        $c   = (object) $cycle;
        $now = $now ?? Carbon::now();

        $stored = CyclePhase::fromStored($c->status ?? null);
        if ($stored === CyclePhase::Archived) return CyclePhase::Archived;

        $nomOpen   = self::at($c->nominations_open  ?? null);
        $nomClose  = self::at($c->nominations_close ?? null);
        $voteOpen  = self::at($c->voting_open       ?? null);
        $voteClose = self::at($c->voting_close      ?? null);
        $results   = self::at($c->results_date      ?? null);

        // No windows set — nothing to derive from, so trust the stored column.
        if (!$nomOpen && !$nomClose && !$voteOpen && !$voteClose && !$results) {
            return $stored;
        }

        // Latest window first; the first satisfied boundary wins.
        if ($results   && $now->gte($results))   return CyclePhase::Results;
        if ($voteClose && $now->gte($voteClose)) return CyclePhase::Judging;
        if ($voteOpen  && $now->gte($voteOpen))  return CyclePhase::Voting;

        // CLOSE-ONLY WINDOW. An operator who sets only `voting_close` ("voting
        // ends 15 Aug") has declared a voting window with an unstated start, so
        // voting runs until that close — beginning when nominations close, or
        // immediately if there is no nominations window either. Honouring this
        // is the whole point: the close date must bind even when the start was
        // never filled in.
        //
        // The stored column is consulted here, and ONLY here, as a tiebreaker:
        // a cycle whose operator has already moved it past voting must not have
        // voting resurrected by an inferred start. A start date was never given,
        // so there is no window to contradict.
        if (!$voteOpen && $voteClose && (!$nomClose || $now->gte($nomClose))) {
            return $stored->ordinal() <= CyclePhase::Voting->ordinal()
                ? CyclePhase::Voting
                : $stored;   // operator already moved it to judging/results
        }

        if ($nomClose  && $now->gte($nomClose)) {
            // The gap after nominations. It is only "shortlisting" if a voting
            // window is actually coming; with no voting_open set, the cycle has
            // gone straight to the jury and we must NOT invent a voting window.
            return $voteOpen ? CyclePhase::Shortlisting : CyclePhase::Judging;
        }
        if ($nomOpen && $now->gte($nomOpen)) return CyclePhase::Nominations;

        return CyclePhase::Upcoming;
    }

    /**
     * The one view-model every lifecycle-rendering surface consumes, so no two
     * places on a page can derive the same fact differently. Returned as a
     * plain array to match how this codebase hands data to Twig.
     *
     * `drifted` is the shadow-mode signal: true when the materialised column
     * disagrees with the computed phase. It is how a divergence gets surfaced
     * to operators instead of silently mis-gating traffic.
     *
     * @param object|array<string,mixed> $cycle
     * @return array<string,mixed>
     */
    public static function stateFor(object|array $cycle, ?Carbon $now = null): array
    {
        $c     = (object) $cycle;
        $now   = $now ?? Carbon::now();
        $phase = self::phaseFor($c, $now);

        [$opensAt, $closesAt] = self::boundsFor($phase, $c);
        $secondsLeft = $closesAt ? max(0, $closesAt->getTimestamp() - $now->getTimestamp()) : null;
        $stored      = CyclePhase::fromStored($c->status ?? null);

        return [
            'phase'               => $phase->value,
            'label'               => $phase->label(),
            'detail'              => self::detailFor($phase, $opensAt, $closesAt, $secondsLeft),
            'is_open'             => $phase->isVotingOpen() || $phase->isNominationsOpen(),
            'is_voting_open'      => $phase->isVotingOpen(),
            'is_nominations_open' => $phase->isNominationsOpen(),
            'is_finished'         => $phase->isFinished(),
            'opens_at'            => $opensAt?->toDateTimeString(),
            'closes_at'           => $closesAt?->toDateTimeString(),
            'seconds_left'        => $secondsLeft,
            'closing_soon'        => $secondsLeft !== null && $secondsLeft > 0 && $secondsLeft <= self::CLOSING_SOON_SECONDS,
            'ordinal'             => $phase->ordinal(),
            'year'                => isset($c->year) ? (int) $c->year : null,
            'stored_status'       => $stored->value,
            'drifted'             => $stored->value !== $phase->storedValue(),
        ];
    }

    /**
     * The window bounding the CURRENT phase: when it began and when it ends.
     * Null bounds are honest — "no close date set" must not render as a
     * countdown to nothing.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private static function boundsFor(CyclePhase $phase, object $c): array
    {
        $nomOpen   = self::at($c->nominations_open  ?? null);
        $nomClose  = self::at($c->nominations_close ?? null);
        $voteOpen  = self::at($c->voting_open       ?? null);
        $voteClose = self::at($c->voting_close      ?? null);
        $results   = self::at($c->results_date      ?? null);

        return match ($phase) {
            CyclePhase::Upcoming     => [null, $nomOpen ?: $voteOpen],
            CyclePhase::Nominations  => [$nomOpen, $nomClose],
            CyclePhase::Shortlisting => [$nomClose, $voteOpen],
            // A close-only window has no declared start; fall back to the
            // nominations close so the countdown still has a real bound.
            CyclePhase::Voting       => [$voteOpen ?: $nomClose, $voteClose],
            CyclePhase::Judging      => [$voteClose ?: $nomClose, $results],
            CyclePhase::Results      => [$results, null],
            CyclePhase::Archived     => [null, null],
        };
    }

    /**
     * Human detail line. Deliberately derived from the SAME bounds as
     * `is_open`, so a page can never simultaneously claim "Voting open" and
     * show a close date that has already passed.
     */
    private static function detailFor(CyclePhase $phase, ?Carbon $opensAt, ?Carbon $closesAt, ?int $secondsLeft): string
    {
        if ($phase === CyclePhase::Archived) return 'This cycle is closed.';
        if ($phase === CyclePhase::Results)  return 'Winners have been announced.';

        if ($phase === CyclePhase::Upcoming) {
            return $opensAt ? 'Opens ' . $opensAt->format('j M Y') : 'Dates to be announced.';
        }
        if ($phase === CyclePhase::Shortlisting) {
            return $closesAt ? 'Voting opens ' . $closesAt->format('j M Y') : 'Voting dates to be announced.';
        }
        if ($phase === CyclePhase::Judging) {
            return $closesAt ? 'Results ' . $closesAt->format('j M Y') : 'Results date to be announced.';
        }

        // Nominations / Voting — the two phases with a live deadline.
        $noun = $phase === CyclePhase::Voting ? 'Voting' : 'Nominations';
        if ($closesAt === null)   return $noun . ' is open.';
        if ($secondsLeft === 0)   return $noun . ' closed ' . $closesAt->format('j M Y') . '.';
        return $noun . ' closes ' . self::humanRemaining($secondsLeft) . ' · ' . $closesAt->format('j M Y');
    }

    /** "in 3 days" / "in 5 hours" / "in 12 minutes" — never a bare "0 days left". */
    public static function humanRemaining(int $seconds): string
    {
        if ($seconds >= 172800) return 'in ' . (int) floor($seconds / 86400) . ' days';
        if ($seconds >= 86400)  return 'tomorrow';
        if ($seconds >= 7200)   return 'in ' . (int) floor($seconds / 3600) . ' hours';
        if ($seconds >= 3600)   return 'in an hour';
        if ($seconds >= 120)    return 'in ' . (int) floor($seconds / 60) . ' minutes';
        return 'in under a minute';
    }

    /** Parse a stored datetime; an unparseable value is treated as absent. */
    private static function at(mixed $v): ?Carbon
    {
        if ($v === null || $v === '' || $v === '0000-00-00 00:00:00') return null;
        if ($v instanceof Carbon) return $v;
        try { return Carbon::parse((string) $v); } catch (\Throwable) { return null; }
    }
}
