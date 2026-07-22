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
}
