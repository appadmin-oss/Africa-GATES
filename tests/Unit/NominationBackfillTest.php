<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The back-fill that carries every existing nomination into the new table.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE PART WORTH A TEST OF ITS OWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The new form writes `gates_nomination_categories`; every nomination this platform has
 * already taken lives in `gates_nominations.category_id` and `.reason`. Without the
 * back-fill the review desk and the judging stage would show **"no categories" for every
 * nomination ever submitted** — the new code reading the new table and the old rows
 * sitting in the old columns, each half correct. A migration that leaves the previous
 * data unreachable is a migration that deleted it.
 *
 * And it runs ONCE, UNATTENDED, over every row. There is no shell on production:
 * migrations are applied by an operator opening a URL, so it is paid inside a web
 * request that can time out. `MigrateCommand` does not record a file that threw, so a
 * failure halfway leaves the file to re-run from the start on the next deploy — which
 * makes idempotence a requirement rather than a nicety, and makes the per-row query it
 * was first written with a real hazard: a back-fill that cannot finish inside one
 * request never finishes at all.
 *
 * ── IT RUNS THE REAL FILE ───────────────────────────────────────────────────
 *
 * Not a copy of its logic. A test that re-implements a migration's loop proves the copy
 * works and lets the original drift — the same fault `JudgeOtpAttemptCapTest` shipped
 * with, where the guarded update was spelled out again in a helper so the controller
 * could drift back to read-then-compare with every test still passing.
 *
 * Every piece of DDL in the file is guarded on the table or column not existing, and the
 * harness has already applied all of it, so requiring the file again runs the back-fill
 * and nothing else.
 */
final class NominationBackfillTest extends TestCase
{
    private const FILE = '/database/migrations/2027_02_02_nomination_categories_evidence.php';

    private int $cycleId = 0;
    private int $catId   = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'backfill-prog', 'title' => 'Back-fill Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $prog, 'year' => 2026, 'status' => 'nominations',
        ]);
        $this->catId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'bf-cat', 'title' => 'Back-fill Category',
        ]);
    }

    /** Run the real migration file. */
    private function migrate(): void
    {
        ob_start();
        try {
            require dirname(__DIR__, 2) . self::FILE;
        } finally {
            ob_end_clean();
        }
    }

    private function nominate(array $over = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId(array_merge([
            'cycle_id'        => $this->cycleId,
            'category_id'     => $this->catId,
            'nominee_name'    => 'Ada Lovelace',
            'nominator_name'  => 'Grace Hopper',
            'nominator_email' => 'g@example.com',
            'status'          => 'pending',
            'reason'          => 'A reason written before the forty-character floor existed.',
        ], $over));
    }

    // ══════════════════════════════════════════════════════════════════════════

    public function test_an_existing_nomination_is_carried_across_with_its_own_reason(): void
    {
        $id = $this->nominate();

        $this->migrate();

        $row = DB::table('gates_nomination_categories')->where('nomination_id', $id)->first();

        $this->assertNotNull($row, 'the nomination is invisible to everything that reads '
            . 'the new table, which is the whole review desk and the judging stage');
        $this->assertSame($this->catId, (int) $row->category_id);
        $this->assertSame(
            'A reason written before the forty-character floor existed.', $row->reason);
    }

    public function test_a_short_historic_reason_is_carried_as_it_is_and_not_padded(): void
    {
        // The forty-character floor is NEW. A placeholder written in here would read, to
        // a panel, as something the nominator wrote — which is worse than a short reason,
        // because it is not theirs.
        $id = $this->nominate(['reason' => 'Hardworking.']);

        $this->migrate();

        $this->assertSame('Hardworking.', DB::table('gates_nomination_categories')
            ->where('nomination_id', $id)->value('reason'));
    }

    public function test_running_it_twice_moves_nothing_twice(): void
    {
        // Idempotence is not a nicety here. `MigrateCommand` aborts the run on a throw
        // and does NOT record the file, so anything that fails part-way re-runs from the
        // start on the next deploy — and the UNIQUE on (nomination_id, category_id) would
        // abort the whole chunk rather than skip the row.
        $id = $this->nominate();

        $this->migrate();
        $this->migrate();

        $this->assertSame(1, (int) DB::table('gates_nomination_categories')
            ->where('nomination_id', $id)->count());
    }

    public function test_a_nomination_with_no_category_is_left_alone(): void
    {
        // `category_id` has always been nullable, and a row with none has nothing to
        // carry. Inventing a category for it would put a nominee in a race nobody
        // entered them for.
        $id = $this->nominate(['category_id' => null]);

        $this->migrate();

        $this->assertSame(0, (int) DB::table('gates_nomination_categories')
            ->where('nomination_id', $id)->count());
    }

    public function test_a_row_already_moved_by_the_new_form_is_not_duplicated(): void
    {
        // The real shape of a second run: the new form has been live for a while, so the
        // table already holds rows the back-fill did not write. Keyed on the PAIR, not on
        // the nomination, because a nomination now has two or three categories.
        $id = $this->nominate();
        DB::table('gates_nomination_categories')->insert([
            'nomination_id' => $id, 'category_id' => $this->catId,
            'reason' => 'Written through the new form, at full length and by a person.',
            'sort_order' => 0,
        ]);

        $this->migrate();

        $rows = DB::table('gates_nomination_categories')->where('nomination_id', $id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Written through the new form, at full length and by a person.',
            $rows[0]->reason, 'the back-fill overwrote a reason somebody actually typed');
    }

    public function test_it_crosses_a_chunk_boundary(): void
    {
        // 501 rows against `chunkById(500)`, so the loop runs twice. This is the only
        // test here that processes more than one chunk, and the break it catches was
        // staged rather than assumed: replacing the chunked walk with a single
        // `limit(500)->get()` fails THIS test and passes the other five, because every
        // one of them fits in the first pass.
        //
        // That is the shape `scale_is_out`'s own test had — it kept passing over a
        // fault it was written for, because its fixture held one category. A back-fill
        // tested only on a fixture smaller than its own batch size is a back-fill whose
        // batching is not tested at all, and the rows it would silently leave behind
        // are the oldest nominations this platform ever took.
        $ids = [];
        for ($i = 0; $i < 501; $i++) $ids[] = $this->nominate();

        $this->migrate();

        $this->assertSame(501, (int) DB::table('gates_nomination_categories')
            ->whereIn('nomination_id', $ids)->count());
    }
}
