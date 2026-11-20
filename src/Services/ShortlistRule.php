<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The policy half of a shortlist: how many advance, and what happens at the cut line.
 *
 * ── WHY THIS IS AN OBJECT AND NOT FOUR COLUMNS READ IN PLACE ─────────────────
 *
 * Because the interesting part is not the number, it is the BOUNDARY. "Top 10" is a
 * complete-sounding instruction that does not answer the only question that matters when
 * three nominees are level on 47 votes in tenth place. `ORDER BY vote_count DESC LIMIT 10`
 * answers it by picking one of the three arbitrarily — arbitrarily as in "whichever the
 * storage engine happens to return", which can differ between two runs of the same query
 * after an index rebuild, and differs between MySQL and SQLite today.
 *
 * That is not a tie-break, it is a coin flip with no record of having been flipped, and it
 * is the kind of thing that ends up in front of somebody's lawyer. So the boundary is an
 * explicit choice an organiser makes and this object carries it.
 *
 * ── THE FOUR DIALS ───────────────────────────────────────────────────────────
 *
 *   mode + threshold  Where the line is drawn. `top_n` takes a count, `top_pct` a
 *                     percentage of the field — which is what a cycle with a 4-nominee
 *                     category and a 90-nominee category actually needs — and `min_votes`
 *                     draws no line at all and admits everyone who cleared a bar.
 *
 *   tieMode           Everyone level with the cut: `include` (the list may run long) or
 *                     `exclude` (it may fall short). Include is the default because
 *                     shortlisting twelve when you said ten is explicable, and dropping
 *                     one of three equal people is not.
 *
 *   minVotes          A floor that applies UNDERNEATH the line. "Top 10" in a category
 *                     with six entrants and no votes cast would otherwise shortlist all
 *                     six, and publish a document announcing that nobody chose them.
 *
 *   organicOnly       Count organic votes and not purchased bonus votes. Whether money
 *                     may move a nominee onto a shortlist is a decision about the
 *                     integrity of the award, so it is exposed rather than assumed.
 */
final class ShortlistRule
{
    public const MODES = [
        'top_n'     => 'Top N by votes',
        'top_pct'   => 'Top percentage of the field',
        'min_votes' => 'Everyone above a vote threshold',
    ];

    public const TIES = [
        'include' => 'Include everyone level with the cut (the list may run long)',
        'exclude' => 'Exclude everyone level with the cut (the list may fall short)',
    ];

    /** Defaults for a cycle that has never had a rule set. */
    public const DEFAULT_MODE      = 'top_n';
    public const DEFAULT_THRESHOLD = 10;
    /**
     * ZERO, and that is a correction rather than a preference.
     *
     * It was 1, which reads as a sensible guard — "do not shortlist somebody nobody voted
     * for" — and made the whole feature unusable in its most common first state. Before any
     * votes exist every nominee has 0, so a floor of 1 excluded EVERYBODY, `in` was 0, and
     * the publish button never rendered at all. An organiser setting up a cycle saw a
     * category full of nominees, a preview saying nought, and no button to press.
     *
     * The guard is still available and still explained on the form; it just is not on by
     * default. {@see warnings()} now raises the zero-vote case only when the preview
     * ACTUALLY contains one, which is the honest time to mention it.
     */
    public const DEFAULT_MIN_VOTES = 0;

    public function __construct(
        public readonly string $mode = self::DEFAULT_MODE,
        public readonly int $threshold = self::DEFAULT_THRESHOLD,
        public readonly int $minVotes = self::DEFAULT_MIN_VOTES,
        public readonly string $tieMode = 'include',
        public readonly bool $organicOnly = false,
    ) {
    }

    /**
     * Build from anything — a DB row, a form POST, a stored JSON blob — with every value
     * clamped rather than rejected.
     *
     * Clamped, not validated-and-refused, because this is also the READ path: a rule row
     * written by an older version of the form, or hand-edited in phpMyAdmin, must still
     * produce a usable shortlist rather than an exception on a page an organiser is trying
     * to look at. The form does its own validation before saving, so a person who types
     * nonsense is told; a row that already contains nonsense is coerced into the nearest
     * sane thing and the screen shows what it became.
     *
     * @param array<string,mixed>|object|null $src
     */
    public static function from(array|object|null $src): self
    {
        $a = is_object($src) ? (array) $src : ($src ?? []);

        $mode = strtolower(trim((string) ($a['mode'] ?? self::DEFAULT_MODE)));
        if (!isset(self::MODES[$mode])) $mode = self::DEFAULT_MODE;

        $tie = strtolower(trim((string) ($a['tie_mode'] ?? $a['tieMode'] ?? 'include')));
        if (!isset(self::TIES[$tie])) $tie = 'include';

        $threshold = (int) ($a['threshold'] ?? self::DEFAULT_THRESHOLD);
        // A percentage above 100 is not a rule, and a top_n of 0 shortlists nobody while
        // looking like it was configured. The lower bound is 1 in every mode except
        // min_votes, where 0 legitimately means "no floor of its own".
        $threshold = $mode === 'top_pct'
            ? max(1, min(100, $threshold))
            : ($mode === 'min_votes' ? max(0, min(1_000_000, $threshold))
                                     : max(1, min(10_000, $threshold)));

        return new self(
            $mode,
            $threshold,
            max(0, min(1_000_000, (int) ($a['min_votes'] ?? $a['minVotes'] ?? self::DEFAULT_MIN_VOTES))),
            $tie,
            (bool) (int) ($a['organic_only'] ?? $a['organicOnly'] ?? 0),
        );
    }

    /** The column the cut is computed against. */
    public function column(): string
    {
        return $this->organicOnly ? 'organic_vote_count' : 'vote_count';
    }

    /**
     * How many advance from a field of `$total`, or NULL when the rule draws no line by
     * position at all.
     *
     * `ceil` rather than `round` for the percentage: "top 20%" of 11 nominees is 2.2, and
     * shortlisting 2 would silently make the rule stricter than it reads. Rounding up is
     * the reading that matches the words.
     */
    public function take(int $total): ?int
    {
        if ($this->mode === 'min_votes') return null;
        if ($this->mode === 'top_pct')   return max(1, (int) ceil($total * $this->threshold / 100));

        return max(1, $this->threshold);
    }

    /** The floor a nominee must clear regardless of position. */
    public function floor(): int
    {
        return $this->mode === 'min_votes'
            ? max($this->threshold, $this->minVotes)
            : $this->minVotes;
    }

    /**
     * The rule in one sentence, for the screen and for the PDF's footer.
     *
     * The PDF prints this. A shortlist that does not say how it was drawn asks the reader
     * to take it on trust, and this platform's whole integrity story is that it does not.
     */
    public function describe(): string
    {
        $s = match ($this->mode) {
            'top_pct'   => "Top {$this->threshold}% of the field by votes",
            'min_votes' => "Every nominee with at least " . number_format($this->threshold) . ' vote'
                           . ($this->threshold === 1 ? '' : 's'),
            default     => "Top {$this->threshold} by votes",
        };

        if ($this->mode !== 'min_votes' && $this->minVotes > 0) {
            $s .= ', minimum ' . number_format($this->minVotes) . ' vote' . ($this->minVotes === 1 ? '' : 's');
        }
        if ($this->mode !== 'min_votes') {
            $s .= $this->tieMode === 'include' ? ', ties included' : ', ties excluded';
        }
        $s .= $this->organicOnly ? '. Organic votes only.' : '.';

        return $s;
    }

    /** @return array<string,int|string> the storage shape, for both the rule row and the snapshot */
    public function toArray(): array
    {
        return [
            'mode'         => $this->mode,
            'threshold'    => $this->threshold,
            'min_votes'    => $this->minVotes,
            'tie_mode'     => $this->tieMode,
            'organic_only' => (int) $this->organicOnly,
        ];
    }

    /**
     * What is wrong with this rule as a matter of policy, for the form to show BEFORE it is
     * saved. Empty means nothing is wrong.
     *
     * These are not clamps — every one of them is a rule the engine will execute perfectly
     * and that will produce a result the organiser did not intend.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $w = [];

        if ($this->tieMode === 'exclude') {
            $w[] = 'With ties excluded, a category where everyone is level produces an empty '
                 . 'shortlist. Preview each category before publishing.';
        }

        if ($this->mode === 'top_n' && $this->threshold > 50) {
            $w[] = 'A shortlist of more than fifty is not a shortlist; the jury will read all of them.';
        }
        if ($this->mode === 'top_pct' && $this->threshold >= 75) {
            $w[] = "Top {$this->threshold}% advances most of the field.";
        }

        return $w;
    }
}
