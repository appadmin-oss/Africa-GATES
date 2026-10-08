<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\ProfileMergeService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Registry-profile merge: linked nominees, CPI history and community reactions
 * fold into one survivor (deduping UNIQUE collisions), the duplicates become
 * hidden tombstones, and an unmerge restores the pre-merge state exactly.
 */
class ProfileMergeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Two profiles for the same person.
        DB::table('gates_profiles')->insert(['id' => 1, 'slug' => 'ada-obi', 'display_name' => 'Ada Obi', 'email' => 'ada@example.com', 'status' => 'approved']);
        DB::table('gates_profiles')->insert(['id' => 2, 'slug' => 'ada-obi-2', 'display_name' => 'ada obi', 'email' => 'ada2@example.com', 'status' => 'approved']);
    }

    private function nominee(int $id, int $profileId): void
    {
        DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => 10, 'name' => 'Ada Obi', 'status' => 'approved', 'profile_id' => $profileId, 'vote_count' => 0, 'organic_vote_count' => 0]);
    }

    public function test_links_move_and_duplicate_is_tombstoned(): void
    {
        $this->nominee(101, 1);
        $this->nominee(102, 2);
        DB::table('gates_cpi_history')->insert(['profile_id' => 2, 'cpi_score' => 300, 'cpi_tier' => 'silver']);

        $r = ProfileMergeService::mergeProfiles(1, [2]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(1, $r['merged']);

        // Both nominees + the CPI-history row now belong to profile 1.
        $this->assertSame(2, (int) DB::table('gates_nominees')->where('profile_id', 1)->count());
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('profile_id', 2)->count());
        $this->assertSame(1, (int) DB::table('gates_cpi_history')->where('profile_id', 1)->count());

        // Profile 2 is a tombstone (kept, hidden), not deleted.
        $p2 = DB::table('gates_profiles')->where('id', 2)->first();
        $this->assertNotNull($p2);
        $this->assertSame(1, (int) $p2->merged_into);
    }

    public function test_cheers_dedupe_on_collision(): void
    {
        // The same fingerprint cheered BOTH profiles → one must be dropped on merge.
        DB::table('gates_cheers')->insert(['target_type' => 'profile', 'target_id' => 1, 'fp' => 'fp-x']);
        DB::table('gates_cheers')->insert(['target_type' => 'profile', 'target_id' => 2, 'fp' => 'fp-x']);
        DB::table('gates_cheers')->insert(['target_type' => 'profile', 'target_id' => 2, 'fp' => 'fp-y']);

        ProfileMergeService::mergeProfiles(1, [2]);

        // Survivor keeps fp-x (its own) + gains fp-y; the colliding fp-x from #2 is dropped.
        $cheers = DB::table('gates_cheers')->where('target_type', 'profile')->where('target_id', 1)->pluck('fp')->sort()->values()->all();
        $this->assertSame(['fp-x', 'fp-y'], $cheers);
        $this->assertSame(0, (int) DB::table('gates_cheers')->where('target_id', 2)->where('target_type', 'profile')->count());
    }

    public function test_unmerge_restores_links_and_dropped_cheer(): void
    {
        $this->nominee(101, 1);
        $this->nominee(102, 2);
        DB::table('gates_cheers')->insert(['target_type' => 'profile', 'target_id' => 1, 'fp' => 'fp-x']);
        DB::table('gates_cheers')->insert(['target_type' => 'profile', 'target_id' => 2, 'fp' => 'fp-x']); // collides

        ProfileMergeService::mergeProfiles(1, [2]);
        $u = ProfileMergeService::unmerge(2);
        $this->assertTrue($u['ok'], $u['error'] ?? '');

        // Nominee 102 moved back to profile 2; profile 2 live again.
        $this->assertSame(102, (int) DB::table('gates_nominees')->where('profile_id', 2)->value('id'));
        $this->assertNull(DB::table('gates_profiles')->where('id', 2)->value('merged_into'));
        // The dropped cheer is re-inserted on profile 2; survivor keeps its own.
        $this->assertSame(1, (int) DB::table('gates_cheers')->where('target_type', 'profile')->where('target_id', 2)->where('fp', 'fp-x')->count());
        $this->assertSame(1, (int) DB::table('gates_cheers')->where('target_type', 'profile')->where('target_id', 1)->where('fp', 'fp-x')->count());
        // Journal consumed.
        $this->assertSame(0, (int) DB::table('gates_profile_merge_log')->where('merged_id', 2)->count());
    }

    public function test_tombstone_excluded_from_listing_scope(): void
    {
        ProfileMergeService::mergeProfiles(1, [2]);
        $q = DB::table('gates_profiles')->where('status', 'approved');
        ProfileMergeService::notMerged($q);
        $ids = $q->pluck('id')->map(fn($i) => (int) $i)->all();
        $this->assertSame([1], $ids);
    }

    public function test_unmerge_rejects_a_live_profile(): void
    {
        $r = ProfileMergeService::unmerge(1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('not a merge tombstone', $r['error']);
    }

    public function test_self_or_empty_merge_is_rejected(): void
    {
        $this->assertFalse(ProfileMergeService::mergeProfiles(1, [1])['ok']);
        $this->assertFalse(ProfileMergeService::mergeProfiles(1, [])['ok']);
        $this->assertFalse(ProfileMergeService::mergeProfiles(999, [1])['ok']);
    }
}
