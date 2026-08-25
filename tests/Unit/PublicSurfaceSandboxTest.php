<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\SitemapService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Nothing from the rehearsal appears on the public site.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHERE CONTAINMENT STOPPED WORKING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see DemoSeeder} puts the sandbox in its own inactive programme, and every public
 * AWARDS reader already required `is_active = 1` — the programme list, the slug lookup,
 * and the category search in the activity feed all do.
 *
 * Three readers did not, because they never touch the programme at all. The activity
 * feed's nominee search and result search start at `gates_nominees` and reach only as
 * far as the category; the phase timeline starts at `gates_cycle_transitions`. All three
 * are public, all three are unauthenticated, and the first of them needed no promotion,
 * no cron run and no scheduler to leak: a sandbox built this morning put "DEMO — Test
 * Nominee" into the site's search the moment it was seeded.
 *
 * The sitemap was the same shape with a longer tail: it published /vote/demo-sandbox/…
 * to search engines, where the slug lookup that a crawler follows then refuses because
 * the programme is inactive. Indexed URLs that resolve to nothing.
 */
final class PublicSurfaceSandboxTest extends TestCase
{
    private ActivityFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new ActivityFeedService();

        $this->build(DemoSeeder::PROGRAMME_SLUG, 0, DemoSeeder::PREFIX . 'Kigali Signal', 'winner');
        $this->build('real-awards', 1, 'Kigali Signal Trust', 'winner');
    }

    private function build(string $slug, int $active, string $nominee, string $status): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => $slug, 'is_active' => $active,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'results', 'edition_label' => $slug,
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cid, 'slug' => 'cat-' . $slug, 'title' => 'Signal', 'sort_order' => 1,
        ]);
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => $nominee, 'status' => $status,
            'nominated_at' => '2026-02-01 10:00:00', 'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => $cid, 'from_status' => 'judging', 'to_status' => 'results',
            'reason' => 'auto: date window', 'actor' => 'cron',
            'observed_at' => '2026-02-02 10:00:00',
        ]);
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        return array_column($this->feed->search($query, 50)['items'] ?? [], 'title');
    }

    // ════════════════════════════════════════════════════════════════════════

    /**
     * The one that needed nothing to happen. A seeded sandbox is immediately
     * searchable by name on a public page.
     */
    public function test_a_demo_nominee_is_not_searchable(): void
    {
        $titles = $this->titles('Kigali');

        $this->assertContains('Kigali Signal Trust', $titles, 'the real nominee stopped being findable');
        $this->assertNotContains(DemoSeeder::PREFIX . 'Kigali Signal', $titles);
    }

    /** And a rehearsal winner is not a result. */
    public function test_a_demo_winner_is_not_in_the_results_search(): void
    {
        $items = $this->feed->search('Signal', 50)['items'] ?? [];
        $results = array_column(array_filter($items, static fn (array $i): bool => $i['kind'] === 'result'), 'title');

        $this->assertContains('Kigali Signal Trust', $results);
        $this->assertNotContains(DemoSeeder::PREFIX . 'Kigali Signal', $results);
    }

    /**
     * Nor is the rehearsal's own phase change an event on the platform's timeline.
     * The materialiser writes a transition row for the sandbox exactly as it does for a
     * real cycle, because from its side there is no difference.
     */
    public function test_a_sandbox_phase_change_is_not_on_the_timeline(): void
    {
        $items  = $this->feed->search(DemoSeeder::PROGRAMME_SLUG, 50)['items'] ?? [];
        $phases = array_filter($items, static fn (array $i): bool => $i['kind'] === 'phase');

        $this->assertSame([], array_values($phases));
    }

    /** The sitemap does not hand a crawler a URL the site will refuse. */
    public function test_the_sitemap_omits_the_sandbox(): void
    {
        $xml = (new SitemapService(null))->section('nominees', 'https://afg.example');

        $this->assertStringContainsString('/vote/real-awards/', $xml);
        $this->assertStringNotContainsString('/vote/' . DemoSeeder::PROGRAMME_SLUG . '/', $xml);
    }

    /**
     * A nominee whose category row is gone is still listed.
     *
     * The filter reaches the programme through a LEFT JOIN, so `cy.programme_id` is NULL
     * for these — and in SQL `NULL != 5` is NULL, not true. A bare `!=` would drop every
     * orphaned row from a PUBLIC listing in order to exclude a sandbox it is not in:
     * a worse bug than the one being fixed, and an invisible one. The sandbox stays
     * present here, because with it deleted the filter is inert and proves nothing.
     */
    public function test_a_nominee_with_no_category_is_still_listed(): void
    {
        $this->assertGreaterThan(0, DemoSeeder::programmeId(), 'the filter must be live for this');

        DB::table('gates_nominees')->insert([
            'category_id' => 999999, 'name' => 'Orphaned Entry', 'status' => 'approved',
            'nominated_at' => '2026-02-01 10:00:00', 'vote_count' => 0, 'organic_vote_count' => 0,
        ]);

        $this->assertContains('Orphaned Entry', $this->titles('Orphaned'),
            'a nominee with no category was dropped by the sandbox filter');
    }
}
