<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{ReleasedStanding, SnapshotService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE REPAIR FOR STANDINGS SEALED WHEN NOTHING WAS ANNOUNCED.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WENT WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `CycleMaterialiser` fired two side effects on entry to `results` that never referred to
 * each other: the staleness rule withheld every announcement, and the seal recorded the
 * standing "as announced" regardless. A programme running late therefore had its figures
 * frozen and published while scoring carried on behind them — the reported symptom being
 * that a nominee's score stopped moving no matter what the panel did.
 *
 * The source is fixed ({@see \AfricaGates\Services\CycleMaterialiser}). This covers the
 * rows it already wrote, and the two properties that make the repair safe to run on a
 * production archive:
 *
 *  1 · IT IDENTIFIES BY EVIDENCE, NOT BY GUESSWORK. `gates_cycle_transitions` is an
 *      idempotency ledger with UNIQUE (cycle_id, to_status) and it records `notify` —
 *      the platform's own contemporaneous note of whether it announced. A cycle with no
 *      ledger row is left alone: absence of a record is not a record of absence.
 *
 *  2 · IT DEMOTES, IT DOES NOT DELETE. `gates_vote_snapshots` is a hash chain. Removing
 *      a row breaks every link after it and {@see SnapshotService::verify()} would report
 *      tampering for the life of the archive. `capture_kind` is stored beside the hash
 *      and not inside it, so relabelling leaves the chain verifiable — which is asserted
 *      here rather than assumed, because it is the whole reason the repair takes this
 *      shape.
 */
final class UnannouncedSealRepairTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/2027_01_12_unannounced_seal_repair.php';

    /**
     * Run the repair the way the runner does, without its output in the test log.
     *
     * `ReleasedStanding::forCycle()` memoises per process, and the migration runner is its
     * own process — so in production nothing has read a seal by the time the repair runs,
     * and nothing reads a stale one afterwards. In-process the two share a static, and a
     * test that asserts a precondition before repairing would otherwise be handed its own
     * earlier answer back. Dropping the memo here models the process boundary rather than
     * working around it.
     */
    private function repair(): void
    {
        ob_start();
        try {
            require self::MIGRATION;
        } finally {
            ob_end_clean();
        }
        ReleasedStanding::forget();
    }

    /**
     * A cycle carrying a sealed standing, plus its ledger row.
     *
     * The snapshot is appended through the real service so the row carries a real link in
     * the chain — a hand-written INSERT would prove nothing about verifiability.
     */
    private function sealedCycle(int $cycleId, bool $announced): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => $cycleId, 'programme_id' => 0, 'year' => 2026, 'status' => 'results',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycleId, 'slug' => 'cat-' . $cycleId, 'title' => 'Category ' . $cycleId,
        ]);
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => 'Nominee ' . $cycleId, 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 100, 'organic_vote_count' => 100,
        ]);

        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => $cycleId, 'from_status' => 'judging', 'to_status' => 'results',
            'reason' => $announced ? 'auto: date window' : 'auto: date window (stale — notifications suppressed)',
            'actor' => 'cron', 'notify' => $announced ? 1 : 0,
        ]);

        (new SnapshotService())->captureRelease($cycleId);
    }

    private function kinds(int $cycleId): array
    {
        return DB::table('gates_vote_snapshots')->where('cycle_id', $cycleId)
            ->pluck('capture_kind')->map(static fn ($v): string => (string) $v)->unique()
            ->values()->all();
    }

    // ══ the repair ═══════════════════════════════════════════════════════════

    /**
     * A SEAL TAKEN WITH THE ANNOUNCEMENTS SUPPRESSED STOPS BEING READ AS THE ANNOUNCEMENT.
     *
     * The behavioural assertion, not the storage one: what matters is that
     * {@see ReleasedStanding::forCycle()} — the only reader, and what
     * `PublicResults::category()` lays over a published page — no longer finds a standing
     * to publish. The page then recomputes and says so, which is the honest answer for a
     * result nobody has released.
     */
    public function test_a_standing_sealed_without_an_announcement_is_no_longer_the_announcement(): void
    {
        $this->sealedCycle(61, announced: false);

        $this->assertNotNull(ReleasedStanding::forCycle(61),
            'precondition: the bad seal is there to be repaired');

        $this->repair();

        $this->assertNull(ReleasedStanding::forCycle(61),
            'a cycle that announced nothing has no announced standing to publish');
        $this->assertSame(['unannounced'], $this->kinds(61),
            'and the capture is relabelled rather than dropped');
    }

    /**
     * A GENUINELY ANNOUNCED STANDING IS UNTOUCHED.
     *
     * The half that must not regress. A repair that cleared every seal would silently
     * un-announce real awards and put back the 693 → 885 fault sealing exists to prevent.
     */
    public function test_an_announced_standing_survives_the_repair(): void
    {
        $this->sealedCycle(62, announced: true);
        $before = ReleasedStanding::forCycle(62);

        $this->repair();

        $this->assertEquals($before, ReleasedStanding::forCycle(62),
            'what was announced stays announced, to the digit');
    }

    /**
     * A CYCLE WITH NO LEDGER ROW IS LEFT ALONE.
     *
     * The repair reads the platform's own record of whether it announced. Where there is
     * no record it must do nothing — inferring "probably never announced" from a missing
     * row is exactly the guesswork {@see ReleasedStanding} refuses to do when it declines
     * to reconstruct a standing from routine captures.
     */
    public function test_a_cycle_with_no_ledger_row_is_not_touched(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 63, 'programme_id' => 0, 'year' => 2026, 'status' => 'results',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => 63, 'slug' => 'cat-63', 'title' => 'Category 63',
        ]);
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => 'Nominee 63', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 100, 'organic_vote_count' => 100,
        ]);
        (new SnapshotService())->captureRelease(63);

        $this->repair();

        $this->assertNotNull(ReleasedStanding::forCycle(63),
            'no evidence either way is not evidence of no announcement');
    }

    /**
     * THE CHAIN STILL VERIFIES AFTERWARDS.
     *
     * The reason the repair relabels instead of deleting. If this ever fails, the repair
     * has started touching something inside the hash payload
     * (`cycleId|nomineeId|votes|cpi|at`) and every integrity claim the platform makes
     * about its archive is void.
     */
    public function test_the_repair_leaves_the_archive_verifiable(): void
    {
        $this->sealedCycle(64, announced: false);
        $this->sealedCycle(65, announced: true);

        $this->repair();

        $this->assertTrue((new SnapshotService())->verify()['ok'] ?? false,
            'relabelling a capture must not break a single link');
    }

    /**
     * RUNNING IT TWICE CHANGES NOTHING THE FIRST RUN DID NOT.
     *
     * Migrations are include()d in a loop on every deploy and re-run on every fresh
     * database, so a repair that is not idempotent is a repair that breaks on the second
     * deployment.
     */
    public function test_the_repair_is_idempotent(): void
    {
        $this->sealedCycle(66, announced: false);

        $this->repair();
        $after = $this->kinds(66);
        $this->repair();

        $this->assertSame($after, $this->kinds(66));
        $this->assertNull(ReleasedStanding::forCycle(66));
    }
}
