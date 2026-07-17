<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Award-cycle lifecycle policy shared by the automated advancer
 * ({@see \AfricaGates\Console\Commands\CycleAdvanceCommand}) and the admin
 * cycle editor. The cron advances one legal step at a time off the date windows
 * and, on entry to 'results', promotes winners through the judge-quorum-checked
 * path. The admin editor must not be able to produce a cycle state that machine
 * never would — jumping straight to 'results' (crowning before judging/quorum),
 * skipping phases, or regressing a finished cycle — on a platform whose whole
 * value proposition is tamper-evident, hash-chained standings.
 */
final class CycleService
{
    /** Lifecycle ordinal — the single source of forward-only ordering. */
    public const ORDER = ['upcoming' => 0, 'nominations' => 1, 'voting' => 2, 'judging' => 3, 'results' => 4, 'archived' => 5];

    /**
     * Validate a MANUAL (admin) cycle status change. Returns null when allowed,
     * or a human-readable reason it is refused. $from is null for a brand-new
     * cycle. Editing dates/labels without changing status always passes.
     *
     * Policy:
     *  - 'results' is never set by hand — winners are promoted only through the
     *    date-driven, quorum-checked path (set the results date and let the
     *    platform publish them). This is the one transition with an integrity
     *    side effect, so it must not be reachable from a plain dropdown.
     *  - forward-only: a finished/advanced cycle can't be regressed here.
     *  - one phase at a time: no skipping (e.g. nominations → judging).
     *  - a brand-new cycle may start at any phase up to 'judging' (not
     *    'results'/'archived').
     */
    public static function manualTransitionError(?string $from, string $to): ?string
    {
        if (!isset(self::ORDER[$to])) {
            return 'Unknown cycle status.';
        }
        if ($to === 'results') {
            return 'Results can\'t be set by hand. Set the cycle\'s results date — the platform publishes winners automatically through the judged, quorum-checked path, so the standings stay tamper-evident.';
        }
        // Brand-new cycle: allow any starting phase except results/archived.
        if ($from === null) {
            if ($to === 'archived') {
                return 'A new cycle can\'t start archived.';
            }
            return null;
        }
        if (!isset(self::ORDER[$from])) {
            return null; // unknown current status — don't block an edit that repairs it
        }
        $fromOrd = self::ORDER[$from];
        $toOrd   = self::ORDER[$to];
        if ($toOrd === $fromOrd) {
            return null; // no status change (date/label edit)
        }
        if ($toOrd < $fromOrd) {
            return 'A cycle can only move forward. Reopening an earlier phase would undermine the published standings; adjust the date windows instead of moving the status back.';
        }
        if ($toOrd > $fromOrd + 1) {
            return 'Advance one phase at a time (' . $from . ' → ' . self::statusName($fromOrd + 1) . '), so no phase — especially voting — is ever skipped.';
        }
        return null;
    }

    /** Lifecycle status name for an ordinal (inverse of self::ORDER). */
    public static function statusName(int $ord): string
    {
        $name = array_search($ord, self::ORDER, true);
        return $name !== false ? (string) $name : 'archived';
    }
}
