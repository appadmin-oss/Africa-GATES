<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MemberActivityService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Member dashboard activity: read-only aggregation over existing tables using
 * the platform's identity convention (sha256 of lowercased trimmed email).
 * Nothing here writes — the audited vote path stays untouched.
 */
final class MemberActivityServiceTest extends TestCase
{
    private const EMAIL = 'Member@X.io'; // deliberately mixed-case

    private function hash(): string
    {
        return hash('sha256', strtolower(trim(self::EMAIL)));
    }

    public function test_votes_for_returns_member_votes_with_nominee_names(): void
    {
        DB::table('gates_nominees')->insert(['id' => 5, 'name' => 'Ada Obi', 'category_id' => 2, 'status' => 'approved']);
        DB::table('gates_award_categories')->insert(['id' => 2, 'cycle_id' => 1, 'slug' => 'arts', 'title' => 'Arts & Culture']);
        DB::table('gates_votes')->insert([
            ['nominee_id' => 5, 'category_id' => 2, 'voter_email_hash' => $this->hash(), 'vote_type' => 'standard', 'weight' => 1, 'voted_at' => '2026-07-01 10:00:00'],
            ['nominee_id' => 5, 'category_id' => 2, 'voter_email_hash' => hash('sha256', 'someone@else.io'), 'vote_type' => 'standard', 'weight' => 1, 'voted_at' => '2026-07-01 11:00:00'],
        ]);

        $votes = MemberActivityService::votesFor(self::EMAIL);
        $this->assertCount(1, $votes);
        $this->assertSame('Ada Obi', $votes[0]['nominee']);
        $this->assertSame('Arts & Culture', $votes[0]['category']);
    }

    public function test_nominations_for_matches_case_insensitively(): void
    {
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'nominations']);
        DB::table('gates_nominations')->insert([
            'cycle_id' => 1, 'nominee_name' => 'Chidi Okeke', 'nominator_name' => 'A Member',
            'nominator_email' => 'member@x.io', 'status' => 'pending', 'reference' => 'AGN-2026-000001-X',
            'created_at' => '2026-07-01 09:00:00',
        ]);
        $noms = MemberActivityService::nominationsFor(self::EMAIL);
        $this->assertCount(1, $noms);
        $this->assertSame('Chidi Okeke', $noms[0]['nominee']);
        $this->assertSame('pending', $noms[0]['status']);
        $this->assertSame('AGN-2026-000001-X', $noms[0]['reference']);
    }

    public function test_share_links_for_lists_only_own_links(): void
    {
        DB::table('gates_nomination_links')->insert([
            ['token' => str_repeat('a', 64), 'payload' => '{"nominee_name":"Ada Obi"}', 'created_by' => 7, 'hits' => 3, 'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => date('Y-m-d H:i:s')],
            ['token' => str_repeat('b', 64), 'payload' => '{"nominee_name":"Other"}', 'created_by' => 9, 'hits' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => date('Y-m-d H:i:s')],
        ]);
        $links = MemberActivityService::shareLinksFor(7);
        $this->assertCount(1, $links);
        $this->assertSame('Ada Obi', $links[0]['nominee']);
        $this->assertSame(3, $links[0]['hits']);
    }

    public function test_community_counts(): void
    {
        DB::table('gates_threads')->insert(['slug' => 't-1', 'title' => 'T', 'body' => 'b', 'author_name' => 'M', 'author_email_hash' => $this->hash(), 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_comments')->insert(['target_type' => 'thread', 'target_id' => 1, 'author_name' => 'M', 'author_email_hash' => $this->hash(), 'body' => 'hi', 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
        $c = MemberActivityService::communityCountsFor(self::EMAIL);
        $this->assertSame(1, $c['threads']);
        $this->assertSame(1, $c['comments']);
    }

    public function test_completeness_and_checklist(): void
    {
        $user = (object) ['name' => 'A Member', 'email' => self::EMAIL, 'phone' => '', 'password_hash' => null, 'email_verified' => 1];
        $c = MemberActivityService::completeness($user);
        $this->assertGreaterThan(0, $c['pct']);
        $this->assertLessThan(100, $c['pct']);
        $this->assertContains('phone', $c['missing']);

        $done = (object) ['name' => 'A Member', 'email' => self::EMAIL, 'phone' => '+2348031234567', 'password_hash' => 'x', 'email_verified' => 1];
        $this->assertSame(100, MemberActivityService::completeness($done)['pct']);
    }
}
