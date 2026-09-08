<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT A NOMINEE WAS ACTUALLY AWARDED, AS OPPOSED TO WHAT TODAY'S RULES WOULD GIVE THEM.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see PublicResults::category()} re-ran the whole calculation on every page view. So a
 * published result page did not show what was announced — it showed what the CURRENT
 * rules produce — and the winner it named was the recomputed top rather than the person
 * the award was given to.
 *
 * Harmless while the rules never change. They changed three times in one week. Measured on
 * a real released nominee, 1,955 votes and a 7.9 panel:
 *
 *     announced under the rules of the day :  693
 *     what the page computed afterwards    :  885
 *
 * Nobody edited anything, and the hash chain in `gates_vote_snapshots` was intact
 * throughout. He simply opened his own result page and found a different number.
 *
 * Meanwhile the help centre publishes: "the result is written down and sealed… there is no
 * quiet edit available. There is only an edit that announces itself." That promise was
 * true of the archive and false of the page, because nothing published ever read the
 * archive: its only readers were a console command and the maintenance sweep, on a host
 * with no shell.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES, AND THE ONE THING IT REFUSES TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see forCycle()} reads the sealed standing — the capture marked `release`, written at
 * promotion by {@see SnapshotService::captureRelease()}. {@see apply()} lays it over a
 * drawn result so the page publishes the sealed figures and the sealed order.
 *
 * It will NOT guess. A cycle released before sealing existed has no `release` capture, and
 * there is no honest way to recover the announced standing from routine captures — they
 * were taken on a schedule, under whatever rules held at the time, and picking the one
 * nearest the announcement would be a heuristic presented as a record. Where there is no
 * seal this returns null, the page falls back to a live computation, and it SAYS SO. A
 * figure labelled as announced when nobody knows whether it was is worse than a figure
 * labelled as recomputed.
 */
final class ReleasedStanding
{
    /**
     * Sealed standings already read in this process, keyed by cycle. Null is a cached
     * answer too — "this cycle has no seal" is the commonest one on a page listing
     * results from before sealing existed.
     *
     * @var array<int, array{at:string, rows:array<int,array<string,mixed>>}|null>
     */
    private static array $memo = [];

    /**
     * The sealed standing for a cycle, or null when none was ever recorded.
     *
     * ── WHY THIS IS MEMOISED WHERE THE EDITION SCALE IS NOT ─────────────────
     *
     * The seal is per CYCLE and this is called per CATEGORY, from
     * {@see PublicResults::category()} — so the results index, which draws up to sixty
     * awards in a row, read the whole of each cycle's archive once per award on it. That
     * is the same shape as the fault the edition scale had one commit earlier, and the
     * same loop: the scorer was threaded through it precisely so a page listing sixty
     * results reads each cycle once, and this went straight back to once per award.
     *
     * A memo is safe here in a way it would not be for the scale, and for one reason: a
     * seal is written once per cycle and never rewritten — {@see SnapshotService::captureRelease()}
     * refuses a second. Immutable data can be cached for the life of a request without a
     * staleness question. The one process that can invalidate it is the one that writes
     * it, and that calls {@see forget()}.
     *
     * @return array{at:string, rows:array<int,array<string,mixed>>}|null
     */
    public static function forCycle(int $cycleId): ?array
    {
        if ($cycleId < 1) return null;
        if (array_key_exists($cycleId, self::$memo)) return self::$memo[$cycleId];
        if (!OptionalColumn::on('gates_vote_snapshots', 'capture_kind')) return null;

        try {
            $rows = DB::table('gates_vote_snapshots')
                ->where('cycle_id', $cycleId)
                ->where('capture_kind', SnapshotService::KIND_RELEASE)
                ->orderBy('id')->get();
        } catch (\Throwable) {
            // No archive on this deployment. A missing seal is a fallback, never an error
            // on a public page.
            return null;
        }

        if ($rows->isEmpty()) return self::$memo[$cycleId] = null;

        $out = [];
        $at  = '';
        foreach ($rows as $r) {
            $at = $at !== '' ? $at : (string) ($r->snapshot_at ?? '');
            $out[(int) $r->nominee_id] = [
                'cpi'              => (int) ($r->cpi_score ?? 0),
                'votes'            => (int) ($r->vote_count ?? 0),
                'judge_score'      => $r->judge_score === null ? null : (float) $r->judge_score,
                'community_points' => $r->community_points === null ? null : (int) $r->community_points,
                'judge_points'     => $r->judge_points === null ? null : (int) $r->judge_points,
                'cohort_max'       => $r->cohort_max === null ? null : (int) $r->cohort_max,
                'unique_voters'    => $r->unique_voters === null ? null : (int) $r->unique_voters,
                'cohort_max_unique'=> $r->cohort_max_unique === null ? null : (int) $r->cohort_max_unique,
                'rank'             => $r->standing_rank === null ? null : (int) $r->standing_rank,
                'in_running'       => $r->in_running === null ? null : ((int) $r->in_running === 1),
            ];
        }

        return self::$memo[$cycleId] = ['at' => $at, 'rows' => $out];
    }

    /**
     * Drop the cached seal for a cycle — for the one process that can change the answer.
     *
     * Called by {@see SnapshotService::captureRelease()} after it writes, so a promotion
     * run that has already looked at a cycle (or a test that reads before sealing) does
     * not go on serving "no seal" to everything after it.
     */
    public static function forget(?int $cycleId = null): void
    {
        if ($cycleId === null) { self::$memo = []; return; }
        unset(self::$memo[$cycleId]);
    }

    /**
     * Lay a sealed standing over a drawn category, so the page publishes the announcement.
     *
     * ── THE ORDER COMES FROM `standing_rank`, AND FALLS BACK TO THE COMPARATOR ──
     *
     * This used to sort the sealed figures through {@see ResultRelease::order()} and throw
     * the sealed rank away, on the reasoning that the comparator "is the same one the
     * award was decided with". It is the same one only until somebody changes it — and a
     * tiebreak is a rule like any other. `order()` breaks a tie on the tally and then on
     * the nominee id; move it to unique voters, or to the panel mark, and every released
     * dead heat on the platform silently reorders, which is the exact fault this class
     * exists to prevent, one level down from the arithmetic it fixed. The seal held the
     * announced order the whole time, in a column nothing read.
     *
     * So where the seal ranks every nominee still in the running, and ranks them
     * distinctly, THAT is the published order — it is what was announced. The comparator
     * is the fallback for the case the old reasoning was actually about: a rank missing
     * because a row failed to write. Then the page says the order was recomputed rather
     * than presenting it as the announcement, because a reader cannot tell the two apart
     * by looking.
     *
     * Ties are left exactly as sealed. Two nominees who were announced level stay level;
     * re-deciding a dead heat under today's tiebreak is the thing this refuses to do.
     *
     * ── AND A NOMINEE THE SEAL DOES NOT MENTION ─────────────────────────────
     *
     * Is out. They were not in the standing that was announced, so they are not in the
     * standing that is published — whatever has happened to their votes or their panel
     * since. Marked with a reason rather than dropped: an exclusion nobody can see is the
     * part of a result that is hardest to defend later.
     *
     * @param array<string,mixed> $drawn  a {@see ResultRelease::category()} result
     * @param array{at:string, rows:array<int,array<string,mixed>>} $sealed
     * @return array<string,mixed>
     */
    public const OUT_NOT_SEALED = 'not in the standing that was announced';

    public static function apply(array $drawn, array $sealed): array
    {
        $seal = $sealed['rows'];
        $rows = [];

        foreach ($drawn['rows'] as $r) {
            $id = (int) $r['nominee_id'];
            $s  = $seal[$id] ?? null;

            if ($s === null) {
                $r['in_running'] = false;
                $r['rank']       = null;
                $r['out_reason'] = self::OUT_NOT_SEALED;
                $rows[] = $r;
                continue;
            }

            $r['cpi']         = $s['cpi'];
            $r['votes']       = $s['votes'];
            $r['vote_count']  = $s['votes'];
            $r['judge_score'] = $s['judge_score'];
            // Null means the working was not recorded when this standing was sealed —
            // captures before the columns existed. The published figure stays the sealed
            // one; only the breakdown is missing, and the page says which.
            $r['community_points'] = $s['community_points'] ?? $r['community_points'];
            $r['judge_points']     = $s['judge_points'] ?? $r['judge_points'];
            $r['unique_voters']    = $s['unique_voters'] ?? $r['unique_voters'];
            $r['working_sealed']   = $s['community_points'] !== null;

            $max = $s['cohort_max'] ?? 0;
            $r['community_share'] = $max > 0
                ? (int) round(min(1.0, $s['votes'] / $max) * 100)
                : $r['community_share'];

            // in_running is the sealed verdict where there is one. Older seals predate the
            // column, and there the rank is the record: ranked means it was in the running.
            $r['in_running'] = $s['in_running'] ?? ($s['rank'] !== null);
            $r['rank']       = $s['rank'];
            if (!$r['in_running'] && ($r['out_reason'] ?? null) === null) {
                $r['out_reason'] = self::OUT_NOT_SEALED;
            }

            $rows[] = $r;
        }

        $running = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['in_running']));

        // Complete means every nominee still in the running carries a rank, and no two
        // carry the same one. Anything less and the sealed ranking cannot order the list
        // on its own — see the docblock for why that is the only case the comparator is
        // allowed to decide.
        $sealedRanks  = array_map(static fn (array $r): ?int => $r['rank'], $running);
        $sealedOrders = $running !== []
            && !in_array(null, $sealedRanks, true)
            && count(array_unique($sealedRanks)) === count($sealedRanks);

        if ($sealedOrders) {
            usort($running, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        } else {
            usort($running, ResultRelease::order(...));
            $rank = [];
            foreach ($running as $i => $r) $rank[$r['nominee_id']] = $i + 1;
            foreach ($rows as $i => $r) $rows[$i]['rank'] = $rank[$r['nominee_id']] ?? null;
            foreach ($running as $i => $r) $running[$i]['rank'] = $rank[$r['nominee_id']] ?? null;
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['in_running'] !== $b['in_running']) return $a['in_running'] ? -1 : 1;
            if ($a['in_running']) return $a['rank'] <=> $b['rank'];
            return [$b['cpi'], $b['votes']] <=> [$a['cpi'], $a['votes']];
        });

        $winner   = $running[0] ?? null;
        $runnerUp = $running[1] ?? null;

        // The denominators, off the seal. Both are edition-wide figures, identical on every
        // row, so the first row that recorded one is the one — and a null means the seal
        // predates the columns, where the live figure is all there is.
        $firstMax = 0;
        $firstMaxUnique = 0;
        foreach ($seal as $s) {
            if ($firstMax === 0)       $firstMax       = (int) ($s['cohort_max'] ?? 0);
            if ($firstMaxUnique === 0) $firstMaxUnique = (int) ($s['cohort_max_unique'] ?? 0);
            if ($firstMax > 0 && $firstMaxUnique > 0) break;
        }

        return array_merge($drawn, [
            'rows'      => $rows,
            'winner'    => $winner,
            'runner_up' => $runnerUp,
            'margin'    => ($winner && $runnerUp) ? $winner['cpi'] - $runnerUp['cpi'] : null,
            'dead_heat' => (bool) ($winner && $runnerUp
                                   && $winner['cpi'] === $runnerUp['cpi']
                                   && $winner['votes'] === $runnerUp['votes']),
            'tie_broken_by_votes' => (bool) ($winner && $runnerUp
                                   && $winner['cpi'] === $runnerUp['cpi']
                                   && $winner['votes'] !== $runnerUp['votes']),
            'cohort_max' => $firstMax > 0 ? $firstMax : ($drawn['cohort_max'] ?? 0),
            // ── THE REACH DENOMINATOR IS SEALED TOO ─────────────────────────
            //
            // Read out of the seal and then never applied, this was a column written at
            // every announcement and used by nothing. It is what the public page gates
            // the backer count on, so a sealed page was deciding whether to print a
            // sealed number of supporters by asking today's rows how many the leader has
            // — and where those rows have since gone (an import, a purge) the whole
            // count disappears from an announcement that counted it.
            'cohort_max_unique' => $firstMaxUnique > 0
                ? $firstMaxUnique
                : ($drawn['cohort_max_unique'] ?? 0),
            // ── WHAT THE PAGE HAS TO BE ABLE TO SAY ─────────────────────────
            //
            // `sealed_at` is the announcement this page is showing. Its absence is the
            // other half: a released cycle with no seal is published from a LIVE
            // computation under today's rules, and a reader must be told that rather than
            // left to assume the figures are the ones that were announced.
            'sealed_at'  => (string) $sealed['at'],
            // ── AND WHETHER THE ORDER IS THE SEALED ONE ─────────────────────
            //
            // True when the seal did not rank everybody still in the running, so the list
            // had to be ordered by today's comparator over the sealed figures. The
            // figures are still the announced ones; their ORDER is a reconstruction, and
            // the page says which — {@see pages/results/show.twig}. Without this the two
            // cases look identical to a reader, which is the whole complaint this class
            // was written about.
            'rank_recomputed' => $running !== [] && !$sealedOrders,
            'blocked'    => $running === []
                ? 'The standing sealed at the announcement names nobody in the running.'
                : null,
        ]);
    }
}
