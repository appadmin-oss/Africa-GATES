<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\MergeService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Nominee merge: votes + judge scores fold into one survivor, deduping where a
 * UNIQUE key would collide, counters rebuild from the moved rows, duplicates
 * are deleted — and cross-category merges are refused.
 */
class MergeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A (keep) and B (merge in), same category.
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_nominees')->insert(['id' => 2, 'category_id' => 10, 'name' => 'ada obi', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
    }

    private function vote(int $nominee, string $hash, string $type = 'standard', int $weight = 1): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nominee, 'category_id' => 10, 'voter_email_hash' => $hash,
            'vote_type' => $type, 'weight' => $weight,
        ]);
    }

    public function test_votes_move_and_counters_rebuild(): void
    {
        // A: 2 organic.  B: 3 organic + 1 bonus(×5) + 1 paid(×3).
        $this->vote(1, 'a1'); $this->vote(1, 'a2');
        $this->vote(2, 'b1'); $this->vote(2, 'b2'); $this->vote(2, 'b3');
        $this->vote(2, 'b4', 'bonus', 5); $this->vote(2, 'b5', 'paid', 3);

        $r = MergeService::mergeNominees(1, [2]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(1, $r['merged']);

        // All 7 vote rows now belong to A; B is a tombstone (row kept, hidden via merged_into).
        $this->assertSame(7, (int) DB::table('gates_votes')->where('nominee_id', 1)->count());
        $this->assertSame(0, (int) DB::table('gates_votes')->where('nominee_id', 2)->count());
        $b = DB::table('gates_nominees')->where('id', 2)->first();
        $this->assertNotNull($b, 'merged nominee is tombstoned, not deleted');
        $this->assertSame(1, (int) $b->merged_into);

        // vote_count = Σweight = 2 + 3 + 5 + 3 = 13; organic = Σweight(standard) = 5.
        $keep = DB::table('gates_nominees')->where('id', 1)->first();
        $this->assertSame(13, (int) $keep->vote_count);
        $this->assertSame(5, (int) $keep->organic_vote_count);
    }

    public function test_judge_scores_dedupe_on_collision(): void
    {
        // Judge 7 scored BOTH A and B on criterion 1 (collision) and B on criterion 2 (no collision).
        DB::table('gates_judge_criteria_scores')->insert(['judge_id' => 7, 'nominee_id' => 1, 'category_id' => 10, 'criterion_id' => 1, 'score' => 8]);
        DB::table('gates_judge_criteria_scores')->insert(['judge_id' => 7, 'nominee_id' => 2, 'category_id' => 10, 'criterion_id' => 1, 'score' => 5]);
        DB::table('gates_judge_criteria_scores')->insert(['judge_id' => 7, 'nominee_id' => 2, 'category_id' => 10, 'criterion_id' => 2, 'score' => 7]);

        $r = MergeService::mergeNominees(1, [2]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        $rows = DB::table('gates_judge_criteria_scores')->where('nominee_id', 1)->orderBy('criterion_id')->get();
        $this->assertSame(2, $rows->count(), 'exactly two scores survive — no UNIQUE violation');
        // Survivor's own criterion-1 score kept (8, not B's 5); B's criterion-2 moved over.
        $this->assertSame(8, (int) $rows->firstWhere('criterion_id', 1)->score);
        $this->assertSame(7, (int) $rows->firstWhere('criterion_id', 2)->score);
        $this->assertSame(0, (int) DB::table('gates_judge_criteria_scores')->where('nominee_id', 2)->count());
    }

    public function test_cross_category_merge_is_refused(): void
    {
        DB::table('gates_nominees')->where('id', 2)->update(['category_id' => 99]); // different category
        $this->vote(1, 'a1'); $this->vote(2, 'b1');

        $r = MergeService::mergeNominees(1, [2]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('same category', $r['error']);
        // Nothing changed — both nominees still exist.
        $this->assertNotNull(DB::table('gates_nominees')->where('id', 2)->first());
    }

    public function test_survivor_adopts_profile_and_photo_when_missing(): void
    {
        DB::table('gates_nominees')->where('id', 2)->update(['profile_id' => 55, 'photo_path' => '/uploads/nominees/b.jpg']);
        MergeService::mergeNominees(1, [2]);
        $keep = DB::table('gates_nominees')->where('id', 1)->first();
        $this->assertSame(55, (int) $keep->profile_id);
        $this->assertSame('/uploads/nominees/b.jpg', $keep->photo_path);
    }

    public function test_survivor_keeps_its_own_profile(): void
    {
        DB::table('gates_nominees')->where('id', 1)->update(['profile_id' => 3]);
        DB::table('gates_nominees')->where('id', 2)->update(['profile_id' => 55]);
        MergeService::mergeNominees(1, [2]);
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', 1)->value('profile_id'));
    }

    public function test_self_or_empty_merge_is_rejected(): void
    {
        $this->assertFalse(MergeService::mergeNominees(1, [1])['ok']);   // only itself
        $this->assertFalse(MergeService::mergeNominees(1, [])['ok']);    // nothing
        $this->assertFalse(MergeService::mergeNominees(999, [1])['ok']); // survivor gone
    }

    // ── Merge-undo ────────────────────────────────────────────────────────────

    public function test_unmerge_restores_votes_and_rebuilds_both_counters(): void
    {
        $this->vote(1, 'a1'); $this->vote(1, 'a2');                       // A: 2
        $this->vote(2, 'b1'); $this->vote(2, 'b2'); $this->vote(2, 'b3'); // B: 3
        MergeService::mergeNominees(1, [2]);
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));

        $u = MergeService::unmerge(2);
        $this->assertTrue($u['ok'], $u['error'] ?? '');
        $this->assertSame(1, $u['keep_id']);

        // B's 3 votes moved back; B is live again; counters rebuilt on both.
        $this->assertSame(3, (int) DB::table('gates_votes')->where('nominee_id', 2)->count());
        $this->assertSame(2, (int) DB::table('gates_votes')->where('nominee_id', 1)->count());
        $b = DB::table('gates_nominees')->where('id', 2)->first();
        $this->assertNull($b->merged_into, 'tombstone cleared');
        $this->assertSame(2, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', 2)->value('vote_count'));
        // The undo journal is consumed.
        $this->assertSame(0, (int) DB::table('gates_merge_log')->where('merged_id', 2)->count());
    }

    public function test_unmerge_reinstates_collision_dropped_judge_score(): void
    {
        // Judge 7 scored BOTH on criterion 1 (collision → B's dropped) + B alone on criterion 2.
        DB::table('gates_judge_criteria_scores')->insert(['judge_id' => 7, 'nominee_id' => 1, 'category_id' => 10, 'criterion_id' => 1, 'score' => 8]);
        DB::table('gates_judge_criteria_scores')->insert(['judge_id' => 7, 'nominee_id' => 2, 'category_id' => 10, 'criterion_id' => 1, 'score' => 5]);
        DB::table('gates_judge_criteria_scores')->insert(['judge_id' => 7, 'nominee_id' => 2, 'category_id' => 10, 'criterion_id' => 2, 'score' => 7]);

        MergeService::mergeNominees(1, [2]);
        // After merge: A has crit1=8 (its own) + crit2=7 (moved); B's crit1=5 was dropped.
        $this->assertSame(2, (int) DB::table('gates_judge_criteria_scores')->where('nominee_id', 1)->count());
        $this->assertSame(0, (int) DB::table('gates_judge_criteria_scores')->where('nominee_id', 2)->count());

        MergeService::unmerge(2);
        // B gets BOTH its scores back (the dropped crit1=5 re-inserted, crit2=7 moved back); A keeps only its own crit1=8.
        $bRows = DB::table('gates_judge_criteria_scores')->where('nominee_id', 2)->orderBy('criterion_id')->get();
        $this->assertSame(2, $bRows->count());
        $this->assertSame(5, (int) $bRows->firstWhere('criterion_id', 1)->score);
        $this->assertSame(7, (int) $bRows->firstWhere('criterion_id', 2)->score);
        $aRows = DB::table('gates_judge_criteria_scores')->where('nominee_id', 1)->get();
        $this->assertSame(1, $aRows->count());
        $this->assertSame(8, (int) $aRows->first()->score);
    }

    public function test_tombstone_is_excluded_from_scoring_surface(): void
    {
        $this->vote(1, 'a1'); $this->vote(2, 'b1');
        MergeService::mergeNominees(1, [2]);

        // The scoring query (leaderboard/CPI) must not see the tombstone.
        $q = DB::table('gates_nominees')->where('category_id', 10)->whereIn('status', ['approved', 'winner', 'runner_up']);
        MergeService::notMerged($q);
        $ids = $q->pluck('id')->map(fn($i) => (int) $i)->all();
        $this->assertSame([1], $ids, 'only the survivor is live for scoring');
    }

    public function test_unmerge_one_of_several_leaves_the_others_merged(): void
    {
        DB::table('gates_nominees')->insert(['id' => 3, 'category_id' => 10, 'name' => 'A. Obi', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        $this->vote(1, 'a1'); $this->vote(2, 'b1'); $this->vote(3, 'c1');
        MergeService::mergeNominees(1, [2, 3]);
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));

        MergeService::unmerge(2);                       // restore only #2
        $this->assertNull(DB::table('gates_nominees')->where('id', 2)->value('merged_into'));
        $this->assertSame(1, (int) DB::table('gates_nominees')->where('id', 3)->value('merged_into'), '#3 stays merged');
        // Keep now has its own vote + #3's (2); #2 has its own (1) back.
        $this->assertSame(2, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
        $this->assertSame(1, (int) DB::table('gates_nominees')->where('id', 2)->value('vote_count'));
    }

    public function test_unmerge_rejects_a_live_nominee(): void
    {
        $r = MergeService::unmerge(1);                  // #1 was never merged
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('not a merge tombstone', $r['error']);
    }

    public function test_merging_into_a_tombstone_is_refused(): void
    {
        $this->vote(1, 'a1'); $this->vote(2, 'b1');
        MergeService::mergeNominees(1, [2]);            // 2 is now a tombstone
        $r = MergeService::mergeNominees(2, [1]);       // try to keep the tombstone
        $this->assertFalse($r['ok']);
    }
}
