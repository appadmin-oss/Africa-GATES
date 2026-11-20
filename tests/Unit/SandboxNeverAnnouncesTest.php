<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityService;
use AfricaGates\Services\CycleAnnouncer;
use AfricaGates\Services\DemoSeeder;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The rehearsal does not crown anybody in public.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE PLACE CONTAINMENT DOES NOT REACH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see DemoSeeder} keeps the sandbox out of every real result by giving it its own
 * programme, and that works because tallies, ranks and cuts are computed per category.
 *
 * A winner announcement is not computed per category. It is a row in `gates_activity`,
 * and {@see CommunityService::activityFeed()} reads that table on nothing but
 * `is_public = 1` — a global broadcast to every visitor of the site.
 *
 * And it is reachable without anybody deciding anything. The practice cycle exists so a
 * real judge can sit a practice ballot; two of them completing a scorecard on the same
 * practice nominee meets the default quorum of two, the cycle's own results_date arrives,
 * and {@see \AfricaGates\Services\CycleMaterialiser::promoteWinners()} crowns a nominee
 * whose name begins "DEMO —" on the front page of a live awards platform.
 */
final class SandboxNeverAnnouncesTest extends TestCase
{
    private int $demoNominee = 0;
    private int $realNominee = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->demoNominee = $this->nomineeUnder(DemoSeeder::PROGRAMME_SLUG, DemoSeeder::PREFIX . 'Test Nominee');
        $this->realNominee = $this->nomineeUnder('real-awards', 'Ada Obi');
    }

    private function nomineeUnder(string $slug, string $name): int
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => $slug, 'is_active' => $slug === 'real-awards' ? 1 : 0,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'results',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cid, 'slug' => 'c-' . $slug, 'title' => 'Category', 'sort_order' => 1,
        ]);

        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => $name, 'status' => 'winner',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    /** @return array<string,mixed> */
    private function row(int $nomineeId): array
    {
        $r = DB::table('gates_activity')->where('target_type', 'nominee')
            ->where('target_id', $nomineeId)->first();
        $this->assertNotNull($r, 'the result was not recorded at all');

        return (array) $r;
    }

    // ════════════════════════════════════════════════════════════════════════

    /**
     * The result is written down — recording is correctness — and it is not public.
     *
     * Both halves matter. A sandbox winner that vanished entirely would make the
     * rehearsal lie about what promotion does, which is the one thing the sandbox exists
     * to show honestly.
     */
    public function test_a_sandbox_winner_is_recorded_but_never_published(): void
    {
        CycleAnnouncer::record($this->demoNominee, 'winner');

        $row = $this->row($this->demoNominee);
        $this->assertSame(0, (int) $row['is_public'], 'a DEMO nominee reached the public activity feed');

        $meta = json_decode((string) $row['meta'], true);
        $this->assertFalse($meta['announced'], 'the row claims it was announced');
        $this->assertTrue($meta['sandbox'], 'nothing on the row explains why it is not public');
    }

    /** And it is genuinely absent from the feed a visitor reads. */
    public function test_the_public_feed_does_not_carry_it(): void
    {
        CycleAnnouncer::record($this->demoNominee, 'winner');
        CycleAnnouncer::record($this->realNominee, 'winner');

        $labels = array_column((new CommunityService(new \AfricaGates\Services\SpamService()))->activityFeed(50), 'target_label');

        $this->assertContains('Ada Obi', $labels, 'the real winner stopped being announced');
        $this->assertNotContains(DemoSeeder::PREFIX . 'Test Nominee', $labels);
    }

    /** A real winner still announces — the gate is the programme, not the caller. */
    public function test_a_real_winner_is_still_published(): void
    {
        CycleAnnouncer::record($this->realNominee, 'winner');

        $row = $this->row($this->realNominee);
        $this->assertSame(1, (int) $row['is_public']);
        $this->assertFalse(json_decode((string) $row['meta'], true)['sandbox']);
    }

    /**
     * The stale-backlog suppression still wins on a real cycle. The sandbox reuses that
     * gate rather than adding a second one, so this pins that it did not replace it.
     */
    public function test_a_stale_real_result_is_still_suppressed(): void
    {
        CycleAnnouncer::record($this->realNominee, 'winner', false);

        $row = $this->row($this->realNominee);
        $this->assertSame(0, (int) $row['is_public']);
        $this->assertFalse(json_decode((string) $row['meta'], true)['sandbox'],
            'a stale real result was mislabelled as a rehearsal');
    }
}
