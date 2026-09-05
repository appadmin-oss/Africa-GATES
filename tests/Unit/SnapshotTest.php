<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\SnapshotService;

/**
 * Phase 2 — tamper-evident snapshots: capture writes a hash-chained row per
 * nominee, verify() re-walks the chain, and any alteration is detected.
 */
class SnapshotTest extends TestCase
{
    private function seed(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'A', 'status' => 'approved', 'vote_count' => 5]);
        DB::table('gates_nominees')->insert(['id' => 2, 'category_id' => 1, 'name' => 'B', 'status' => 'approved', 'vote_count' => 3]);
    }

    public function test_capture_writes_chained_rows_and_verifies(): void
    {
        $this->seed();
        $svc = new SnapshotService();

        $this->assertSame(2, $svc->capture());
        $this->assertSame(2, DB::table('gates_vote_snapshots')->count());

        $v = $svc->verify();
        $this->assertTrue($v['ok']);
        $this->assertSame(2, $v['checked']);
        $this->assertSame('', (string) DB::table('gates_vote_snapshots')->orderBy('id')->value('prev_hash'));
    }

    public function test_verify_detects_tampering(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture();

        // Alter a stored standing without re-deriving the chain.
        $id = (int) DB::table('gates_vote_snapshots')->orderBy('id')->value('id');
        DB::table('gates_vote_snapshots')->where('id', $id)->update(['vote_count' => 999]);

        $v = $svc->verify();
        $this->assertFalse($v['ok']);
        $this->assertSame($id, $v['broken_at']);
    }

    public function test_second_capture_continues_the_chain(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture();
        $lastHash1 = DB::table('gates_vote_snapshots')->orderByDesc('id')->value('hash');

        $svc->capture();

        $this->assertSame(4, DB::table('gates_vote_snapshots')->count());
        $thirdRow = DB::table('gates_vote_snapshots')->orderBy('id')->skip(2)->first();
        $this->assertSame($lastHash1, $thirdRow->prev_hash);
        $this->assertTrue($svc->verify()['ok']);
    }

    public function test_verify_detects_middle_row_tamper(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture();
        $svc->capture(); // 4 rows — a real chain with a middle link

        // Tamper the SECOND row, not the first — proves the walk stops at the
        // right point and reports rows verified before the break.
        $second = DB::table('gates_vote_snapshots')->orderBy('id')->skip(1)->first();
        DB::table('gates_vote_snapshots')->where('id', $second->id)->update(['cpi_score' => 12345]);

        $v = $svc->verify();
        $this->assertFalse($v['ok']);
        $this->assertSame((int) $second->id, $v['broken_at']);
        $this->assertSame(1, $v['checked']);
    }

    public function test_verify_detects_row_deletion(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture(); // 2 rows

        // Delete the first row: the survivor's prev_hash no longer matches the chain.
        $first  = DB::table('gates_vote_snapshots')->orderBy('id')->first();
        $second = DB::table('gates_vote_snapshots')->orderBy('id')->skip(1)->first();
        DB::table('gates_vote_snapshots')->where('id', $first->id)->delete();

        $v = $svc->verify();
        $this->assertFalse($v['ok']);
        $this->assertSame((int) $second->id, $v['broken_at']);
    }

    /**
     * THE FORK. This is the failure the unique index exists for, and it is written
     * as the race actually happens: capture() reads the tail hash, and a second run
     * that read the SAME tail tries to extend it too.
     *
     * Simulated by taking the tail's prev_hash and inserting another row claiming it,
     * which is byte-for-byte what a concurrent capture would have written. Before
     * UNIQUE(prev_hash) that insert succeeded and the archive was permanently
     * unverifiable — not because anything was wrong with either row, but because the
     * chain had stopped being a line and the only repair was to rewrite history.
     */
    public function test_a_second_run_cannot_extend_the_same_link(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture();

        $tail = DB::table('gates_vote_snapshots')->orderByDesc('id')->first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('gates_vote_snapshots')->insert([
            'cycle_id'    => 1, 'nominee_id' => 1, 'vote_count' => 5, 'cpi_score' => 0,
            'snapshot_at' => '2026-01-01 00:00:00',
            'prev_hash'   => $tail->prev_hash,          // <- the link is already spoken for
            'hash'        => str_repeat('f', 64),
        ]);
    }

    /**
     * The genesis link is a link too: only one row may begin the chain. Without this
     * the race on an EMPTY table — two first-ever captures, both reading '' — forks
     * the archive at row 1, which is the worst place for it to happen.
     */
    public function test_only_one_row_can_begin_the_chain(): void
    {
        $this->seed();
        (new SnapshotService())->capture();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('gates_vote_snapshots')->insert([
            'cycle_id'    => 1, 'nominee_id' => 2, 'vote_count' => 3, 'cpi_score' => 0,
            'snapshot_at' => '2026-01-01 00:00:00',
            'prev_hash'   => '',                        // <- a second genesis
            'hash'        => str_repeat('e', 64),
        ]);
    }

    /**
     * A capture that collides rolls back WHOLE. Losing one six-hourly reading costs
     * nothing; half a reading left behind is a thing somebody has to reason about
     * later, at the point where reasoning about the archive is exactly what has been
     * called into question.
     */
    public function test_a_colliding_capture_writes_nothing_at_all(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture();                                   // rows 1,2 — prev '' then $h1

        $rows = DB::table('gates_vote_snapshots')->orderBy('id')->get();
        $h1   = (string) $rows[0]->hash;                   // already extended, by row 2
        $h2   = (string) $rows[1]->hash;

        // Put the run in the position a stale reader is in: the tail it is about to
        // build from is a link that has ALREADY been extended. (Appending a row whose
        // own hash is $h1 is the only way to reach that state deterministically in a
        // single-threaded test; concurrently it happens by two runs reading one tail.)
        DB::table('gates_vote_snapshots')->insert([
            'cycle_id'    => 1, 'nominee_id' => 1, 'vote_count' => 5, 'cpi_score' => 0,
            'snapshot_at' => '2026-01-01 00:00:00',
            'prev_hash'   => $h2, 'hash' => $h1,
        ]);
        $before = DB::table('gates_vote_snapshots')->count();

        try {
            $svc->capture();
            $this->fail('the colliding capture should have thrown');
        } catch (\Throwable) { /* expected */ }

        $this->assertSame($before, DB::table('gates_vote_snapshots')->count(),
            'a capture that collided must leave no partial rows behind');
    }

    /**
     * Rows written before prev_hash existed are reported as unverifiable, NOT as
     * tampering. The original walk compared them against a computed digest and
     * declared the archive altered — a false accusation at the top of the record
     * that no operator could ever clear, because the data to clear it was never
     * written. Every installation that captured a snapshot before 2026_06_14 would
     * have been told its history had been edited.
     */
    public function test_pre_chain_rows_are_reported_not_blamed(): void
    {
        $this->seed();

        // Two legacy rows: no prev_hash, no hash. Written before the chain existed.
        foreach ([[1, 5], [2, 3]] as [$nomId, $votes]) {
            DB::table('gates_vote_snapshots')->insert([
                'cycle_id'    => 1, 'nominee_id' => $nomId, 'vote_count' => $votes, 'cpi_score' => 0,
                'snapshot_at' => '2026-01-01 00:00:00',
                'prev_hash'   => null, 'hash' => null,
            ]);
        }

        $svc = new SnapshotService();
        $svc->capture();                               // the chain starts after them

        $v = $svc->verify();
        $this->assertTrue($v['ok'], 'legacy rows are outside the chain, not evidence against it');
        $this->assertSame(2, $v['unchained']);
        $this->assertSame(2, $v['checked']);
    }

    /** A missing hash AFTER the chain has started is a deletion, not legacy data. */
    public function test_a_hash_stripped_mid_chain_is_still_a_break(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture();
        $svc->capture();

        $second = DB::table('gates_vote_snapshots')->orderBy('id')->skip(1)->first();
        DB::table('gates_vote_snapshots')->where('id', $second->id)->update(['hash' => null]);

        $v = $svc->verify();
        $this->assertFalse($v['ok']);
        $this->assertSame((int) $second->id, $v['broken_at']);
        $this->assertSame(0, $v['unchained'], 'a stripped hash mid-chain is not "pre-chain"');
    }

    /** Chunking is an implementation detail: the verdict must not depend on it. */
    public function test_the_walk_gives_the_same_answer_at_any_chunk_size(): void
    {
        $this->seed();
        $svc = new SnapshotService();
        $svc->capture();
        $svc->capture();
        $svc->capture();   // 6 rows, so a chunk of 1 and of 4 both straddle batches

        foreach ([1, 2, 4, 1000] as $chunk) {
            $v = $svc->verify($chunk);
            $this->assertTrue($v['ok'], "chunk $chunk");
            $this->assertSame(6, $v['checked'], "chunk $chunk");
        }

        // And a break is found at the same row no matter where the batch boundary lands.
        $fourth = DB::table('gates_vote_snapshots')->orderBy('id')->skip(3)->first();
        DB::table('gates_vote_snapshots')->where('id', $fourth->id)->update(['cpi_score' => 777]);
        foreach ([1, 2, 4, 1000] as $chunk) {
            $this->assertSame((int) $fourth->id, $svc->verify($chunk)['broken_at'], "chunk $chunk");
        }
    }
}
