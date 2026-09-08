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
     * The sealed standing for a cycle, or null when none was ever recorded.
     *
     * @return array{at:string, rows:array<int,array<string,mixed>>}|null
     */
    public static function forCycle(int $cycleId): ?array
    {
        if ($cycleId < 1) return null;
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

        if ($rows->isEmpty()) return null;

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

        return ['at' => $at, 'rows' => $out];
    }

    /**
     * Lay a sealed standing over a drawn category, so the page publishes the announcement.
     *
     * ── WHY THE ORDER IS RE-SORTED AND NOT TAKEN FROM `standing_rank` ────────
     *
     * Because the rank is sealed per nominee and this has to produce a LIST, and a list
     * built by trusting a stored index breaks the moment one is missing — a nominee added
     * to the category after the release, a row that failed to write. Sorting the sealed
     * figures through {@see ResultRelease::order()} — the same comparator the award was
     * decided with, and the only one on the platform — puts them in the announced order
     * because they are the announced numbers. `standing_rank` is carried for display and
     * as the check that the two agree.
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
        usort($running, ResultRelease::order(...));

        $rank = [];
        foreach ($running as $i => $r) $rank[$r['nominee_id']] = $i + 1;
        foreach ($rows as $i => $r) $rows[$i]['rank'] = $rank[$r['nominee_id']] ?? null;

        usort($rows, static function (array $a, array $b): int {
            if ($a['in_running'] !== $b['in_running']) return $a['in_running'] ? -1 : 1;
            if ($a['in_running']) return $a['rank'] <=> $b['rank'];
            return [$b['cpi'], $b['votes']] <=> [$a['cpi'], $a['votes']];
        });

        $winner   = $running[0] ?? null;
        $runnerUp = $running[1] ?? null;

        $firstMax = 0;
        foreach ($seal as $s) { $firstMax = (int) ($s['cohort_max'] ?? 0); break; }

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
            // ── WHAT THE PAGE HAS TO BE ABLE TO SAY ─────────────────────────
            //
            // `sealed_at` is the announcement this page is showing. Its absence is the
            // other half: a released cycle with no seal is published from a LIVE
            // computation under today's rules, and a reader must be told that rather than
            // left to assume the figures are the ones that were announced.
            'sealed_at'  => (string) $sealed['at'],
            'blocked'    => $running === []
                ? 'The standing sealed at the announcement names nobody in the running.'
                : null,
        ]);
    }
}
