<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Every mark a judge sets or changes leaves a record.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ASYMMETRY THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_vote_snapshots` is hash-chained, and {@see \AfricaGates\Services\SnapshotService}
 * explains at length why: altering, inserting, deleting or reordering a historical row
 * breaks the chain, so the record of how standings evolved is verifiable afterwards.
 *
 * The INPUTS to that record had no protection of any kind. `saveScore()` writes with
 * `updateOrInsert`, so a judge who marked a nominee 9 and later changed it to 3 left a row
 * saying 3, an `updated_at` that had moved, and nothing else — no previous value, no count
 * of revisions, nothing anywhere in the application.
 *
 * The platform could therefore prove a published standing had not been edited, and could
 * not answer the question anybody actually asks when a result is disputed: did a judge
 * change their mark, and when.
 */
final class JudgeScoreTrailTest extends TestCase
{
    private JudgeService $svc;
    private int $judge = 0;
    private int $nominee = 0;
    /** @var list<int> */
    private array $crit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new JudgeService();

        $prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'p', 'title' => 'P', 'is_active' => 1,
        ]);
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $prog, 'year' => 2026, 'status' => 'judging',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'c', 'title' => 'C', 'sort_order' => 1,
        ]);
        $this->nominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => 'N', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        $this->judge = (int) DB::table('gates_judges')->insertGetId([
            'name' => 'J', 'email' => 'j@x.io', 'is_active' => 1,
            'programme_ids' => json_encode([$prog]),
        ]);

        $this->publishShortlist($cycle, $cat, [$this->nominee]);

        $this->crit = array_map('intval',
            DB::table('gates_judge_criteria')->whereNull('programme_id')->pluck('id')->all());
    }

    /** @return list<object> */
    private function trail(): array
    {
        return DB::table('gates_judge_score_log')->orderBy('id')->get()->all();
    }

    // ════════════════════════════════════════════════════════════════════════

    /**
     * A revision is recorded with what it changed FROM.
     *
     * The old value is the entire point. "This is now 3" is already in the scores table;
     * "this was 9 until Tuesday" is the fact a disputed result turns on.
     */
    public function test_changing_a_mark_records_what_it_was(): void
    {
        $cid = $this->crit[0];

        $this->svc->saveScore($this->judge, $this->nominee, [$cid => 9]);
        $this->svc->saveScore($this->judge, $this->nominee, [$cid => 3]);

        $trail = $this->trail();
        $this->assertCount(2, $trail);

        $this->assertNull($trail[0]->old_score, 'a first mark is not a change from zero');
        $this->assertSame(9, (int) $trail[0]->new_score);

        $this->assertSame(9, (int) $trail[1]->old_score, 'the previous mark was lost');
        $this->assertSame(3, (int) $trail[1]->new_score);
    }

    /**
     * A first mark of ZERO is distinguishable from never having been marked.
     *
     * `old_score` is nullable precisely so these two do not collapse. A NOT NULL default
     * would make "first scored 0" and "changed 0 to 0" the same row.
     */
    public function test_a_first_mark_of_zero_is_not_confused_with_no_mark(): void
    {
        $cid = $this->crit[0];

        $this->svc->saveScore($this->judge, $this->nominee, [$cid => 0]);

        $trail = $this->trail();
        $this->assertCount(1, $trail);
        $this->assertNull($trail[0]->old_score);
        $this->assertSame(0, (int) $trail[0]->new_score);
    }

    /**
     * Re-saving the SAME value writes nothing.
     *
     * The ballot autosaves on a debounce and re-sends every mark it holds each time, so
     * logging unconditionally would bury the handful of real revisions under thousands of
     * no-ops — which is the same as having no log.
     */
    public function test_resaving_an_unchanged_mark_adds_nothing(): void
    {
        $cid = $this->crit[0];

        $this->svc->saveScore($this->judge, $this->nominee, [$cid => 7]);
        $this->svc->saveScore($this->judge, $this->nominee, [$cid => 7]);
        $this->svc->saveScore($this->judge, $this->nominee, [$cid => 7]);

        $this->assertCount(1, $this->trail(), 'the debounce filled the log with no-ops');
    }

    /** A whole scorecard logs one row per criterion, not one per save. */
    public function test_a_full_scorecard_logs_each_criterion_once(): void
    {
        $marks = [];
        foreach ($this->crit as $cid) $marks[$cid] = 6;

        $this->svc->saveScore($this->judge, $this->nominee, $marks);
        $this->svc->saveScore($this->judge, $this->nominee, $marks);

        $this->assertCount(count($this->crit), $this->trail());
    }

    /**
     * A refused save writes no trail — the mark did not happen.
     *
     * The log has to describe what the scores table actually holds. A rejected attempt
     * recorded as a change would put a mark in the audit trail that never existed in the
     * result, which is a worse lie than the gap it was written to close.
     */
    public function test_a_refused_save_leaves_no_trail(): void
    {
        $cid = $this->crit[0];
        $this->svc->declareConflict($this->judge,
            (int) DB::table('gates_award_programmes')->where('slug', 'p')->value('id'));

        $r = $this->svc->saveScore($this->judge, $this->nominee, [$cid => 8]);

        $this->assertFalse($r['ok'], 'a recused judge was allowed to score');
        $this->assertSame([], $this->trail());
    }

    /**
     * And a logging failure never costs the judge their mark.
     *
     * The score is the thing the platform exists to collect; the trail is what explains it
     * afterwards. A log that can refuse a save is worse than a gap in the log — a judge
     * mid-panel would simply be unable to work.
     */
    public function test_the_score_survives_a_broken_log(): void
    {
        $cid = $this->crit[0];
        DB::statement('ALTER TABLE gates_judge_score_log RENAME TO _log_hidden');

        try {
            $r = $this->svc->saveScore($this->judge, $this->nominee, [$cid => 8]);

            $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
            $this->assertSame(8, (int) DB::table('gates_judge_criteria_scores')
                ->where('judge_id', $this->judge)->where('criterion_id', $cid)->value('score'));
        } finally {
            DB::statement('ALTER TABLE _log_hidden RENAME TO gates_judge_score_log');
        }
    }
}
