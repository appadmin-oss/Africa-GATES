<?php
declare(strict_types=1);

namespace AfricaGates\Services;

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
 */
class SnapshotService
{
    public function __construct(private readonly NomineeScoringService $scoring = new NomineeScoringService()) {}

    /** Canonical, exact-integer payload for one snapshot row. */
    private static function payload(int|string $cycleId, int|string $nomineeId, int $votes, int $cpi, string $at): string
    {
        return implode('|', [$cycleId, $nomineeId, $votes, $cpi, $at]);
    }

    /** Capture standings for every active cycle. Returns the number of rows written. */
    public function capture(): int
    {
        $cycles = DB::table('gates_award_cycles')->whereIn('status', ['voting', 'judging', 'results'])->pluck('id')->all();
        if (!$cycles) return 0;

        $prev = (string) (DB::table('gates_vote_snapshots')->orderByDesc('id')->value('hash') ?? '');
        $at = Carbon::now()->toDateTimeString();
        $written = 0;

        foreach ($cycles as $cycleId) {
            $catIds = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->pluck('id')->all();
            foreach ($catIds as $catId) {
                foreach ($this->scoring->scoreCategory((int) $catId) as $nomineeId => $s) {
                    $hash = hash('sha256', $prev . '|' . self::payload($cycleId, $nomineeId, $s['vote_count'], $s['cpi_score'], $at));
                    DB::table('gates_vote_snapshots')->insert([
                        'cycle_id'    => $cycleId,
                        'nominee_id'  => $nomineeId,
                        'vote_count'  => $s['vote_count'],
                        'judge_score' => $s['judge_score'],
                        'cpi_score'   => $s['cpi_score'],
                        'snapshot_at' => $at,
                        'prev_hash'   => $prev,
                        'hash'        => $hash,
                    ]);
                    $prev = $hash;
                    $written++;
                }
            }
        }
        return $written;
    }

    /**
     * Re-walk the whole chain and confirm no row was altered/reordered/deleted.
     * @return array{ok:bool, checked:int, broken_at:int|null}
     */
    public function verify(): array
    {
        $prev = '';
        $checked = 0;
        foreach (DB::table('gates_vote_snapshots')->orderBy('id')->get() as $r) {
            $expected = hash('sha256', $prev . '|' . self::payload($r->cycle_id, $r->nominee_id, (int) $r->vote_count, (int) $r->cpi_score, (string) $r->snapshot_at));
            if (!hash_equals($expected, (string) $r->hash) || !hash_equals((string) $r->prev_hash, $prev)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r->id];
            }
            $prev = (string) $r->hash;
            $checked++;
        }
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
