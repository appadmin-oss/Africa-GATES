<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\FlierService;
use AfricaGates\Services\NomineeBroadcast;
use AfricaGates\Services\SitemapService;
use AfricaGates\Support\Slug;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
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
 *
 * ── AND THE ONE THIS FILE ITSELF MISSED ──────────────────────────────────────
 *
 * The sitemap assertion above is written as though a crawler following
 * /vote/demo-sandbox/{id}-{name} would be refused. It would not have been. That claim
 * was only ever true of the PROGRAMME page — `AwardService::getProgrammeBySlug()`
 * requires `is_active = 1`, so /vote/demo-sandbox bounced to the hub. The NOMINEE page
 * underneath it is a different reader, and it required no such thing: it starts at
 * `gates_nominees`, joins its way up to the programme for the slug alone, and filters
 * only on nominee status. DemoSeeder seeds its nominees 'approved' in a cycle at
 * 'voting', so every filter on that query waved them through.
 *
 * That reader is THE BALLOT. A sandbox nominee had a live, votable page at its direct
 * URL, and /vote/{id} would hand that URL to anyone who guessed a number — so a real
 * voter's OTP could be spent on a row that exists to be deleted. The two tests at the
 * bottom of this file are the ones that would have caught it.
 */
final class PublicSurfaceSandboxTest extends TestCase
{
    private ActivityFeedService $feed;
    private int $demoNomineeId = 0;
    private int $realNomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new ActivityFeedService();

        $this->demoNomineeId = $this->build(DemoSeeder::PROGRAMME_SLUG, 0, DemoSeeder::PREFIX . 'Kigali Signal', 'winner');
        $this->realNomineeId = $this->build('real-awards', 1, 'Kigali Signal Trust', 'winner');
    }

    private function build(string $slug, int $active, string $nominee, string $status): int
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
        $nid = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => $nominee, 'status' => $status,
            'nominated_at' => '2026-02-01 10:00:00', 'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => $cid, 'from_status' => 'judging', 'to_status' => 'results',
            'reason' => 'auto: date window', 'actor' => 'cron',
            'observed_at' => '2026-02-02 10:00:00',
        ]);

        return $nid;
    }

    /** The real routing stack, so these two assertions are about the URL and not a method. */
    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        return $app;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
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

    // ════════════════════════════════════════════════════════════════════════
    //  THE BALLOT — see the note at the top of this file
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The page a share link actually opens.
     *
     * Asserted both ways on purpose. A guard that 404s the sandbox by 404ing every
     * nominee would pass a one-sided test and take the whole ballot down with it —
     * which is a far worse bug than the one being fixed, and one nothing else here
     * would catch.
     */
    public function test_a_demo_nominee_has_no_ballot_page(): void
    {
        $real = $this->get('/vote/real-awards/' . Slug::idSegment($this->realNomineeId, 'Kigali Signal Trust'));
        $this->assertNotSame(404, $real->getStatusCode(), 'the guard took a real nominee down with it');

        $demo = $this->get('/vote/' . DemoSeeder::PROGRAMME_SLUG . '/'
            . Slug::idSegment($this->demoNomineeId, DemoSeeder::PREFIX . 'Kigali Signal'));
        $this->assertSame(404, $demo->getStatusCode(), 'a sandbox nominee still has a votable page');
    }

    /**
     * And the legacy /vote/{id} link, which needs no slug at all — so it hands out the
     * canonical URL of whatever row that id names. Guessing a number was the whole
     * discovery mechanism the direct-URL leak needed.
     */
    public function test_the_legacy_id_link_does_not_resolve_a_sandbox_nominee(): void
    {
        $real = $this->get('/vote/' . $this->realNomineeId);
        $this->assertSame(302, $real->getStatusCode());
        $this->assertStringContainsString('/vote/real-awards/', $real->getHeaderLine('Location'),
            'a real nominee stopped being redirected to their page');

        $demo = $this->get('/vote/' . $this->demoNomineeId);
        $this->assertSame('/vote', $demo->getHeaderLine('Location'),
            'the legacy link still hands out the sandbox nominee\'s URL');
    }

    /**
     * The two pages that hang off the ballot. Both resolve a nominee by the same shape of
     * query the ballot used, and "no public ballot" has to mean one thing across all three
     * — otherwise closing the ballot just moves the sandbox to a neighbouring URL.
     */
    public function test_the_messages_and_supporters_pages_are_not_open_on_a_sandbox_nominee(): void
    {
        $realSeg = Slug::idSegment($this->realNomineeId, 'Kigali Signal Trust');
        $demoSeg = Slug::idSegment($this->demoNomineeId, DemoSeeder::PREFIX . 'Kigali Signal');

        foreach (['messages', 'supporters'] as $page) {
            $this->assertNotSame(404, $this->get("/vote/real-awards/$realSeg/$page")->getStatusCode(),
                "the guard took the real nominee's $page page down with it");
            $this->assertSame(404, $this->get('/vote/' . DemoSeeder::PROGRAMME_SLUG . "/$demoSeg/$page")->getStatusCode(),
                "a sandbox nominee still has a $page page");
        }
    }

    /**
     * The share card — the one surface whose entire job is to be reposted. A crawler
     * handed the URL rendered a real PNG of a nominee whose name begins "DEMO —".
     */
    public function test_the_share_card_does_not_render_a_sandbox_nominee(): void
    {
        $flier = new FlierService();

        $this->assertNotNull($flier->forNominee($this->realNomineeId),
            'the guard stopped a real nominee having a share card');
        $this->assertNull($flier->forNominee($this->demoNomineeId),
            'the sandbox still has a share card');
    }

    /**
     * And the one that reaches outward rather than being reached.
     *
     * DemoSeeder seeds the rehearsal at status 'voting' with voting_close twenty days
     * out — exactly the shape NomineeBroadcast::cycles() selects — so the broadcast
     * mailed the sandbox's nominees at @demo.invalid. That domain does not resolve, so
     * each one is a hard bounce charged against the sending domain's reputation.
     */
    public function test_the_nominee_broadcast_does_not_pick_up_the_rehearsal(): void
    {
        DB::table('gates_award_cycles')->update([
            'status' => 'voting', 'voting_close' => '2099-01-01 00:00:00',
        ]);

        $labels = array_map(
            static fn (object $c): string => (string) $c->programme_title,
            (new NomineeBroadcast())->cycles()
        );

        // build() sets each programme's title to its slug, so these are the titles.
        $this->assertContains('real-awards', $labels, 'a real cycle stopped being broadcast');
        $this->assertNotContains(DemoSeeder::PROGRAMME_SLUG, $labels, 'the rehearsal is still being mailed');
    }
}
