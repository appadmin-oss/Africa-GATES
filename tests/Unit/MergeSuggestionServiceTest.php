<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\MergeSuggestionService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Duplicate-scan suggestions — the always-on deterministic layer (no AI key in
 * tests): clusters exact + near-miss names within a category, ignores clearly
 * different people, and never groups across categories.
 */
class MergeSuggestionServiceTest extends TestCase
{
    private function nominee(int $id, string $name, int $cat = 10): void
    {
        DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => $cat, 'name' => $name, 'status' => 'approved', 'vote_count' => 0]);
    }

    public function test_clusters_exact_and_near_duplicates(): void
    {
        $this->nominee(1, 'Dr. Jane Doe');
        $this->nominee(2, 'jane doe');       // exact (normalised)
        $this->nominee(3, 'Jane Doo');       // edit-distance 1
        $this->nominee(4, 'Samuel Eto');     // unrelated

        $r = MergeSuggestionService::forCategory(10);
        $this->assertCount(1, $r['groups'], 'one duplicate group');
        $ids = $r['groups'][0]['nominee_ids'];
        sort($ids);
        $this->assertSame([1, 2, 3], $ids);
        $this->assertSame('rule', $r['groups'][0]['source']);
    }

    public function test_distinct_names_produce_no_group(): void
    {
        $this->nominee(1, 'Ada Obi');
        $this->nominee(2, 'Chidi Okeke');
        $this->assertSame([], MergeSuggestionService::forCategory(10)['groups']);
    }

    public function test_same_name_different_category_not_grouped(): void
    {
        $this->nominee(1, 'Ada Obi', 10);
        $this->nominee(2, 'Ada Obi', 20); // same name, different category
        $this->assertSame([], MergeSuggestionService::forCategory(10)['groups']);
        $this->assertSame([], MergeSuggestionService::forCategory(20)['groups']);
    }

    public function test_forCycle_aggregates_categories_with_titles(): void
    {
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 10, 'cycle_id' => 1, 'slug' => 'a', 'title' => 'Alpha', 'sort_order' => 1]);
        DB::table('gates_award_categories')->insert(['id' => 20, 'cycle_id' => 1, 'slug' => 'b', 'title' => 'Beta', 'sort_order' => 2]);
        $this->nominee(1, 'Ada Obi', 10);
        $this->nominee(2, 'ada obi', 10);
        $this->nominee(3, 'Bola Ade', 20);
        $this->nominee(4, 'bola ade', 20);

        $r = MergeSuggestionService::forCycle(1);
        $this->assertCount(2, $r['groups']);
        $cats = array_map(fn($g) => $g['category'], $r['groups']);
        sort($cats);
        $this->assertSame(['Alpha', 'Beta'], $cats);
    }

    // ── findLiveMatch (auto-attach on approval) ─────────────────────────────────

    public function test_findLiveMatch_links_honorific_and_punctuation_variants(): void
    {
        $this->nominee(1, 'Jane Doe');
        // Approving a nomination worded "Dr. Jane Doe" resolves to the existing nominee.
        $this->assertSame(1, MergeSuggestionService::findLiveMatch(10, 'Dr. Jane Doe'));
        $this->assertSame(1, MergeSuggestionService::findLiveMatch(10, '  jane   doe '));
        $this->assertSame(1, MergeSuggestionService::findLiveMatch(10, 'Prof Jane-Doe'));
    }

    public function test_findLiveMatch_returns_zero_for_a_different_person(): void
    {
        $this->nominee(1, 'Jane Doe');
        $this->assertSame(0, MergeSuggestionService::findLiveMatch(10, 'John Doe'));
        // Conservative: a near-miss (edit-distance) is NOT auto-attached — that's
        // left to the human-confirmed merge flow.
        $this->assertSame(0, MergeSuggestionService::findLiveMatch(10, 'Jane Doo'));
    }

    public function test_findLiveMatch_ignores_other_categories(): void
    {
        $this->nominee(1, 'Jane Doe', 20);
        $this->assertSame(0, MergeSuggestionService::findLiveMatch(10, 'Jane Doe'));
    }

    public function test_findLiveMatch_skips_tombstones(): void
    {
        $this->nominee(1, 'Jane Doe');
        $this->nominee(2, 'Jane Doe');
        // Tombstone #1 (as if merged into #2); the resolver must return the live one.
        DB::table('gates_nominees')->where('id', 1)->update(['merged_into' => 2]);
        $this->assertSame(2, MergeSuggestionService::findLiveMatch(10, 'Dr Jane Doe'));

        // With the only match tombstoned, there's nothing to attach to.
        DB::table('gates_nominees')->where('id', 2)->update(['merged_into' => 1, 'status' => 'approved']);
        DB::table('gates_nominees')->where('id', 1)->update(['merged_into' => null]);
        DB::table('gates_nominees')->where('id', 1)->update(['name' => 'Someone Else']);
        $this->assertSame(0, MergeSuggestionService::findLiveMatch(10, 'Jane Doe'));
    }
}
