<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\StatsService;

class StatsServiceTest extends TestCase
{
    public function test_empty_database_returns_zeroes(): void
    {
        $s = (new StatsService())->summary();
        $this->assertSame(
            ['total_profiles' => 0, 'total_votes' => 0, 'nations_live' => 0, 'legacy_events' => 0, 'categories' => 0],
            $s
        );
    }

    public function test_counts_reflect_real_rows(): void
    {
        // 2 approved profiles in 2 nations, 1 pending (must not count)
        DB::table('gates_profiles')->insert([
            ['slug' => 'a', 'display_name' => 'A', 'email' => 'a@x.io', 'country_code' => 'NG', 'status' => 'approved'],
            ['slug' => 'b', 'display_name' => 'B', 'email' => 'b@x.io', 'country_code' => 'GH', 'status' => 'approved'],
            ['slug' => 'c', 'display_name' => 'C', 'email' => 'c@x.io', 'country_code' => 'KE', 'status' => 'pending'],
        ]);
        DB::table('gates_votes')->insert([
            ['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'h1', 'voted_at' => '2026-01-01 00:00:00'],
            ['nominee_id' => 1, 'category_id' => 2, 'voter_email_hash' => 'h2', 'voted_at' => '2026-01-01 00:00:00'],
        ]);
        DB::table('gates_legacy_events')->insert([
            ['slug' => 'e1', 'title' => 'E1', 'event_date' => '2025-01-01', 'is_published' => 1],
            ['slug' => 'e2', 'title' => 'E2', 'event_date' => '2025-02-01', 'is_published' => 0],
        ]);

        $s = (new StatsService())->summary();
        $this->assertSame(2, $s['total_profiles']);   // pending excluded
        $this->assertSame(2, $s['total_votes']);
        $this->assertSame(1, $s['legacy_events']);     // unpublished excluded

        // ── AND NOT TWO NATIONS ─────────────────────────────────────────────
        //
        // This used to assert 2 — "NG, GH distinct" over approved profiles — which is a
        // count of the DIRECTORY, and the site never published it as one. The footer, the
        // meta description and the JSON-LD all print `NationsLive::phrase()`, which counts
        // nations with a nominee standing in a LIVE award and says in as many words why a
        // registered profile is not the platform operating in a country. Anybody may
        // register from anywhere.
        //
        // So the homepage could print "2 nations live" beside a footer reading "live in
        // Nigeria" on the same page load, and the larger figure was the wrong one. Two
        // readers of one claim; the assertion is now on the published definition.
        $this->assertSame(0, $s['nations_live']);
        $this->assertSame(\AfricaGates\Support\NationsLive::count(), $s['nations_live']);
    }

    /**
     * WHAT DOES MOVE IT: somebody standing in a live award.
     *
     * Same seeding as `NationsLiveTest`, deliberately — this asserts the figure the
     * homepage prints is the figure that service resolves, not a second count that
     * happens to agree today.
     */
    public function test_nations_live_counts_nominees_standing_in_a_live_award(): void
    {
        $programme = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'live', 'title' => 'Live', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $programme, 'year' => 2026, 'status' => 'voting',
        ]);
        $category = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'cat', 'title' => 'Category', 'sort_order' => 1,
        ]);
        DB::table('gates_nominees')->insert([
            ['category_id' => $category, 'name' => 'Adaeze Nwankwo', 'status' => 'approved',
             'country_code' => 'NG', 'vote_count' => 0, 'organic_vote_count' => 0],
            ['category_id' => $category, 'name' => 'Kwabena Mensah', 'status' => 'approved',
             'country_code' => 'GH', 'vote_count' => 0, 'organic_vote_count' => 0],
            // Pending: nominated, not standing.
            ['category_id' => $category, 'name' => 'Under review', 'status' => 'pending',
             'country_code' => 'KE', 'vote_count' => 0, 'organic_vote_count' => 0],
        ]);

        $this->assertSame(2, (new StatsService())->summary()['nations_live']);
    }
}
