<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * A write was refused because the cycle is not in the right phase.
 *
 * Carries a machine CODE alongside the human message so the JSON API, the form
 * re-render and the paid-checkout bail can all speak one vocabulary instead of
 * each inventing its own string. Previously each of the five write paths built
 * its own refusal — and the paid path emitted eight query-string reason codes
 * that no template ever rendered.
 */
final class PhaseError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly CyclePhase $phase,
    ) {
        parent::__construct($message);
    }

    /** Vote refused: the voting window has not started yet. */
    public static function votingNotOpenYet(CyclePhase $phase, ?string $opensAt = null): self
    {
        $when = $opensAt ? ' Voting opens ' . date('j M Y', strtotime($opensAt)) . '.' : '';
        return new self('VOTING_NOT_OPEN_YET', 'Voting has not opened for this category yet.' . $when, $phase);
    }

    /** Vote refused: the voting window has ended. */
    public static function votingClosed(CyclePhase $phase, ?string $closedAt = null): self
    {
        $when = $closedAt ? ' It closed ' . date('j M Y', strtotime($closedAt)) . '.' : '';
        return new self('VOTING_CLOSED', 'Voting is closed for this category.' . $when, $phase);
    }

    /** Nomination refused: outside the nominations window. */
    public static function nominationsClosed(CyclePhase $phase, ?string $opensAt = null): self
    {
        $when = $opensAt && strtotime($opensAt) > time()
            ? ' Nominations open ' . date('j M Y', strtotime($opensAt)) . '.'
            : '';
        return new self('NOMINATIONS_CLOSED', 'Nominations are not open for this programme right now.' . $when, $phase);
    }

    /** Either action refused: the cycle has been archived. */
    public static function archived(): self
    {
        return new self('CYCLE_ARCHIVED', 'This cycle is closed.', CyclePhase::Archived);
    }

    /** The category/programme has no resolvable cycle at all. */
    public static function noCycle(string $what = 'category'): self
    {
        return new self('NO_CYCLE', 'No award cycle is running for this ' . $what . ' right now.', CyclePhase::Upcoming);
    }
}
