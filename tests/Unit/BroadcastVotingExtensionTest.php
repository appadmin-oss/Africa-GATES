<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NomineeBroadcast;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * THE VOTING-EXTENSION BROADCAST FOUND NOBODY (owner, 17 Nov 2026, a screenshot of the campaign
 * page: "That did not work — No nominee resolved in a live voting cycle", "Will be sent 0").
 *
 * Voting had closed, the maintenance run had moved the cycle's cached `status` to `judging`,
 * and then the close date was EXTENDED — which reopens voting on every public page, because
 * the phase is computed from the dates (CyclePolicy::phaseFor). The broadcast alone still read
 * `status = 'voting'`, so the one campaign whose whole message is "you now have until …"
 * resolved no cycle at all. These hold the broadcast to the ballot's own answer, and hold the
 * refusal to a reason an operator can act on.
 *
 * Ids below 255 (TINYINT clamp on MySQL).
 */
final class BroadcastVotingExtensionTest extends TestCase
{
    private const P = 97, Y = 9701, C = 9711, N = 9721;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'ext-award', 'title' => 'Extension Award', 'is_active' => 1]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'teachers', 'title' => 'Best Teacher']);
        DB::table('gates_nominees')->insert(['id' => self::N, 'name' => 'Chiamaka Eze', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_nominations')->insert(['cycle_id' => self::Y, 'category_id' => self::C, 'nominee_name' => 'Chiamaka Eze',
            'nominee_email' => 'chiamaka@example.org', 'nominator_name' => 'A', 'nominator_email' => 'a@example.org', 'reason' => 'x', 'status' => 'approved',
            'created_at' => date('Y-m-d H:i:s')]);
    }

    private function cycle(string $status, ?string $open, ?string $close): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => 2026, 'status' => $status,
            'voting_open' => $open, 'voting_close' => $close]);
    }

    public function test_an_extended_cycle_whose_cached_status_says_judging_is_reached(): void
    {
        $this->cycle('judging', Carbon::now()->subDays(20)->toDateTimeString(), Carbon::now()->addDays(3)->toDateTimeString());
        $plan = (new NomineeBroadcast())->plan();
        $this->assertCount(1, $plan['cycles'], 'an extended cycle read as closed because of its cached status');
        $this->assertSame(1, $plan['counts']['sendable']);
        $this->assertSame('', (new NomineeBroadcast())->whyEmpty($plan));
    }

    public function test_a_cycle_whose_dates_say_closed_is_not_reached_whatever_its_status(): void
    {
        $this->cycle('voting', Carbon::now()->subDays(20)->toDateTimeString(), Carbon::now()->subDay()->toDateTimeString());
        $plan = (new NomineeBroadcast())->plan();
        $this->assertSame([], $plan['cycles'], 'nominees told voting is open on a closed cycle');
        $why = (new NomineeBroadcast())->whyEmpty($plan);
        $this->assertStringContainsString('No cycle is open for voting right now', $why);
    }

    public function test_the_refusal_names_the_cycle_and_how_to_extend_it(): void
    {
        // Results not yet set, results date far off: the cycle is with the jury.
        $this->cycle('judging', Carbon::now()->subDays(20)->toDateTimeString(), Carbon::now()->subHours(2)->toDateTimeString());
        $why = (new NomineeBroadcast())->whyEmpty();
        $this->assertStringContainsString('Extension Award 2026', $why);
        $this->assertStringContainsString('voting close date in the future', $why);
    }

    public function test_open_voting_with_no_address_says_so_rather_than_no_cycle(): void
    {
        $this->cycle('voting', Carbon::now()->subDays(2)->toDateTimeString(), Carbon::now()->addDays(3)->toDateTimeString());
        DB::table('gates_nominations')->where('cycle_id', self::Y)->update(['nominee_email' => '']);
        $why = (new NomineeBroadcast())->whyEmpty();
        $this->assertStringContainsString('none of its 1 nominees has a usable email address', $why);
    }
}
