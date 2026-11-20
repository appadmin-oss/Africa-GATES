<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Where a nominee actually stands, and what would change it.
 *
 * The ballot showed a vote total and nothing else. A total on its own answers no
 * question a supporter has: whether their vote would matter, how close the race is,
 * whether the last push worked. So the page had no reason to be visited twice and no
 * reason to be shared — which on a platform whose whole mechanic is rallying support
 * is the difference between a ballot and a scoreboard.
 *
 * EVERY NUMBER HERE IS REAL. This codebase's Pulse page carries the note "no
 * fabricated likes, views or member counts", and the same rule holds harder on a
 * ballot: an invented "trending" badge or a rounded-up momentum figure is a lie about
 * a competition people are paying to enter. Where a figure cannot be computed —
 * momentum on a category with no timestamped votes, a gap when the nominee is first —
 * it comes back null and the template omits the whole element rather than showing a
 * zero that reads as a measurement.
 *
 * WHAT IS DELIBERATELY NOT EXPOSED. Any per-voter detail. Rank, totals, the gap to
 * the neighbouring positions and a 24-hour count are all aggregates; nothing here can
 * be reduced to who voted for whom. The gap is expressed as a NUMBER OF VOTES rather
 * than by naming the nominee ahead, because "you are 12 behind" is the actionable
 * fact and "you are 12 behind Ada" invites a campaign against a person.
 *
 * The CPI, not the raw tally, decides awards — 55% independent jury. That is stated
 * on every surface that shows a rank, because a nominee who reads a leaderboard
 * position as the result is being misled by omission.
 */
final class StandingsService
{
    /** Cache window. Short: a supporter refreshing after a push must see movement. */
    public const TTL = 45;

    /**
     * Standing for one nominee within its category.
     *
     * @return array{
     *   rank:int, field:int, votes:int, top_pct:?int, top_notable:bool,
     *   gap_ahead:?int, gap_behind:?int, next_rank:?int, shared_top:bool,
     *   momentum_24h:?int, momentum_available:bool,
     *   leader_votes:int, progress_pct:int, is_leader:bool, is_top_three:bool
     * }
     */
    public function forNominee(int $nomineeId, int $categoryId): array
    {
        $cache = new CacheService();
        $key   = "standings:n:{$nomineeId}";
        try {
            return $cache->remember($key, self::TTL, fn (): array => $this->compute($nomineeId, $categoryId), ['leaderboard']);
        } catch (\Throwable) {
            return $this->compute($nomineeId, $categoryId);
        }
    }

    private function compute(int $nomineeId, int $categoryId): array
    {
        $blank = [
            'rank' => 0, 'field' => 0, 'votes' => 0, 'top_pct' => null, 'top_notable' => false,
            'gap_ahead' => null, 'gap_behind' => null, 'next_rank' => null, 'shared_top' => false,
            'momentum_24h' => null, 'momentum_available' => false,
            'leader_votes' => 0, 'progress_pct' => 0, 'is_leader' => false, 'is_top_three' => false,
        ];

        try {
            // The whole category, ordered. Ordering in SQL and ranking in PHP keeps ties
            // handled one way in one place — MySQL 8 window functions and SQLite's would
            // both work, but the two schemas have to agree and this is the cheaper
            // guarantee.
            $rows = DB::table('gates_nominees')
                ->where('category_id', $categoryId)
                ->whereIn('status', ['approved', 'winner', 'runner_up'])
                ->whereNull('merged_into')
                ->orderByDesc('vote_count')->orderBy('id')
                ->get(['id', 'vote_count'])->all();
        } catch (\Throwable) {
            return $blank;
        }

        $field = count($rows);
        if ($field === 0) return $blank;

        $index = null;
        foreach ($rows as $i => $r) {
            if ((int) $r->id === $nomineeId) { $index = $i; break; }
        }
        if ($index === null) return $blank;

        $votes  = (int) $rows[$index]->vote_count;
        $leader = (int) $rows[0]->vote_count;

        // COMPETITION RANK: everyone on the same total shares a position. Using the
        // array index would tell two nominees on 40 votes that one is 3rd and the other
        // 4th, which is not true and is exactly the kind of detail a nominee screenshots
        // and disputes.
        $rank = 1;
        foreach ($rows as $r) {
            if ((int) $r->vote_count > $votes) $rank++;
        }

        // Gap to the nearest HIGHER total, and the position that total occupies.
        //
        // Deliberately not "the row above": with two nominees tied on 40 behind a leader
        // on 100, the row above has the same total and the gap to it is zero — which
        // would tell a jointly-second nominee they are level with the position they
        // already hold. The useful facts are "joint 2nd" and "60 from 1st", so the scan
        // skips equal totals.
        $gapAhead = null; $nextRank = null;
        for ($i = $index - 1; $i >= 0; $i--) {
            if ((int) $rows[$i]->vote_count > $votes) {
                $gapAhead = (int) $rows[$i]->vote_count - $votes;
                $nextRank = $rank - 1;
                break;
            }
        }
        // Nothing higher and not alone at the top: tied FOR first. `rank` is already 1
        // for both, so `is_leader` is true and the headline says so.
        $sharedTop = $gapAhead === null && $rank === 1 && $field > 1
            && (int) $rows[1]->vote_count === $votes;

        $gapBehind = null;
        for ($i = $index + 1; $i < $field; $i++) {
            if ((int) $rows[$i]->vote_count < $votes) {
                $gapBehind = $votes - (int) $rows[$i]->vote_count;
                break;
            }
        }

        [$momentum, $momentumAvailable] = $this->momentum($nomineeId);

        return [
            'rank'        => $rank,
            'field'       => $field,
            'votes'       => $votes,
            /**
             * "Top N%", but only when N is worth printing.
             *
             * Two guards, and the second was added after seeing the first render: a
             * nominee at #192 of 379 was shown "Top 51%", which is arithmetic dressed
             * as an achievement — it says "slightly worse than average" in the visual
             * language of a badge, and a cue that reads as praise for a median position
             * devalues every other figure beside it.
             *
             * Computed as ceil(rank / field × 100), which is what "top N%" means. The
             * previous expression subtracted a percentile from 100 and added one, an
             * off-by-one that happened to agree at the value it was first seen at.
             */
            'top_pct'     => $field >= 10 ? (int) ceil(($rank / $field) * 100) : null,
            'top_notable' => $field >= 10 && (int) ceil(($rank / $field) * 100) <= 25,
            'gap_ahead'   => $gapAhead,
            'gap_behind'  => $gapBehind,
            'next_rank'   => $nextRank,
            'shared_top'  => $sharedTop,
            'momentum_24h'       => $momentum,
            'momentum_available' => $momentumAvailable,
            'leader_votes' => $leader,
            // Share of the leader's total, for a progress bar. Capped at 100 and floored
            // at 2 so a nominee on very few votes still sees a sliver rather than an
            // empty track that reads as a rendering fault.
            'progress_pct' => $leader > 0 ? max(2, min(100, (int) round(($votes / $leader) * 100))) : 0,
            'is_leader'    => $rank === 1,
            'is_top_three' => $rank <= 3,
        ];
    }

    /**
     * Votes in the last 24 hours, and whether that is measurable at all.
     *
     * The second return value is the honest part. `gates_votes.voted_at` is a TIMESTAMP
     * and every vote has one, but a category may have none inside the window, and a
     * historic import may have none at all — in which case the answer is "not known",
     * not "zero". Showing 0 for both makes a quiet day look identical to a broken
     * counter, and a nominee will read the first as the second.
     *
     * @return array{0:?int,1:bool}
     */
    private function momentum(int $nomineeId): array
    {
        try {
            $since = Carbon::now()->subDay()->toDateTimeString();
            $any   = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)->limit(1)->count();
            if ($any === 0) return [null, false];      // nothing timestamped to measure
            $n = (int) DB::table('gates_votes')
                ->where('nominee_id', $nomineeId)
                ->where('voted_at', '>=', $since)
                ->count();
            return [$n, true];
        } catch (\Throwable) {
            return [null, false];
        }
    }

    /**
     * One line stating the position, written for a person rather than a dashboard.
     *
     * Built here rather than in the template so the ballot, the flier and any future
     * surface cannot describe the same standing three different ways — which is how a
     * nominee ends up with a share card that contradicts the page it came from.
     */
    public static function headline(array $s): string
    {
        if (($s['field'] ?? 0) < 2) return 'Open for votes';
        $rank = (int) $s['rank'];
        $of   = (int) $s['field'];

        if ($rank === 1) {
            if ($s['shared_top'] ?? false) return 'Level at the top — joint first';
            $lead = $s['gap_behind'];
            return $lead !== null && $lead > 0
                ? "Leading by {$lead} vote" . ($lead === 1 ? '' : 's')
                : 'Leading';
        }
        // There was a `$gap === 0` branch here reading "Level for #N". It was
        // unreachable: a zero gap only ever arose when nothing higher existed, which is
        // rank 1, which returns above. Removed rather than left as apparent coverage.
        $gap = $s['gap_ahead'];
        if ($gap !== null) {
            return "#{$rank} of {$of} — {$gap} vote" . ($gap === 1 ? '' : 's') . " from #{$s['next_rank']}";
        }
        return "#{$rank} of {$of}";
    }

    /**
     * The call to action, matched to the standing.
     *
     * A single "Vote now" ignores that the useful ask differs: a nominee 3 votes off
     * first needs urgency, a leader needs defence, someone mid-field needs a reason to
     * believe the push is worth it. Never invents a number.
     */
    public static function callToAction(array $s): string
    {
        $gap = $s['gap_ahead'] ?? null;
        if (($s['is_leader'] ?? false)) {
            if ($s['shared_top'] ?? false) return 'Level on votes at the top — the next one breaks the tie.';
            $behind = $s['gap_behind'] ?? null;
            return $behind !== null && $behind <= 5 && $behind > 0
                ? 'Only ' . $behind . ' vote' . ($behind === 1 ? '' : 's') . ' separate first and second — hold the lead.'
                : 'Every vote widens the lead.';
        }
        if ($gap !== null && $gap <= 10) return $gap . ' more vote' . ($gap === 1 ? '' : 's') . ' takes the next position.';
        if ($gap !== null)   return 'Closing the gap takes ' . $gap . ' votes. Every one counts.';
        return 'Add your vote.';
    }
}
