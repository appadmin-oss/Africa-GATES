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
    public const ORDER = ['upcoming' => 0, 'nominations' => 1, 'shortlisting' => 2, 'voting' => 3, 'judging' => 4, 'results' => 5, 'archived' => 6];

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

    /**
     * The statuses {@see manualTransitionError()} will actually accept from
     * $from, so the admin dropdown stops offering options that always fail.
     *
     * The editor used to list all six values including 'results', which is
     * refused unconditionally — the admin picked it, round-tripped, and got a
     * red flash. An option that can never be chosen should not be offered.
     *
     * @return list<string>
     */
    public static function selectableFrom(?string $from): array
    {
        $out = [];
        foreach (array_keys(self::ORDER) as $to) {
            if (self::manualTransitionError($from, $to) === null) $out[] = $to;
        }
        return $out;
    }

    /**
     * Validate the five date windows against each other. Returns null when the
     * ordering is coherent, or a human-readable reason it is not.
     *
     * Nothing validated these before, so it was possible to save a cycle whose
     * voting window closed before it opened, or whose results predate its own
     * voting — configurations that produce a phase no one intended.
     *
     * @param array<string,mixed> $b the submitted form body
     */
    public static function windowError(array $b): ?string
    {
        $at = static function (string $k) use ($b): ?int {
            $v = trim((string) ($b[$k] ?? ''));
            if ($v === '') return null;
            $t = strtotime($v);
            return $t === false ? null : $t;
        };
        $nomOpen  = $at('nominations_open');
        $nomClose = $at('nominations_close');
        $voteOpen = $at('voting_open');
        $voteClose= $at('voting_close');
        $results  = $at('results_date');

        if ($nomOpen && $nomClose && $nomClose <= $nomOpen) {
            return 'Nominations must close AFTER they open.';
        }
        if ($voteOpen && $voteClose && $voteClose <= $voteOpen) {
            return 'Voting must close AFTER it opens.';
        }
        if ($nomClose && $voteOpen && $voteOpen < $nomClose) {
            return 'Voting cannot open before nominations close — the two windows would overlap.';
        }
        if ($voteClose && $results && $results < $voteClose) {
            return 'Results cannot be published before voting closes.';
        }
        if ($nomClose && $results && $results < $nomClose) {
            return 'Results cannot be published before nominations close.';
        }
        return null;
    }

    /**
     * A non-blocking caution appended to the save confirmation, for orderings
     * that are legal but have consequences worth stating.
     *
     * @param array<string,mixed> $b the submitted form body
     */
    public static function windowWarning(array $b): ?string
    {
        $has = static fn (string $k): bool => trim((string) ($b[$k] ?? '')) !== '';

        if ($has('voting_close') && !$has('voting_open')) {
            // This is the configuration that reaches the one branch in
            // CyclePolicy where the stored status column can affect an
            // authorization decision. Setting an open date removes it entirely.
            return ' Note: a voting CLOSE date is set with no OPEN date, so voting'
                . ' is inferred to start when nominations close. Set an explicit'
                . ' voting open date to remove the ambiguity.';
        }
        if ($has('voting_open') && !$has('voting_close')) {
            return ' Warning: voting has an OPEN date but no CLOSE date, so it will'
                . ' stay open indefinitely. Set a close date.';
        }
        if (!$has('nominations_open') && !$has('nominations_close')
            && !$has('voting_open') && !$has('voting_close') && !$has('results_date')) {
            return ' Note: no date windows are set, so this cycle will not advance'
                . ' automatically and its phase falls back to the status above.';
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
