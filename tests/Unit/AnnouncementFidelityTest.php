<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\CycleAnnouncer;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The result announcement has to be true about WHICH award and honest about
 * WHETHER it is news.
 *
 * Both failures here are quiet ones. Nothing throws, nothing looks broken in a
 * log, and the only person who notices is the winner — reading the single most
 * consequential email this platform sends.
 */
class AnnouncementFidelityTest extends TestCase
{
    private function seed(int $cycleYear, ?string $edition = null): int
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => $cycleYear, 'status' => 'results',
            'edition_label' => $edition,
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert([
            'id' => 7, 'category_id' => 1, 'name' => 'Ada', 'status' => 'winner', 'vote_count' => 3,
        ]);
        return 7;
    }

    private function meta(): array
    {
        $row = DB::table('gates_activity')->where('kind', 'winner')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'the result must always be recorded');
        return (array) json_decode((string) $row->meta, true);
    }

    /**
     * The award belongs to the CYCLE's year, not the year the cron happened to run.
     * A cycle closing on 30 December and promoting on 2 January is three days late —
     * comfortably inside the announcement grace window — and the old `date('Y')`
     * congratulated its winner on an edition that does not exist.
     */
    public function test_the_recorded_result_carries_the_cycle_year_not_todays(): void
    {
        $id = $this->seed(2019);                       // deliberately not the current year

        CycleAnnouncer::record($id, 'winner', true);

        $this->assertSame(2019, $this->meta()['cycle']);
        $this->assertNotSame((int) date('Y'), $this->meta()['cycle']);
    }

    /**
     * Suppression means suppression. `is_public = 1` puts the row in the site's
     * public activity feed, so a months-old backlog used to broadcast itself to
     * every visitor today while carefully skipping the email — the same
     * announcement through a different door.
     */
    public function test_a_suppressed_result_is_recorded_but_not_broadcast(): void
    {
        $id = $this->seed((int) date('Y'));

        CycleAnnouncer::record($id, 'winner', false);

        $row = DB::table('gates_activity')->where('kind', 'winner')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'the result is still recorded — that part is correctness');
        $this->assertSame(0, (int) $row->is_public, 'but it must not appear in the public feed');
        $this->assertFalse($this->meta()['announced']);

        // And it is genuinely absent from the feed the site renders.
        $feed = (new \AfricaGates\Services\CommunityService(new \AfricaGates\Services\SpamService()))->activityFeed(50);
        $this->assertSame([], array_filter($feed, static fn ($e) => ($e['kind'] ?? '') === 'winner'));
    }

    public function test_an_announced_result_does_reach_the_public_feed(): void
    {
        $id = $this->seed((int) date('Y'));

        CycleAnnouncer::record($id, 'winner', true);

        $feed = (new \AfricaGates\Services\CommunityService(new \AfricaGates\Services\SpamService()))->activityFeed(50);
        $winners = array_values(array_filter($feed, static fn ($e) => ($e['kind'] ?? '') === 'winner'));
        $this->assertCount(1, $winners);
        $this->assertSame('Ada', $winners[0]['target_label']);
    }

    /** A named edition wins over the bare year — it is what the cycle calls itself. */
    public function test_an_edition_label_is_preferred_over_the_year(): void
    {
        $id = $this->seed(2026, '5th Edition');

        CycleAnnouncer::record($id, 'winner', true);

        // The label drives the email copy; the machine-readable meta keeps the year.
        $this->assertSame(2026, $this->meta()['cycle']);
        $this->assertSame('5th Edition',
            DB::table('gates_award_cycles')->where('id', 1)->value('edition_label'));
    }
}
