<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\AwardsController;
use AfricaGates\Services\AwardOverview;
use AfricaGates\Services\CyclePolicy;
use AfricaGates\Services\RuleEngine;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * /awards/{slug}, rebuilt on AwardsPage.dc.html — and the facts it states, which are
 * worked out by AwardOverview rather than written into the page.
 */
final class AwardPageTest extends TestCase
{
    private int $programme = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));
        // A distinct cache key per run: the controller remembers a programme for half an
        // hour, and the suite is one process.
        DB::table('gates_cache')->delete();
        $this->programme = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'kcea', 'title' => 'Creative Economy Awards', 'is_active' => 1,
            'description' => 'Celebrating the creative economy.',
            'cover_path' => '/uploads/awards/kcea.jpg', 'terms' => "Rule one.\nRule two.",
        ]);
        foreach ([2019, 2024] as $y) {
            DB::table('gates_award_cycles')->insert(['programme_id' => $this->programme, 'year' => $y,
                'status' => 'archived', 'voting_close' => $y . '-06-01 00:00:00', 'results_date' => $y . '-07-01 00:00:00']);
        }
        $cy = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programme, 'year' => 2026, 'edition_label' => '11th Edition',
            'status' => 'voting',
            'nominations_open' => '2026-08-01 00:00:00', 'nominations_close' => '2026-09-01 00:00:00',
            'voting_open' => '2026-09-15 00:00:00', 'voting_close' => '2026-10-31 23:30:00',
            'results_date' => '2026-12-06 12:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['cycle_id' => $cy, 'slug' => 'music', 'title' => 'Musician of the Year']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(string $query = ''): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/awards/kcea' . $query);
        if ($query !== '') {
            parse_str(ltrim($query, '?'), $q);
            $req = $req->withQueryParams($q);
        }
        $res = $b->build()->get(AwardsController::class)->programme($req, new Response(), ['p' => 'kcea']);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    public function test_the_overview_renders_the_comps_sections(): void
    {
        $h = $this->page();

        foreach (['class="ag-subnav"', 'id="h-ed"', 'class="aw-steps"', 'id="h-cats"', 'id="h-about"'] as $m) {
            $this->assertStringContainsString($m, $h, $m);
        }
        $this->assertMatchesRegularExpression('~id="h-ed"><span>Edition</span>\s*11th Edition · 2026~', $h);
        $this->assertStringContainsString('aria-current="step"', $h, 'no step is marked as now');
        $this->assertSame(1, preg_match_all('~<main\b~', $h));
    }

    /** The award's own picture, never the stock photo the old page hotlinked for every award. */
    public function test_the_cover_is_the_awards_own(): void
    {
        $h = $this->page();
        $this->assertStringContainsString('src="/uploads/awards/kcea.jpg"', $h);
        $this->assertStringNotContainsString('images.unsplash.com/photo', $h, 'a stock photo is not this award');

        DB::table('gates_award_programmes')->where('id', $this->programme)->update(['cover_path' => null]);
        DB::table('gates_cache')->delete();
        $this->assertStringNotContainsString('aw-hero__art', $this->page(), 'no cover must mean no picture, not a stand-in');
    }

    public function test_views_are_urls_and_terms_is_offered_only_when_written(): void
    {
        $this->assertStringContainsString('href="?tab=terms"', $this->page());
        $terms = $this->page('?tab=terms');
        $this->assertStringContainsString('Rule one.<br />', $terms);
        $this->assertMatchesRegularExpression('~href="\?tab=terms" aria-current="page"~', $terms);

        DB::table('gates_award_programmes')->where('id', $this->programme)->update(['terms' => null]);
        DB::table('gates_cache')->delete();
        $h = $this->page('?tab=terms');
        $this->assertStringNotContainsString('?tab=terms', $h, 'a Terms view over nothing is a promise the award has not made');
        $this->assertStringContainsString('id="h-ed"', $h, 'an unknown view falls back to the overview');
    }

    /** The scoring split is the scorer's, for this programme — never a typed figure. */
    public function test_the_scoring_fact_moves_with_the_rules(): void
    {
        $this->assertStringContainsString('45% public · 55% panel', $this->page());

        (new RuleEngine())->merge('programme', $this->programme, ['community_weight' => 0.3, 'judge_weight' => 0.7]);
        DB::table('gates_cache')->delete();
        $this->assertStringContainsString('30% public · 70% panel', $this->page());
    }

    public function test_since_is_counted_from_the_editions(): void
    {
        $this->assertStringContainsString('2019 · 3 editions', $this->page());
    }

    public function test_the_timeline_marks_one_step_now(): void
    {
        $cycle = (array) DB::table('gates_award_cycles')->where('year', 2026)->first();
        $t = AwardOverview::timeline($cycle, CyclePolicy::stateFor((object) $cycle));
        $this->assertSame(['done', 'now', 'next', 'next'], array_column($t, 'state'));
        $this->assertSame('Closed', $t[0]['sub']);

        // Between the windows nothing is "now": nominations are done and voting is next.
        $short = AwardOverview::timeline($cycle, ['phase' => 'shortlisting']);
        $this->assertSame(['done', 'next', 'next', 'next'], array_column($short, 'state'));

        $this->assertSame('/nominate/kcea', AwardOverview::action('kcea', ['phase' => 'nominations'])['href'],
            'a nomination must go to the award\'s own page, not the generic one');
        $this->assertNull(AwardOverview::action('kcea', ['phase' => 'upcoming']));
    }

    /**
     * One deadline, one date. The phase line formatted the stored UTC instant while every
     * other date is shown in the display zone, so a close stored after 23:00 UTC printed a
     * day early beside a step saying the right day.
     */
    public function test_the_phase_line_and_the_step_agree_on_the_date(): void
    {
        $cycle = (object) DB::table('gates_award_cycles')->where('year', 2026)->first();
        $state = CyclePolicy::stateFor($cycle);
        $steps = AwardOverview::timeline((array) $cycle, $state);

        // 31 Oct 23:30 UTC is 1 Nov in Lagos.
        $this->assertSame('Until 1 Nov', $steps[1]['sub']);
        $this->assertStringContainsString('1 Nov 2026', $state['detail']);
        $this->assertStringStartsWith('Voting closes', $state['detail']);
    }
}
