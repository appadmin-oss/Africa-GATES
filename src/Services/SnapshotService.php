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

        return (int) DB::transaction(function () use ($cycles) {
            // FOR UPDATE on the tail: a concurrent capture blocks here instead of
            // reading a link that is about to stop being the tail. Compiles to nothing
            // on SQLite, which is why the unique index below is the real guarantee.
            $prev = (string) (DB::table('gates_vote_snapshots')
                ->orderByDesc('id')->lockForUpdate()->value('hash') ?? '');
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
