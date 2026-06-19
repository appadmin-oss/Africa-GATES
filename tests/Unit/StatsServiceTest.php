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
        $this->assertSame(2, $s['nations_live']);      // NG, GH distinct
        $this->assertSame(1, $s['legacy_events']);     // unpublished excluded
    }
}
