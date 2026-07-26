<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The lifecycle phase of an award cycle.
 *
 * A phase is a COMPUTED value — see {@see CyclePolicy::phaseFor()} — derived
 * from the cycle's date windows and the current time. The stored
 * `gates_award_cycles.status` column is a materialised cache for querying and
 * reporting, never the authority for "may this action happen".
 *
 * SHORTLISTING is the phase the old implementation lacked. It is the gap
 * between nominations closing and voting opening, and its absence is what let
 * the date-driven advancer walk a cycle into 'judging' early and then refuse
 * to "regress" to 'voting' when the voting window actually opened — so voting
 * never opened at all. Because it sorts BEFORE Voting, forward-only
 * advancement and "never skip voting" are satisfiable at the same time.
 *
 * The stored ENUM/CHECK on `status` only permits the six legacy values, so
 * Shortlisting materialises as 'judging' via {@see storedValue()} until that
 * column is widened. The computed phase — the one that gates every action and
 * renders every label — carries the distinction regardless.
 */
enum CyclePhase: string
{
    case Upcoming     = 'upcoming';
    case Nominations  = 'nominations';
    case Shortlisting = 'shortlisting';
    case Voting       = 'voting';
    case Judging      = 'judging';
    case Results      = 'results';
    case Archived     = 'archived';

    /** Forward-only ordering. Shortlisting sits between Nominations and Voting. */
    public function ordinal(): int
    {
        return match ($this) {
            self::Upcoming     => 0,
            self::Nominations  => 1,
            self::Shortlisting => 2,
            self::Voting       => 3,
            self::Judging      => 4,
            self::Results      => 5,
            self::Archived     => 6,
        };
    }

    /** The phase one step forward, or null at the end of the lifecycle. */
    public function next(): ?self
    {
        foreach (self::cases() as $c) {
            if ($c->ordinal() === $this->ordinal() + 1) return $c;
        }
        return null;
    }

    /** Public-facing name. The single source for every badge and chip. */
    public function label(): string
    {
        return match ($this) {
            self::Upcoming     => 'Opens soon',
            self::Nominations  => 'Nominations open',
            self::Shortlisting => 'Shortlisting',
            self::Voting       => 'Voting open',
            self::Judging      => 'With the jury',
            self::Results      => 'Results published',
            self::Archived     => 'Archived',
        };
    }

    /** Only ONE phase accepts votes. */
    public function isVotingOpen(): bool
    {
        return $this === self::Voting;
    }

    /** Only ONE phase accepts nominations. */
    public function isNominationsOpen(): bool
    {
        return $this === self::Nominations;
    }

    /** True once the cycle has finished competing (results/archived). */
    public function isFinished(): bool
    {
        return $this->ordinal() >= self::Results->ordinal();
    }

    /**
     * The value written to `gates_award_cycles.status`. Shortlisting collapses
     * to 'judging' because the column's ENUM/CHECK predates this phase; every
     * other phase round-trips unchanged.
     */
    public function storedValue(): string
    {
        return $this === self::Shortlisting ? self::Judging->value : $this->value;
    }

    /**
     * Read a stored status column. Unknown/NULL values degrade to Upcoming
     * rather than throwing — a legacy or hand-edited row must never 500 a page.
     */
    public static function fromStored(?string $stored): self
    {
        return self::tryFrom(strtolower(trim((string) $stored))) ?? self::Upcoming;
    }
}
