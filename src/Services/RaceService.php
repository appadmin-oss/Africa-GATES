<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Turn a category's nominee list into a RACE.
 *
 * ── WHY A SEPARATE THING FROM StandingsService ───────────────────────────────
 *
 * {@see StandingsService} answers "where does THIS nominee stand", and does it by
 * reading the whole category to work out one row's rank. Asking it once per nominee to
 * render a category of forty is forty scans of the same data — so this does the same
 * arithmetic once, in a single pass over a list the caller already has in memory. No
 * queries at all.
 *
 * The two must not disagree, so the rules are copied deliberately and identically:
 * rank counts how many nominees have strictly MORE votes (ties share a rank), and the
 * gap is to the nearest HIGHER total rather than to the row above — with two nominees
 * tied on 40 behind a leader on 100, the row above has the same total and a gap of
 * zero would tell a jointly-second nominee they are level with the position they
 * already hold.
 *
 * ── AND THE SAME HONESTY RULES ───────────────────────────────────────────────
 *
 * This codebase's standing figures are governed by one principle: never print a number
 * that flatters. So `gap` is null for the leader rather than 0, and `pct` is share of
 * the LEADER's total rather than share of all votes cast — the second reads as "12% of
 * the vote" which is a different and more discouraging claim than "12% of the way to
 * first". A field of one has no race in it and gets no framing at all.
 */
final class RaceService
{
    /**
     * Annotate a category's nominees with their position in the race.
     *
     * Each nominee gains: `rank`, `field`, `gap` (votes to the nearest higher total,
     * null when leading), `next_rank`, `leader_votes`, `pct` (share of the leader,
     * floored at 3 so a nominee on very few votes still sees a sliver rather than an
     * empty track that reads as a rendering fault), and `is_leader`.
     *
     * @param list<array<string,mixed>> $nominees any order; sorted descending on return
     * @return list<array<string,mixed>>
     */
    public static function annotate(array $nominees): array
    {
        $field = count($nominees);
        if ($field === 0) return [];

        usort($nominees, static fn (array $a, array $b): int => (int) $b['vote_count'] <=> (int) $a['vote_count']);

        $leader = (int) ($nominees[0]['vote_count'] ?? 0);

        foreach ($nominees as $i => &$n) {
            $votes = (int) ($n['vote_count'] ?? 0);

            // Rank by strictly-greater totals, so equal totals share a position.
            $rank = 1;
            foreach ($nominees as $other) {
                if ((int) $other['vote_count'] > $votes) $rank++;
            }

            // Nearest HIGHER total, skipping ties — see the class note.
            $gap = null; $nextRank = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ((int) $nominees[$j]['vote_count'] > $votes) {
                    $gap      = (int) $nominees[$j]['vote_count'] - $votes;
                    $nextRank = $rank - 1;
                    break;
                }
            }

            $n['rank']         = $rank;
            $n['field']        = $field;
            $n['gap']          = $gap;
            $n['next_rank']    = $nextRank;
            $n['leader_votes'] = $leader;
            $n['is_leader']    = $gap === null;
            // Share of the LEADER, not of all votes cast. A field of one is not a race,
            // so it gets a full bar rather than a meaningless one.
            $n['pct'] = $leader > 0 ? max(3, (int) round($votes * 100 / $leader)) : ($field === 1 ? 100 : 3);
        }
        unset($n);

        return $nominees;
    }

    /**
     * The one line that makes a category worth watching: how close the top of it is.
     *
     * Returns null when there is no contest to describe — fewer than two nominees, or
     * a leader on zero votes, where "0 ahead" is noise rather than drama.
     *
     * @param list<array<string,mixed>> $annotated output of {@see annotate()}
     * @return array{lead:int, leader:string, chaser:string}|null
     */
    public static function headline(array $annotated): ?array
    {
        if (count($annotated) < 2) return null;

        $top = (int) ($annotated[0]['vote_count'] ?? 0);
        if ($top <= 0) return null;

        // The nearest total BELOW the leader — not simply row two, which may be tied
        // for first and would report a lead of zero over itself.
        foreach ($annotated as $n) {
            $v = (int) $n['vote_count'];
            if ($v < $top) {
                return [
                    'lead'   => $top - $v,
                    'leader' => (string) ($annotated[0]['name'] ?? ''),
                    'chaser' => (string) ($n['name'] ?? ''),
                ];
            }
        }
        // Everyone is tied at the top.
        return ['lead' => 0, 'leader' => (string) ($annotated[0]['name'] ?? ''), 'chaser' => ''];
    }
}
