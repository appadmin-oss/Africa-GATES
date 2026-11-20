<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnTarget as Target;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Every rule with a MONEY target shows it where people vote (owner, 20 Nov 2026: "it should
 * work for all things that have a monetary target"), not only the target pool.
 *
 * Two-stage, and a custom ladder held locked: the edition's milestone, once, never as a
 * nominee's own goal. Tiered and custom: the next step of the ladder, per nominee or for the
 * edition as the ladder is measured. Threshold and flat have no money target and draw nothing.
 *
 * What these try to break: counting refunded or fraud-flagged money towards a target the
 * accrual never counts; a milestone drawn as something one nominee "reached"; "about N paid
 * votes" off by one at the line; a ladder with nowhere to climb drawn as a target; a target
 * shown with vote sales off; the poll and the page disagreeing; a query per row.
 */
final class MoneyTargetDisplayTest extends TestCase
{
    private const SLUG = 'money-award';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 71, 'slug' => self::SLUG, 'title' => 'Money Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 71, 'programme_id' => 71, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(), 'voting_close' => Carbon::now()->addDays(5)->toDateTimeString()]);
        DB::table('gates_award_categories')->insert(['id' => 711, 'cycle_id' => 71, 'slug' => 'music', 'title' => 'Music']);
        foreach ([7101 => 'Ada Obi', 7102 => 'Bola Ade'] as $id => $n) {
            DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => 711, 'name' => $n, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        }
        foreach (['paid_voting_enabled' => '1', 'vote_price_naira' => '100'] as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
    }

    /** @param array<string,mixed> $in */
    private function rule(array $in): void
    {
        $r = Milestone::saveEditionRules(71, $in);
        $this->assertTrue($r['ok'], $r['message']);
    }

    private function paid(int $nominee, int $naira, array $extra = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($extra + ['donor_name' => 'D', 'donor_email' => 'd@x.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'status' => 'confirmed', 'intent_nominee_id' => $nominee,
            'created_at' => Carbon::now()->toDateTimeString()]);
    }

    private function get(string $path): string
    {
        DB::table('gates_cache')->delete();
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $res = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        $this->assertSame(200, $res->getStatusCode(), $path);
        return (string) $res->getBody();
    }

    private function words(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    private function goal(string $html): string
    {
        return preg_match('~<section class="vn-goal[^"]*".*?</section>~s', $html, $m) ? $m[0] : '';
    }

    // ── two-stage: the edition's milestone ───────────────────────────────────

    public function test_a_two_stage_edition_shows_its_milestone_counted_by_the_accrual_rule(): void
    {
        $this->rule(['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10, 'milestone_naira' => 10000]);
        $this->paid(7101, 3000);
        $this->paid(7102, 1050);
        $this->paid(7101, 5000, ['refunded_at' => Carbon::now()->toDateTimeString()]);      // money that went back
        $flagged = $this->paid(7102, 2000);
        DB::table('gates_votes')->insert(['nominee_id' => 7102, 'category_id' => 711, 'voter_email_hash' => 'paidvote:f',
            'vote_type' => 'paid', 'weight' => 20, 'fraud_flag' => 1, 'donation_id' => $flagged]);

        $t = Target::forPublic(71);
        $this->assertSame('milestone', $t['kind']);
        $this->assertSame('edition', $t['scope']);
        $e = Target::editionIn($t);
        $this->assertSame(405000, $e['raised_kobo'], 'refunded and fraud-flagged money counted towards the milestone');
        // ₦5,950 to go at ₦100 a vote is 59.5 → 60 paid votes: rounded UP, never "0 to go" early.
        $this->assertSame('₦5,950 to go · about 60 paid votes', $e['left']);
        $this->assertSame(40, $e['pct']);
        $this->assertNull(Target::standingIn($t, 7101), 'the edition’s milestone was given to one nominee as their own');

        $page = $this->words($this->goal($this->get('/vote/' . self::SLUG . '/7101-x')));
        $this->assertStringContainsString('Edition milestone: ₦10,000', $page);
        $this->assertStringContainsString('₦4,050 of ₦10,000 raised', $page);
        $this->assertStringContainsString('every nominee’s share unlocks', $page);
        $this->assertStringNotContainsString('Target reached', $page);

        $list = $this->get('/vote/' . self::SLUG);
        $this->assertStringContainsString('data-goal-edition', $list, 'the list states the edition’s milestone once');
        $this->assertStringNotContainsString('vp-row__goal', $list, 'and puts no edition figure on a nominee’s row');

        $award = $this->words($this->get('/awards/' . self::SLUG));
        $this->assertStringContainsString('Edition milestone: ₦10,000 · ₦5,950 to go', $award);
    }

    public function test_a_reached_and_then_confirmed_milestone_says_so(): void
    {
        $this->rule(['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10, 'milestone_naira' => 1000]);
        $this->paid(7101, 1000);
        $this->assertSame('Milestone reached, awaiting confirmation', Target::editionIn(Target::forPublic(71))['left'],
            'reaching the line is shown, never treated as the confirmation');

        $this->assertTrue(Milestone::confirm(71, 1)['ok']);
        DB::table('gates_settings')->where('key_name', 'paid_voting_enabled')->update(['value' => '0']);
        $e = Target::editionIn(Target::forPublic(71));
        $this->assertNotNull($e, 'a confirmed milestone is a record and stays when vote sales stop');
        $this->assertSame('Milestone confirmed', $e['left']);
    }

    public function test_a_custom_ladder_held_locked_shows_the_milestone(): void
    {
        $this->rule(['model' => 'custom', 'ladder' => "0: 20\n5000: 40", 'ladder_locked' => '1', 'milestone_naira' => 8000]);
        $this->assertSame('milestone', Target::forPublic(71)['kind']);
    }

    // ── ladders: the next step ───────────────────────────────────────────────

    public function test_a_tiered_ladder_shows_each_nominee_their_next_step_on_the_page_the_list_and_the_poll(): void
    {
        $this->rule(['model' => 'tiered']);   // the house ladder: 10% · 30% from ₦50,000 · 50% from ₦200,000
        $this->paid(7101, 49950);
        $this->paid(7102, 250000);
        $this->paid(7101, 10000, ['refunded_at' => Carbon::now()->toDateTimeString()]);   // went back: not raised
        $this->paid(7101, 10000, ['refund_requested_at' => Carbon::now()->toDateTimeString()]);

        $t = Target::forPublic(71);
        $this->assertSame(['ladder', 'nominee'], [$t['kind'], $t['scope']]);
        $a = Target::standingIn($t, 7101);
        $this->assertSame('Next step: ₦50,000 for a 30% share', $a['head']);
        $this->assertSame('₦50 to go · about 1 paid vote', $a['left'], 'one ₦100 vote closes a ₦50 gap');
        $b = Target::standingIn($t, 7102);
        $this->assertTrue($b['reached']);
        $this->assertSame('Top share reached', $b['left']);

        $page = $this->words($this->goal($this->get('/vote/' . self::SLUG . '/7101-x')));
        $this->assertStringContainsString('Next step: ₦50,000 for a 30% share', $page);
        $this->assertStringContainsString('the share now is 10%', $page);

        $list = $this->words($this->get('/vote/' . self::SLUG));
        $this->assertStringContainsString('₦50 to go · about 1 paid vote', $list);
        $this->assertStringContainsString('Top share reached', $list);

        $poll = json_decode($this->get('/vote/' . self::SLUG . '/tallies'), true);
        $rows = array_column($poll['target']['nominees'], null, 'id');
        $this->assertSame($a['left'], $rows[7101]['left'], 'the poll and the page say the same thing');
        $this->assertSame('ladder', $poll['target']['kind']);

        $award = $this->words($this->get('/awards/' . self::SLUG));
        $this->assertStringContainsString('1 of 2 nominees are on the top share', $award);
    }

    public function test_a_custom_ladder_on_the_edition_is_one_figure(): void
    {
        $this->rule(['model' => 'custom', 'ladder' => "0: 10\n20000: 40", 'basis' => 'edition']);
        $this->paid(7101, 6000);
        $this->paid(7102, 4000);
        $t = Target::forPublic(71);
        $this->assertSame('edition', $t['scope']);
        $this->assertSame('₦10,000 to go · about 100 paid votes', Target::editionIn($t)['left']);
        $this->assertNull(Target::standingIn($t, 7101));
    }

    // ── nothing to draw ──────────────────────────────────────────────────────

    public function test_rules_without_a_money_target_draw_nothing(): void
    {
        $this->rule(['model' => 'threshold']);
        $this->assertNull(Target::forPublic(71), 'threshold counts votes, not money');
        $this->rule(['model' => 'flat', 'full_pct' => 20]);
        $this->assertNull(Target::forPublic(71), 'flat has nowhere to climb');
        $this->rule(['model' => 'custom', 'ladder' => '0: 25']);
        $this->assertNull(Target::forPublic(71), 'a one-step ladder from ₦0 is flat in effect');

        $page = $this->get('/vote/' . self::SLUG . '/7101-x');
        $this->assertSame('', $this->goal($page));
        $this->assertStringNotContainsString('aw-pool', $this->get('/awards/' . self::SLUG));
    }

    public function test_no_money_target_is_promised_while_vote_sales_are_off(): void
    {
        $this->rule(['model' => 'tiered']);
        DB::table('gates_settings')->where('key_name', 'paid_voting_enabled')->update(['value' => '0']);
        $this->assertNull(Target::forPublic(71));
    }

    public function test_a_list_costs_one_money_query_however_many_rows(): void
    {
        $this->rule(['model' => 'tiered']);
        for ($i = 0; $i < 30; $i++) {
            DB::table('gates_nominees')->insert(['id' => 7200 + $i, 'category_id' => 711, 'name' => 'N' . $i, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        }
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        Target::forPublic(71);
        $money = array_filter(DB::connection()->getQueryLog(), static fn ($q) => str_contains($q['query'], 'gates_donations'));
        DB::connection()->disableQueryLog();
        $this->assertCount(1, $money, 'a query per nominee row');
    }
}
