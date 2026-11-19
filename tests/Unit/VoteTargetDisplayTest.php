<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AwardsFront;
use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnPool as Pool;
use AfricaGates\Services\RuleEngine;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The vote target, where people vote (owner, 19 Nov 2026: "the votes with targets actually
 * display and count down to the targets").
 *
 * On the target pool every approved nominee has the same vote target and the pool is shared
 * only by those who reach it (CommunityReturnPool). The target was printed on the award page
 * as an edition-wide count and nowhere a supporter actually votes: the nominee's own page and
 * the vote list said nothing about it. Now both do, and the figure counts down from the same
 * cached tallies poll the race uses.
 *
 * What these try to break: the page counting `vote_count` (which carries grants and flagged
 * votes the target never counts); "to go" arithmetic off by one at the line; a target of 0
 * drawn as reached; a settled target moving, or vanishing once vote sales are switched off;
 * the poll and the page disagreeing; a target per nominee costing a query per row; and the
 * sandbox reaching a public reader keyed on a cycle.
 *
 * Votes are written as WEIGHTED rows, deliberately: a contributed order mints one row of its
 * whole weight (PaidVoteService::mint()), and CommunityReturnPool::votes() sums weight — the
 * shape is the subject, not one ballot row per voter.
 */
final class VoteTargetDisplayTest extends TestCase
{
    private const SLUG = 'target-award';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 61, 'slug' => self::SLUG, 'title' => 'Target Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 61, 'programme_id' => 61, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(), 'voting_close' => Carbon::now()->addDays(5)->toDateTimeString()]);
        DB::table('gates_award_categories')->insert(['id' => 611, 'cycle_id' => 61, 'slug' => 'music', 'title' => 'Music']);
        foreach ([6101 => 'Ada Obi', 6102 => 'Bola Ade', 6103 => 'Chuka Eze'] as $id => $n) {
            DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => 611, 'name' => $n, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        }
        $this->settings(['paid_voting_enabled' => '1', 'vote_price_naira' => '100']);
        // ₦30,000 at ₦100 a vote is 300 votes; across three approved nominees, 100 each.
        $r = Milestone::saveEditionRules(61, ['model' => 'pool', 'full_pct' => 20, 'milestone_naira' => 30000]);
        $this->assertTrue($r['ok'], $r['message']);
    }

    /** @param array<string,string> $kv */
    private function settings(array $kv): void
    {
        foreach ($kv as $k => $v) DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
    }

    private function votes(int $nominee, int $n, string $type = 'paid', int $fraud = 0): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => 611, 'voter_email_hash' => 'paidvote:' . bin2hex(random_bytes(6)),
            'vote_type' => $type, 'weight' => $n, 'fraud_flag' => $fraud]);
        // The denormalised total moves with every row, flagged and granted included: that is
        // exactly why the target must not be read from it.
        DB::table('gates_nominees')->where('id', $nominee)->increment('vote_count', $n);
    }

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

    private function get(string $path): string
    {
        DB::table('gates_cache')->delete();   // the poll and the hub are cached; each read here is fresh
        $res = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        if ($res->getStatusCode() === 302) {
            $res = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $res->getHeaderLine('Location')));
        }
        $this->assertSame(200, $res->getStatusCode(), $path);
        return (string) $res->getBody();
    }

    private function nomineePage(int $id = 6101): string
    {
        return $this->get('/vote/' . self::SLUG . '/' . $id . '-x');
    }

    /** The target section of a nominee page, or '' when none is drawn. */
    private function goal(string $html): string
    {
        return preg_match('~<section class="vn-goal[^"]*".*?</section>~s', $html, $m) ? $m[0] : '';
    }

    private function words(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    private function settle(): void
    {
        DB::table('gates_award_cycles')->where('id', 61)->update(['status' => 'results', 'voting_close' => Carbon::now()->subDays(10)->toDateTimeString()]);
        $r = Pool::settle(61, 1);
        $this->assertTrue($r['ok'], $r['message']);
    }

    // ── the nominee's own page ───────────────────────────────────────────────

    public function test_the_nominee_page_counts_the_votes_the_target_counts_not_the_tally(): void
    {
        $this->votes(6101, 40);
        $this->votes(6101, 500, 'bonus');        // a grant: on the tally, never towards the target
        $this->votes(6101, 30, 'paid', 1);       // fraud-flagged: likewise
        $this->assertSame(570, (int) DB::table('gates_nominees')->where('id', 6101)->value('vote_count'));

        $g = $this->goal($this->nomineePage());
        $this->assertNotSame('', $g, 'the page draws the target');
        $w = $this->words($g);
        $this->assertStringContainsString('Vote target: 100 votes', $w);
        $this->assertStringContainsString('60 votes to go', $w);
        $this->assertStringContainsString('40 of 100 votes', $w);
        $this->assertStringContainsString('style="width:40%"', $g);
        $this->assertStringNotContainsString('570', $g, 'the denormalised tally is not the count towards the target');
        // And the page says why the two figures differ, rather than leaving them to contradict.
        $this->assertStringContainsString('votes that count towards the target', $w);
        // Open and unsettled: it counts down from the tallies poll.
        $this->assertStringContainsString('data-tallies="/vote/' . self::SLUG . '/tallies"', $g);
        $this->assertStringContainsString('data-cycle="61"', $g);
    }

    public function test_the_line_itself_one_short_and_reached(): void
    {
        $this->votes(6101, 99);
        $w = $this->words($this->goal($this->nomineePage()));
        $this->assertStringContainsString('1 vote to go', $w);
        $this->assertStringNotContainsString('Target reached', $w);

        $this->votes(6101, 1);
        $g = $this->goal($this->nomineePage());
        $this->assertStringContainsString('Target reached', $this->words($g));
        $this->assertStringNotContainsString('to go', $this->words($g));
        $this->assertStringContainsString('vn-goal is-reached', $g);
        $this->assertStringContainsString('style="width:100%"', $g);

        $this->votes(6101, 250);                 // past it: never a negative "to go", never past 100%
        $s = Pool::standingIn(Pool::progress(61), 6101);
        $this->assertSame(['target' => 100, 'votes' => 350, 'to_go' => 0, 'reached' => true, 'pct' => 100, 'settled' => false], $s);
    }

    public function test_no_target_set_draws_nothing_and_is_never_reached(): void
    {
        // A target of nothing is "not set": the milestone cleared on the edition's own rules.
        (new RuleEngine())->merge('cycle', 61, ['community_return_milestone_naira' => 0]);
        $this->assertSame(0, Pool::progress(61)['target']);
        $this->assertNull(Pool::standingIn(Pool::progress(61), 6101), 'a target of 0 is not "reached"');
        $this->assertNull(Pool::forPublic(61));
        $this->assertSame('', $this->goal($this->nomineePage()));
        $this->assertStringNotContainsString('vp-row__goal', $this->get('/vote/' . self::SLUG));
        // The award page drew "Target today: 0 votes each" beside "0 of 3 have reached it".
        $this->assertNull(AwardsFront::detail(self::SLUG)['pool'] ?? null);
    }

    public function test_an_edition_not_on_the_pool_draws_no_target(): void
    {
        (new RuleEngine())->merge('cycle', 61, ['community_return_model' => 'threshold']);
        $this->assertNull(Pool::forPublic(61));
        $this->assertSame('', $this->goal($this->nomineePage()));
    }

    public function test_a_settled_target_is_shown_as_settled_and_stops_counting(): void
    {
        $this->votes(6101, 120);
        $this->votes(6102, 70);
        $this->settle();
        // Approved after settling: today's target would be 75, the settled one stays 100.
        DB::table('gates_nominees')->insert(['id' => 6104, 'category_id' => 611, 'name' => 'Dayo Late', 'status' => 'approved']);

        $g = $this->goal($this->nomineePage(6102));
        $w = $this->words($g);
        $this->assertStringContainsString('Vote target, settled: 100 votes', $w);
        $this->assertStringContainsString('Finished 30 votes short', $w);
        $this->assertStringNotContainsString('to go', $w);
        $this->assertStringNotContainsString('data-tallies', $g, 'a settled target does not count down');
        $this->assertStringContainsString('Target reached', $this->words($this->goal($this->nomineePage(6101))));
    }

    public function test_a_settled_target_survives_vote_sales_being_switched_off(): void
    {
        $this->votes(6101, 120);
        $this->settle();
        $this->settings(['paid_voting_enabled' => '']);

        // A settlement is a record of who was paid, not a promise: it stays on the award page
        // and on the nominee's own page after the operator stops selling votes.
        $this->assertNotNull(Pool::forPublic(61));
        $this->assertSame(100, AwardsFront::detail(self::SLUG)['pool']['target'] ?? null);
        $this->assertStringContainsString('Vote target, settled: 100 votes', $this->words($this->goal($this->nomineePage())));
    }

    public function test_an_open_target_with_vote_sales_off_is_a_promise_of_nothing(): void
    {
        $this->settings(['paid_voting_enabled' => '']);
        $this->assertNull(Pool::forPublic(61));
        $this->assertSame('', $this->goal($this->nomineePage()));
    }

    // ── the vote list ────────────────────────────────────────────────────────

    public function test_every_row_of_the_vote_list_says_how_many_to_go(): void
    {
        $this->votes(6101, 100);
        $this->votes(6102, 99);
        $this->votes(6102, 400, 'bonus');
        $html = $this->get('/vote/' . self::SLUG);

        $row = function (int $id) use ($html): string {
            $this->assertSame(1, preg_match('~<a class="vp-row" href="[^"]*" data-nominee="' . $id . '">(.*?)</a>~s', $html, $m), "row $id");
            return $this->words($m[1]);
        };
        $this->assertStringContainsString('Target reached', $row(6101));
        $this->assertStringContainsString('1 to go', $row(6102), 'the grant is on the tally and not towards the target');
        $this->assertStringContainsString('100 to go', $row(6103));
        $this->assertStringContainsString('Vote target: 100 votes for every nominee', $this->words($html));
        // The words the poll writes, from the template and never typed in the script.
        $this->assertStringContainsString('data-w-goal="%n% to go"', $html);
        $this->assertStringContainsString('data-w-reached="Target reached"', $html);
    }

    public function test_the_vote_list_asks_for_the_target_once_not_once_per_row(): void
    {
        // Constant in the field: the target costs the same queries for three rows and for
        // twelve. A per-row progress() would add a dozen queries per nominee.
        $count = function (): int {
            DB::table('gates_cache')->delete();
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/vote/' . self::SLUG));
            $n = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();
            return $n;
        };
        // Warm the per-process schema probes (SchemaHas and friends) both ways first, or the
        // first measured render pays for them and the comparison measures the warm-up.
        $count();
        $this->settings(['paid_voting_enabled' => '']);
        $count();
        $this->settings(['paid_voting_enabled' => '1']);
        $on3 = $count();
        $this->settings(['paid_voting_enabled' => '']);   // the gate shut: no target work at all
        $off3 = $count();
        $this->settings(['paid_voting_enabled' => '1']);
        for ($i = 4; $i <= 12; $i++) {
            DB::table('gates_nominees')->insert(['id' => 6100 + $i, 'category_id' => 611, 'name' => 'Nominee ' . $i, 'status' => 'approved', 'vote_count' => 0]);
        }
        $on12 = $count();
        $this->settings(['paid_voting_enabled' => '']);
        $off12 = $count();

        $this->assertSame($on3 - $off3, $on12 - $off12, 'the target added queries per nominee');

        // And the resolver itself: one progress(), then memory.
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $this->settings(['paid_voting_enabled' => '1']);
        DB::connection()->flushQueryLog();
        $p = Pool::forPublic(61);
        $base = count(DB::connection()->getQueryLog());
        foreach (array_column($p['rows'], 'id') as $id) Pool::standingIn($p, (int) $id);
        $this->assertSame($base, count(DB::connection()->getQueryLog()), 'standingIn reads memory only');
        DB::connection()->disableQueryLog();
    }

    // ── the live poll ────────────────────────────────────────────────────────

    public function test_the_poll_carries_the_same_figures_the_page_drew(): void
    {
        $this->votes(6101, 40);
        $this->votes(6102, 100);
        $this->votes(6101, 300, 'bonus');
        $d = json_decode($this->get('/vote/' . self::SLUG . '/tallies'), true);

        $this->assertTrue($d['ok']);
        $t = $d['target'];
        $this->assertSame(61, $t['cycle_id']);
        $this->assertSame(100, $t['target']);
        $this->assertFalse($t['settled']);
        $this->assertIsList($t['nominees'], 'a list, so the script never meets a JSON object keyed 0…n by accident');
        $by = array_column($t['nominees'], null, 'id');
        $this->assertSame(['id' => 6101, 'votes' => 40, 'to_go' => 60, 'reached' => false, 'pct' => 40], $by[6101]);
        $this->assertSame(['id' => 6102, 'votes' => 100, 'to_go' => 0, 'reached' => true, 'pct' => 100], $by[6102]);
        $this->assertSame(100, $by[6103]['to_go']);

        // The page and the poll, side by side: one resolver, so they cannot disagree.
        $page = Pool::standingIn(Pool::forPublic(61), 6101);
        $this->assertSame($page['to_go'], $by[6101]['to_go']);

        // The poll counts down: two more contributed votes, and the next read says so.
        $this->votes(6101, 2);
        $d = json_decode($this->get('/vote/' . self::SLUG . '/tallies'), true);
        $this->assertSame(58, array_column($d['target']['nominees'], null, 'id')[6101]['to_go']);

        // No target, no target block: the script then leaves the server's figure alone.
        (new RuleEngine())->merge('cycle', 61, ['community_return_milestone_naira' => 0]);
        $this->assertNull(json_decode($this->get('/vote/' . self::SLUG . '/tallies'), true)['target']);
    }

    public function test_the_script_counts_down_through_agpage_and_degrades_to_the_server_figure(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/vote.js');
        $this->assertSame(1, preg_match('~function goalBox\(box\) \{(.*?)\n  \}\n~s', $js, $m), 'the nominee page part of vote.js');
        $this->assertStringContainsString('pg.every(poll, 10000)', $m[1], 'the timer goes through agPage, so a swapped page stops polling');
        $this->assertStringNotContainsString('setInterval', $m[1]);
        $this->assertStringContainsString("box.getAttribute('data-tallies')", $m[1], 'polls only where the server asked it to');
        $this->assertStringContainsString("$$('[data-vote-target]').forEach(goalBox)", $js);
        // The words come from the page; the script types none.
        $this->assertStringNotContainsString('to go', preg_replace('~/\*.*?\*/~s', '', $m[1]));
    }

    // ── the sandbox ──────────────────────────────────────────────────────────

    public function test_the_sandbox_has_no_public_target(): void
    {
        $this->votes(6101, 40);
        $this->assertNotNull(Pool::forPublic(61));
        // The rehearsal is contained by its programme being inactive (DemoSeeder). A reader
        // keyed on a cycle never walks that chain unless it asks.
        DB::table('gates_award_programmes')->where('id', 61)->update(['is_active' => 0]);
        $this->assertNull(Pool::forPublic(61));
        $this->assertNull(Pool::pollPayload(61));
    }
}
