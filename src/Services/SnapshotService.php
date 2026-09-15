<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Tamper-evident standings snapshots.
 *
 * Each capture writes one row per nominee (vote_count, judge_score, cpi_score)
 * into gates_vote_snapshots, hash-chained: row.hash = sha256(prev_hash | payload)
 * where payload is the exact integer standing. Altering, inserting, deleting, or
 * reordering any historical row breaks the chain from that point on — so the
 * record of how standings evolved is verifiable after the fact.
 *
 * judge_score is stored for reference but NOT in the hash payload (it's a float);
 * the integer cpi_score already encodes the judge contribution exactly.
 *
 * ── A CHAIN HAS EXACTLY ONE TAIL ─────────────────────────────────────────────
 *
 * Everything below turns on that sentence, because the first version of capture()
 * did not enforce it. It read the tail hash once, outside any transaction, and then
 * appended. Two overlapping runs therefore both read the same link and both built
 * from it, producing two rows with the same `prev_hash` — a fork.
 *
 * That is not a hypothetical race here. {@see \AfricaGates\Support\CronGuard} fails
 * OPEN by design (better to run twice than to silently skip), its `flock` is
 * per-machine so it means nothing across two app servers, and the materialiser's own
 * docblock states plainly that "two schedulers CAN overlap by design". The scheduled
 * capture and an operator running the task by hand is enough.
 *
 * A fork is the worst possible failure for this particular structure. It is not lost
 * data — every row is still there and still honest — but verify() walks ONE line, so
 * from the fork onward it reports the record as altered. The alarm is permanent,
 * indistinguishable from real tampering, and unclearable: the only way to make the
 * chain verify again is to rewrite history, which is the exact act the chain exists
 * to make impossible. So the fix has to be prevention, and it is two layers:
 *
 *   1. capture() runs in a transaction and takes the tail FOR UPDATE, so on MySQL a
 *      second writer waits rather than reading a stale link.
 *   2. UNIQUE(prev_hash) — the structural one, and the one that holds on any driver
 *      and any number of machines. A link can be extended once. A second writer that
 *      slipped through anyway fails its INSERT, the transaction rolls back, and the
 *      run reports an error instead of leaving a corrupted archive behind. Losing one
 *      capture is nothing; a permanently unverifiable chain is everything.
 */
class SnapshotService
{
    public function __construct(private readonly NomineeScoringService $scoring = new NomineeScoringService()) {}

    /** Canonical, exact-integer payload for one snapshot row. */
    private static function payload(int|string $cycleId, int|string $nomineeId, int $votes, int $cpi, string $at): string
    {
        return implode('|', [$cycleId, $nomineeId, $votes, $cpi, $at]);
    }

    /**
     * Capture standings for every active cycle. Returns the number of rows written.
     *
     * All-or-nothing on purpose. A capture that half-succeeds is still a valid chain
     * (each row links to the one before it), but rolling the whole thing back is what
     * lets the UNIQUE(prev_hash) collision above be a clean no-op rather than a
     * partial run that has to be reasoned about later.
     */
    public function capture(): int
    {
        $cycles = DB::table('gates_award_cycles')->whereIn('status', ['voting', 'judging', 'results'])->pluck('id')->all();
        if (!$cycles) return 0;

        $rows = [];
        foreach ($cycles as $cycleId) {
            $catIds = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->pluck('id')->all();
            foreach ($catIds as $catId) {
                foreach ($this->scoring->scoreCategory((int) $catId) as $nomineeId => $s) {
                    $rows[] = [
                        'cycle_id'          => (int) $cycleId,
                        'nominee_id'        => (int) $nomineeId,
                        'vote_count'        => (int) $s['vote_count'],
                        'cpi_score'         => (int) $s['cpi_score'],
                        'judge_score'       => $s['judge_score'],
                        'community_points'  => $s['community_points'] ?? null,
                        'judge_points'      => $s['judge_points'] ?? null,
                        'cohort_max'        => $s['cohort_max'] ?? null,
                        'unique_voters'     => $s['unique_voters'] ?? null,
                        'cohort_max_unique' => $s['cohort_max_unique'] ?? null,
                        // The routine sweep records arithmetic, not an outcome: it runs
                        // while a cycle is still being voted on and judged, and there is
                        // no published standing to record. Only the release capture below
                        // seals a ranking.
                        'standing_rank'     => null,
                        'in_running'        => null,
                    ];
                }
            }
        }

        return $this->append($rows, self::KIND_ROUTINE);
    }

    /** The scheduled sweep. */
    public const KIND_ROUTINE = 'routine';

    /** The standing as announced. */
    public const KIND_RELEASE = 'release';

    /**
     * SEAL THE STANDING AS ANNOUNCED — the capture taken at the moment of promotion.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A RELEASE NEEDS ITS OWN CAPTURE, AND WHY IT SEALS THE DRAWN RESULT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A cycle in `results` keeps being captured by the routine sweep, so the archive holds
     * many standings for a released cycle and every one of them was computed under
     * whatever the rules were that day. Which is the announced one cannot be recovered
     * from timestamps; it has to be marked as it happens.
     *
     * And it seals {@see ResultRelease::forCycle()} rather than the raw scorer, because a
     * published result is not only arithmetic — it is a RANKING, and who was in it. Below
     * the judge quorum, off the published shortlist, no support at all: each of those puts
     * a nominee out of the running, and each can change after a release. A nominee who was
     * below quorum on the day and has since been judged would otherwise be ranked into a
     * standing that never contained them.
     *
     * ── AND IT CAN NEVER STOP A RELEASE ─────────────────────────────────────
     *
     * Idempotent per cycle: a cycle that already has a release capture keeps the first
     * one. Promotion is driven by an unattended sweep whose own docblock says two
     * schedulers can overlap, and the announced standing is the standing at the
     * announcement, not at whichever re-run fired last.
     *
     * @return int rows written; 0 when already sealed, or when the columns do not exist yet
     */
    public function captureRelease(int $cycleId): int
    {
        if ($cycleId < 1) return 0;

        // Without `capture_kind` there is no way to mark a capture as the announced one,
        // so a row written here would be indistinguishable from the routine sweep — and a
        // published page would then read a standing that is not the sealed one while
        // saying that it is. Better to have no seal than a seal that means nothing.
        if (!OptionalColumn::on('gates_vote_snapshots', 'capture_kind')) return 0;

        try {
            if (DB::table('gates_vote_snapshots')
                    ->where('cycle_id', $cycleId)
                    ->where('capture_kind', self::KIND_RELEASE)->exists()) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        $rows = [];
        foreach (ResultRelease::forCycle($cycleId) as $cat) {
            foreach ($cat['rows'] as $r) {
                $rows[] = [
                    'cycle_id'          => $cycleId,
                    'nominee_id'        => (int) $r['nominee_id'],
                    'vote_count'        => (int) $r['votes'],
                    'cpi_score'         => (int) $r['cpi'],
                    'judge_score'       => $r['judge_score'],
                    'community_points'  => (int) $r['community_points'],
                    'judge_points'      => (int) $r['judge_points'],
                    'cohort_max'        => (int) ($cat['cohort_max'] ?? 0),
                    'unique_voters'     => (int) ($r['unique_voters'] ?? 0),
                    'cohort_max_unique' => (int) ($cat['cohort_max_unique'] ?? 0),
                    'standing_rank'     => $r['rank'] === null ? null : (int) $r['rank'],
                    'in_running'        => !empty($r['in_running']) ? 1 : 0,
                ];
            }
        }

        if ($rows === []) return 0;

        $written = $this->append($rows, self::KIND_RELEASE);
        // A seal is immutable, so {@see ReleasedStanding::forCycle()} caches it for the
        // life of the process — including the answer "there is no seal", which this call
        // has just made wrong. The promotion sweep is the one process that can see both
        // sides of that, and it draws the cycle before it seals it.
        ReleasedStanding::forget($cycleId);

        return $written;
    }

    /**
     * THE ONE APPENDER. Both captures come through here, so the chain has a single writer.
     *
     * All-or-nothing on purpose. A capture that half-succeeds is still a valid chain (each
     * row links to the one before it), but rolling the whole thing back is what lets the
     * UNIQUE(prev_hash) collision be a clean no-op rather than a partial run that has to be
     * reasoned about later.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function append(array $rows, string $kind): int
    {
        if ($rows === []) return 0;

        return (int) DB::transaction(function () use ($rows, $kind) {
            // FOR UPDATE on the tail: a concurrent capture blocks here instead of reading a
            // link that is about to stop being the tail. Compiles to nothing on SQLite,
            // which is why the unique index is the real guarantee.
            $prev = (string) (DB::table('gates_vote_snapshots')
                ->orderByDesc('id')->lockForUpdate()->value('hash') ?? '');
            $at      = Carbon::now()->toDateTimeString();
            $written = 0;

            foreach ($rows as $row) {
                // THE PAYLOAD IS UNCHANGED — `cycleId|nomineeId|votes|cpi|at`. Everything
                // added since is stored beside the hash and not inside it, so every link
                // written before those columns existed still verifies. Same choice already
                // made for `judge_score`: the integer cpi encodes it exactly.
                $hash = hash('sha256', $prev . '|' . self::payload(
                    $row['cycle_id'], $row['nominee_id'], $row['vote_count'], $row['cpi_score'], $at));

                DB::table('gates_vote_snapshots')->insert(OptionalColumn::filter(
                    'gates_vote_snapshots',
                    $row + ['snapshot_at' => $at, 'prev_hash' => $prev, 'hash' => $hash,
                            'capture_kind' => $kind],
                    // Additive columns. A deployment whose migration has not run yet still
                    // captures: a chain that stops being appended to because a widening is
                    // pending is worse than one with less detail in its newest rows.
                    ['capture_kind', 'community_points', 'judge_points', 'cohort_max',
                     'unique_voters', 'cohort_max_unique', 'standing_rank', 'in_running']));

                $prev = $hash;
                $written++;
            }

            return $written;
        });
    }

    /**
     * Re-walk the chain and confirm no row was altered, inserted, deleted or reordered.
     *
     * ── WHY IT CHUNKS ────────────────────────────────────────────────────────────
     *
     * This used to `->get()` the whole table. Captures run every six hours and write a
     * row per nominee per active cycle, so the archive grows without bound — and the
     * check would have run out of memory at precisely the point the history was long
     * enough to be worth proving. A verification that stops working as the record
     * lengthens is not a verification.
     *
     * ── WHY `unchained` EXISTS ───────────────────────────────────────────────────
     *
     * `prev_hash` was added by a later migration (2026_06_14). Any installation that
     * captured snapshots before that has leading rows with no hash at all, and the
     * original walk compared them against a computed digest and declared the record
     * TAMPERED — a false accusation, at the top of the archive, that no operator could
     * ever clear because the data to clear it was never written.
     *
     * Those rows are reported for what they are: written before the chain existed,
     * therefore outside it, therefore not evidence of anything. They are counted, not
     * quietly skipped, because "verified 40,000 rows" and "verified 40,000 rows and
     * there are 900 older ones nothing can vouch for" are different claims and the
     * second is the true one. A missing hash AFTER the chain has started is still a
     * break — that is a deletion, not history.
     *
     * @return array{ok:bool, checked:int, broken_at:int|null, unchained:int}
     */
    public function verify(int $chunk = 1000): array
    {
        $prev      = '';
        $checked   = 0;
        $unchained = 0;
        $started   = false;
        $broken    = null;

        DB::table('gates_vote_snapshots')->orderBy('id')->chunk(max(1, $chunk), function ($rows) use (
            &$prev, &$checked, &$unchained, &$started, &$broken
        ) {
            foreach ($rows as $r) {
                $hash = (string) ($r->hash ?? '');
                if (!$started && $hash === '') { $unchained++; continue; }
                $started = true;

                $expected = hash('sha256', $prev . '|' . self::payload(
                    $r->cycle_id, $r->nominee_id, (int) $r->vote_count, (int) $r->cpi_score, (string) $r->snapshot_at
                ));
                if (!hash_equals($expected, $hash) || !hash_equals((string) ($r->prev_hash ?? ''), $prev)) {
                    $broken = (int) $r->id;
                    return false;
                }
                $prev = $hash;
                $checked++;
            }
            return true;
        });

        return ['ok' => $broken === null, 'checked' => $checked, 'broken_at' => $broken, 'unchained' => $unchained];
    }
}
