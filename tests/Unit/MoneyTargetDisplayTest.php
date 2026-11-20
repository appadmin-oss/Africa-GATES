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
        $this->assertSame('₦5,950 to go', $e['left']);
        $this->assertSame(60, $e['votes_to_go'], '₦5,950 at ₦100 a vote is 59.5: rounded UP');
        $this->assertSame(40, $e['pct']);
        $this->assertNull(Target::standingIn($t, 7101), 'the edition’s milestone was given to one nominee as their own');

        $page = $this->words($this->goal($this->get('/vote/' . self::SLUG . '/7101-x')));
        $this->assertStringContainsString('Unlock rewards for every nominee!', $page);
        $this->assertStringContainsString('₦4,050 of ₦10K raised', $page);
        $this->assertStringContainsString($e['note'], $page, 'the page carries the cheer the resolver chose');
        $this->assertStringNotContainsString('Target reached', $page);

        $list = $this->get('/vote/' . self::SLUG);
        $this->assertStringContainsString('data-goal-edition', $list, 'the list states the edition’s milestone once');
        $this->assertStringNotContainsString('vp-row__goal', $list, 'and puts no edition figure on a nominee’s row');

        $award = $this->words($this->get('/awards/' . self::SLUG));
        $this->assertStringContainsString('Unlock rewards for every nominee! ₦5,950 to go', $award);
    }

    public function test_a_reached_and_then_confirmed_milestone_says_so(): void
    {
        $this->rule(['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10, 'milestone_naira' => 1000]);
        $this->paid(7101, 1000);
        $this->assertSame('Rewards unlocking soon', Target::editionIn(Target::forPublic(71))['left'],
            'reaching the line is shown, never treated as the confirmation');

        $this->assertTrue(Milestone::confirm(71, 1)['ok']);
        DB::table('gates_settings')->where('key_name', 'paid_voting_enabled')->update(['value' => '0']);
        $e = Target::editionIn(Target::forPublic(71));
        $this->assertNotNull($e, 'a confirmed milestone is a record and stays when vote sales stop');
        $this->assertSame('Rewards unlocked!', $e['head']);
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
        $this->assertSame('Power up this nominee’s reward!', $a['head']);
        $this->assertSame('₦50 to 30%', $a['left']);
        $this->assertSame(1, $a['votes_to_go'], 'one ₦100 vote closes a ₦50 gap');
        $this->assertContains($a['note'], $this->cheers(99, 'Only ₦50 left. Let’s finish this!', '₦49K raised in the last hour. Keep it rolling!'), '99% of the way');
        $this->assertStringContainsString('/assets/img/spark/trophy-', $a['art'], 'the closing stretch is the trophy');
        $b = Target::standingIn($t, 7102);
        $this->assertTrue($b['reached']);
        $this->assertSame('Top reward unlocked!', $b['head']);
        $this->assertSame('Earning the full 50%', $b['left']);

        $page = $this->words($this->goal($this->get('/vote/' . self::SLUG . '/7101-x')));
        $this->assertStringContainsString('Power up this nominee’s reward!', $page);
        $this->assertStringContainsString('₦49K of ₦50K raised', $page);

        $list = $this->words($this->get('/vote/' . self::SLUG));
        $this->assertStringContainsString('₦50 to 30%', $list);
        $this->assertStringContainsString('Earning the full 50%', $list);

        $poll = json_decode($this->get('/vote/' . self::SLUG . '/tallies'), true);
        $rows = array_column($poll['target']['nominees'], null, 'id');
        $this->assertSame($a['left'], $rows[7101]['left'], 'the poll and the page say the same thing');
        $this->assertSame('ladder', $poll['target']['kind']);

        $award = $this->words($this->get('/awards/' . self::SLUG));
        $this->assertStringContainsString('1 of 2 nominees have unlocked the top reward', $award);
    }

    public function test_a_custom_ladder_on_the_edition_is_one_figure(): void
    {
        $this->rule(['model' => 'custom', 'ladder' => "0: 10\n20000: 40", 'basis' => 'edition']);
        $this->paid(7101, 6000);
        $this->paid(7102, 4000);
        $t = Target::forPublic(71);
        $this->assertSame('edition', $t['scope']);
        $this->assertSame('₦10K to 40%', Target::editionIn($t)['left']);
        $this->assertContains(Target::editionIn($t)['note'], $this->cheers(50, 'Just ₦5,000 to the three-quarter mark!', '₦10K raised in the last hour. Keep it rolling!'));
        $this->assertNull(Target::standingIn($t, 7101));
    }

    /** The edition in the owner's screenshot: short figures, not a wall of digits. */
    public function test_big_figures_read_the_way_people_say_them(): void
    {
        $this->rule(['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10, 'milestone_naira' => 5900000]);
        $this->paid(7101, 795980);
        $e = Target::editionIn(Target::forPublic(71));
        $this->assertSame('₦5.1M to go', $e['left']);
        $this->assertSame('₦795K of ₦5.9M raised', $e['of']);
        $this->assertContains($e['note'], $this->cheers(13, 'Just ₦679K to the first quarter!', '₦795K raised in the last hour. Keep it rolling!'), '13% there');
        $this->assertSame(13, $e['pct']);
    }

    /**
     * Every line a bar at $pct may show: its stage's lines and the live-figure line given.
     * Read from the resolver itself by turning the clock through a quarter of an hour, so the
     * test asserts the RULE (right stage, rotating) rather than one sentence.
     *
     * @return list<string>
     */
    private function cheers(int $pct, string ...$live): array
    {
        $stage = match (true) {
            $pct >= 90 => ['So close! One big push!', 'The finish line is right there!', 'Almost there. Don’t stop now!', 'This is it. Let’s finish strong!'],
            $pct >= 75 => ['Three quarters done. Bring it home!', 'The end is in sight. Keep pushing!', 'Huge progress! One more push!'],
            $pct >= 50 => ['Halfway there! Keep it coming!', 'Past halfway and climbing!', 'The second half starts now. Let’s go!'],
            $pct >= 25 => ['Momentum is building. Keep going!', 'A quarter done. On to halfway!', 'It’s moving! Every vote counts.'],
            default    => ['We’re off! Every vote counts.', 'Great start. Keep it rolling!', 'The climb has begun!'],
        };
        return [...$stage, ...$live];
    }

    public function test_the_cheer_rotates_through_its_stage_and_uses_live_figures_only_when_true(): void
    {
        $this->rule(['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10, 'milestone_naira' => 10000]);
        $this->paid(7101, 3000, ['created_at' => Carbon::now()->subHours(3)->toDateTimeString()]);   // 30%: momentum

        $seen = [];
        $base = Carbon::now();
        for ($m = 0; $m < 12; $m++) {
            Carbon::setTestNow($base->copy()->addMinutes($m));
            $seen[Target::editionIn(Target::forPublic(71))['note']] = true;
        }
        Carbon::setTestNow();
        $seen = array_keys($seen);
        $allowed = $this->cheers(30, 'Just ₦2,000 to the halfway mark!');
        $this->assertSame([], array_values(array_diff($seen, $allowed)), 'a line from the wrong stage of the bar');
        $this->assertGreaterThanOrEqual(3, count($seen), 'the line does not move');
        $this->assertContains('Just ₦2,000 to the halfway mark!', $seen, 'the gap to the next mark is never said');
        foreach ($seen as $line) {
            $this->assertStringNotContainsString('last hour', $line, 'money from three hours ago called the last hour');
        }

        // Money in the past hour: now, and only now, the live line can appear.
        $this->paid(7102, 500);
        $seen = [];
        for ($m = 0; $m < 12; $m++) {
            Carbon::setTestNow($base->copy()->addMinutes($m));
            $seen[Target::editionIn(Target::forPublic(71))['note']] = true;
        }
        Carbon::setTestNow();
        $this->assertArrayHasKey('₦500 raised in the last hour. Keep it rolling!', $seen);
    }

    public function test_each_stage_has_its_picture_in_both_styles_and_a_generated_image_takes_over(): void
    {
        $dir = dirname(__DIR__, 2) . '/public/assets/img/spark/';
        foreach (Target::ART_SUBJECTS as $sub) {
            foreach (Target::ART_STYLES as $style) {
                $this->assertFileExists($dir . "$sub-$style.svg", 'a picture the site can ask for is missing');
                $this->assertSame("/assets/img/spark/$sub-$style.svg", Target::art($sub, 0, $style));
            }
        }
        $this->rule(['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10, 'milestone_naira' => 10000]);
        $this->assertStringContainsString('/spark/rocket-', Target::editionIn(Target::forPublic(71))['art'], 'under a quarter is the rocket');
        $this->paid(7101, 6000);
        $this->assertStringContainsString('/spark/target-', Target::editionIn(Target::forPublic(71))['art'], 'past halfway is the target');

        // An AI-generated replacement, saved under the same name, is used in its place.
        $png = $dir . 'gift-bold.png';
        $this->assertFileDoesNotExist($png, 'a real generated file would be overwritten by this test');
        file_put_contents($png, 'x');
        try {
            $this->assertSame('/assets/img/spark/gift-bold.png', Target::art('gift', 0, 'bold'));
        } finally {
            unlink($png);
        }
        $this->assertSame('/assets/img/spark/gift-bold.svg', Target::art('gift', 0, 'bold'));
    }

    public function test_the_pop_up_shows_a_built_in_picture_when_nobody_uploaded_one(): void
    {
        $base = ['announce_popup' => '1', 'announce_text' => 'Voting is open', 'announce_title' => 'Vote now'];
        $p = \AfricaGates\Services\SiteNotice::popup($base);
        $this->assertSame('/assets/img/spark/megaphone-bold.svg', $p['art'], 'the default is the app-style megaphone');
        $this->assertSame('/assets/img/spark/trophy-house.svg', \AfricaGates\Services\SiteNotice::popup($base + ['announce_art' => 'trophy-house'])['art']);
        $this->assertSame('/assets/img/spark/megaphone-bold.svg', \AfricaGates\Services\SiteNotice::popup($base + ['announce_art' => '../../etc'])['art'],
            'an unknown choice reached an img src');
        $this->assertNull(\AfricaGates\Services\SiteNotice::popup($base + ['announce_image' => '/assets/img/a.gif'])['art'], 'an uploaded image is replaced');
        $this->assertCount(16, \AfricaGates\Services\SiteNotice::artChoices());
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
