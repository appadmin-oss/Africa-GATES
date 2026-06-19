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
}
