<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CpiService, JudgeRubric, PublicResults, ReleasedStanding,
                          ResultRelease, RuleEngine, SnapshotService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A PUBLISHED RESULT IS THE ONE THAT WAS ANNOUNCED.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see PublicResults::category()} re-ran the whole calculation on every page view, so a
 * released result page showed what TODAY'S rules produce rather than what the nominee was
 * awarded — and the winner it named was the recomputed top, not the person given the
 * award. Measured on a real released nominee, 1,955 votes and a 7.9 panel: 693 when it was
 * announced, 885 after a week of scoring changes. Nothing was edited. The hash chain in
 * `gates_vote_snapshots` was intact throughout, and nothing published had ever read it.
 *
 * Meanwhile the help centre promises "there is no quiet edit available. There is only an
 * edit that announces itself" — a promise about an archive whose only readers were a
 * console command and the maintenance sweep, on a host with no shell.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * HOW THESE TESTS PROVE IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * By actually changing the rules between the announcement and the page view — which is the
 * only way to tell a sealed figure from a recomputed one, because while the rules hold
 * still the two agree and every assertion passes either way.
 */
final class ReleasedStandingTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'seal-' . bin2hex(random_bytes(3)),
            'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'results',
            'edition_label' => '2026 edition',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'primary',
            'title' => 'Primary School Principal', 'sort_order' => 1,
        ]);
    }

    /**
     * A nominee WITH THE BALLOT ROWS BEHIND THEIR TALLY.
     *
     * One person per vote, which is the perfect case the `ideal` yardstick is named for.
     * Without rows the whole edition is unmeasured, the people term — 70% of the community
     * half — is not paid, and every figure in this file would be measuring that rather
     * than the thing under test. Chunked, because thousands of single inserts is a slow
     * test and a slow test is one somebody skips.
     */
    private function nominee(string $name, int $votes): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $votes, 'vote_count' => $votes,
        ]);

        $rows = [];
        for ($v = 0; $v < $votes; $v++) {
            $rows[] = [
                'nominee_id' => $id, 'category_id' => $this->categoryId,
                'vote_type' => 'standard', 'weight' => 1,
                'voter_email_hash' => \AfricaGates\Services\VoteService::voterHash(
                    'rs' . $id . '-' . $v . '@x.test'),
            ];
            if (count($rows) === 500) { DB::table('gates_votes')->insert($rows); $rows = []; }
        }
        if ($rows !== []) DB::table('gates_votes')->insert($rows);

        return $id;
    }

    /** A complete panel at quorum, from whole marks. */
    private function panel(int $nominee, int $mark, int $judges = 2): void
    {
        static $n = 0;
        for ($k = 0; $k < $judges; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Judge ' . (++$n), 'is_active' => 1,
                'email' => 'sj' . $n . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee,
                    'category_id' => $this->categoryId, 'criterion_id' => (int) $c->id,
                    'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    /** The public result page, rendered, so a claim about what it says is checked. */
    private function renderShow(array $r): string
    {
        // The real layout pulls in globals and functions a unit test has no business
        // booting, so it is stubbed — ArrayLoader FIRST, or the chain finds the real one
        // and the stub does nothing.
        $layout = <<<'TWIG'
            <!doctype html><title>{{ page_title }}</title>
            {% block head_styles %}{% endblock %}
            <main>{% block content %}{% endblock %}</main>
            {% block foot_scripts %}{% endblock %}
            TWIG;

        $twig = new \Twig\Environment(
            new \Twig\Loader\ChainLoader([
                new \Twig\Loader\ArrayLoader(['layout/gates.twig' => $layout]),
                new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
            ]),
            ['strict_variables' => true]);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        // Whitespace-normalised: the page wraps its prose across lines, so a claim about
        // what it SAYS must not turn into a claim about where the template breaks.
        return (string) preg_replace('~\s+~', ' ', $twig->render('pages/results/show.twig', [
            'page_title' => 'Result', 'gates_page' => 'results', 'r' => $r,
            'thread' => null, 'replies' => [], 'is_member' => false,
            'og_image' => '', 'og_image_w' => 0, 'og_image_h' => 0,
        ]));
    }

    /** Move the scoring rules, which is what a released page must be immune to. */
    private function changeTheRules(): void
    {
        (new RuleEngine())->set('global', null, [
            'community_basis' => CpiService::BASIS_RELATIVE,
            'community_scope' => CpiService::SCOPE_CATEGORY,
            'judge_scale'     => CpiService::SCALE_CURVED,
        ]);
    }

    // ══ the fix ══════════════════════════════════════════════════════════════

    /**
     * THE FIGURES ON A RELEASED PAGE ARE THE SEALED ONES, NOT TODAY'S.
     *
     * The rules move between the seal and the read, so a live computation gives a
     * different index. The page must go on publishing the announcement.
     */
    public function test_a_released_page_publishes_the_sealed_index_not_a_recomputed_one(): void
    {
        $a = $this->nominee('Ajayi Temitope', 1955);
        $this->panel($a, 8);

        $announced = ResultRelease::category($this->categoryId)['rows'][0]['cpi'];
        $this->assertSame(1, (new SnapshotService())->captureRelease($this->cycleId) > 0 ? 1 : 0,
            'the standing was not sealed at all, so there is nothing for the page to read');

        $this->changeTheRules();

        $live = ResultRelease::category($this->categoryId)['rows'][0]['cpi'];
        $this->assertNotSame($announced, $live,
            'the rules did not actually move, so this test cannot tell a sealed figure '
            . 'from a recomputed one and proves nothing');

        $r = PublicResults::category($this->categoryId);
        $this->assertSame($announced, $r['rows'][0]['cpi'],
            'the published page recomputed the score under rules that did not exist when '
            . 'the award was made');
        $this->assertNotSame('', (string) $r['sealed_at'],
            'the page cannot say which announcement it is showing');
    }

    /**
     * AND THE WINNER IT NAMES IS THE ONE WHO WAS CROWNED.
     *
     * This is the sharper half. `r.winner` on the public page is the top of the ranking,
     * and the ranking was recomputed — so a rules change big enough to reorder a category
     * silently made the page name somebody else as the winner of an award already given to
     * a different person.
     */
    public function test_a_rules_change_cannot_rename_the_winner_of_a_released_award(): void
    {
        // Sealed under the default: the community half is edition-wide, so the nominee with
        // vastly more votes takes it despite the lower panel mark.
        $many = $this->nominee('More votes, good panel', 2000);
        $few  = $this->nominee('Fewer votes, best panel', 1400);
        $this->panel($many, 8);
        $this->panel($few, 10);

        $sealedWinner = ResultRelease::category($this->categoryId)['winner']['name'];
        (new SnapshotService())->captureRelease($this->cycleId);

        // The curved judge scale rewards the 10 far more steeply, and the per-category
        // basis stops the tally dominating — enough to flip the order.
        $this->changeTheRules();

        $liveWinner = ResultRelease::category($this->categoryId)['winner']['name'];
        $this->assertNotSame($sealedWinner, $liveWinner,
            'the rules change did not reorder the category, so this test is not about '
            . 'what it says it is about');

        $this->assertSame($sealedWinner, PublicResults::category($this->categoryId)['winner']['name'],
            'the published page renamed the winner of an award that had already been '
            . 'given to somebody else');
    }

    /**
     * A NOMINEE THE SEAL DOES NOT NAME IS NOT IN THE PUBLISHED STANDING.
     *
     * Somebody added to the category after the release, or judged to quorum after it, was
     * not in the standing that was announced. Ranking them into it afterwards would rewrite
     * a published result — quietly, and without any record being edited.
     */
    public function test_a_nominee_added_after_the_release_is_not_ranked_into_it(): void
    {
        $a = $this->nominee('Announced winner', 500);
        $this->panel($a, 8);

        (new SnapshotService())->captureRelease($this->cycleId);

        $late = $this->nominee('Arrived afterwards', 5000);
        $this->panel($late, 10);

        $r  = PublicResults::category($this->categoryId);
        $by = [];
        foreach ($r['rows'] as $row) $by[$row['name']] = $row;

        $this->assertSame('Announced winner', $r['winner']['name'],
            'a nominee who was not in the announced standing has taken the award');
        $this->assertFalse($by['Arrived afterwards']['in_running']);
        $this->assertSame(ReleasedStanding::OUT_NOT_SEALED,
            $by['Arrived afterwards']['out_reason'],
            'they are excluded with no reason on the page, which is the part of a result '
            . 'hardest to defend later');
    }

    /**
     * A NOMINEE SEALED AS OUT OF THE RUNNING STAYS OUT, EVEN ONCE THEY QUALIFY.
     *
     * Sharper than a nominee the seal never mentions, and the case a naive implementation
     * gets wrong: this one IS in the sealed standing, with figures and everything — they
     * were simply below the judge quorum on the day and so were not ranked.
     *
     * Quorum is a live fact. A panel that finishes a week after the announcement would
     * otherwise sweep them into a published result they were never part of, moving
     * everybody below them down a place, with no record edited and nothing on the page to
     * say why the order changed. That is why `in_running` is sealed rather than re-derived.
     */
    public function test_a_nominee_sealed_below_quorum_is_not_swept_in_when_judging_finishes(): void
    {
        $won     = $this->nominee('Announced winner', 500);
        $pending = $this->nominee('Panel unfinished', 5000);
        $this->panel($won, 8);
        $this->panel($pending, 10, judges: 1);          // below quorum at the announcement

        $drawn = ResultRelease::category($this->categoryId);
        $this->assertSame('Announced winner', $drawn['winner']['name']);
        (new SnapshotService())->captureRelease($this->cycleId);

        // Their panel finishes afterwards. Live, that makes them eligible and — on 5,000
        // votes and a perfect mark — the top of the category.
        $this->panel($pending, 10, judges: 1);
        $this->assertSame('Panel unfinished',
            ResultRelease::category($this->categoryId)['winner']['name'],
            'the fixture no longer produces the takeover this test is about');

        $r  = PublicResults::category($this->categoryId);
        $by = [];
        foreach ($r['rows'] as $row) $by[$row['name']] = $row;

        $this->assertSame('Announced winner', $r['winner']['name'],
            'a nominee judged after the announcement has taken a published award');
        $this->assertFalse($by['Panel unfinished']['in_running'],
            'the published standing ranked somebody the announced one did not');
    }

    // ══ and what it refuses to do ════════════════════════════════════════════

    /**
     * A RELEASE WITH NO SEAL IS PUBLISHED AS A LIVE COMPUTATION, AND SAYS SO.
     *
     * Cycles released before sealing existed have no `release` capture. There is no honest
     * way to recover their announced standing — the routine captures were taken on a
     * schedule under whatever rules held that day, and choosing the one nearest the
     * announcement would be a guess presented as a record.
     *
     * So the page recomputes, and `sealed_at` is empty so it can say which it is. A figure
     * labelled as announced when nobody knows whether it was is worse than one labelled
     * as recomputed.
     */
    public function test_a_release_with_no_seal_is_not_dressed_up_as_an_announcement(): void
    {
        $a = $this->nominee('Released long ago', 400);
        $this->panel($a, 8);

        $r = PublicResults::category($this->categoryId);

        $this->assertSame('', (string) $r['sealed_at'],
            'the page claims to be showing a sealed announcement that was never recorded');
        $this->assertSame(ResultRelease::category($this->categoryId)['rows'][0]['cpi'],
            $r['rows'][0]['cpi']);
    }

    /**
     * AND THE PAGE SAYS WHICH OF THE TWO IT IS SHOWING.
     *
     * The distinction only protects anybody if a reader can see it. Both states are
     * rendered, because the failure mode is not a missing sentence — it is a live
     * computation being read as an announcement.
     */
    public function test_the_page_states_whether_the_figures_were_announced_or_recomputed(): void
    {
        $a = $this->nominee('Ajayi Temitope', 1955);
        $this->panel($a, 8);

        $loose = $this->renderShow(PublicResults::category($this->categoryId));
        $this->assertStringContainsString('Recomputed under current rules', $loose,
            'a live computation is being presented as the announced result');
        $this->assertStringContainsString('not read back from the announcement', $loose);

        (new SnapshotService())->captureRelease($this->cycleId);

        $sealed = $this->renderShow(PublicResults::category($this->categoryId));
        $this->assertStringContainsString('As announced', $sealed);
        $this->assertStringNotContainsString('Recomputed under current rules', $sealed,
            'a sealed standing is still being described as a recomputation');
    }

    /**
     * AND A CYCLE IS SEALED ONCE.
     *
     * Promotion runs from an unattended sweep whose own docblock says two schedulers can
     * overlap. The announced standing is the standing at the announcement, not at whichever
     * re-run fired last — so a second seal must be refused rather than appended.
     */
    public function test_a_cycle_is_sealed_once_however_often_the_sweep_runs(): void
    {
        $a = $this->nominee('Ajayi Temitope', 1955);
        $this->panel($a, 8);

        $snap  = new SnapshotService();
        $first = $snap->captureRelease($this->cycleId);
        $this->assertGreaterThan(0, $first);

        $this->changeTheRules();

        $this->assertSame(0, $snap->captureRelease($this->cycleId),
            'a re-run resealed the cycle, so the announced standing is now whichever one '
            . 'the last sweep happened to compute');

        $this->assertSame(1, DB::table('gates_vote_snapshots')
            ->where('cycle_id', $this->cycleId)
            ->where('capture_kind', SnapshotService::KIND_RELEASE)
            ->distinct()->count('snapshot_at'));
    }

    // ══ the order, which is a rule like any other ════════════════════════════

    /**
     * THE PUBLISHED ORDER IS THE SEALED ORDER, NOT TODAY'S TIEBREAK.
     *
     * `apply()` used to throw `standing_rank` away and re-sort the sealed figures through
     * {@see ResultRelease::order()}, on the reasoning that it is "the same comparator the
     * award was decided with". It is — until somebody changes it, and a tiebreak is a rule
     * exactly like the two the seal already protects. `order()` settles a dead heat on the
     * tally and then on the nominee id, neither of which is an announcement.
     *
     * Simulated the only way it can be: the seal records the placings announced on the day,
     * and here they are the OPPOSITE of what today's comparator produces from the same
     * numbers. A page that re-derives the order cannot publish an announcement it is unable
     * to reconstruct, which is the whole reason the placings are sealed and not the figures
     * alone.
     */
    public function test_a_released_page_publishes_the_sealed_placings(): void
    {
        $first  = $this->nominee('Announced first', 900);
        $second = $this->nominee('Announced second', 900);
        $this->panel($first, 8);
        $this->panel($second, 8);

        (new SnapshotService())->captureRelease($this->cycleId);

        // A dead heat on both terms, so today's comparator falls through to the nominee
        // id — and the announcement went the other way.
        $this->assertSame(
            [900, 900],
            DB::table('gates_vote_snapshots')->where('cycle_id', $this->cycleId)
                ->where('capture_kind', SnapshotService::KIND_RELEASE)
                ->orderBy('nominee_id')->pluck('vote_count')
                ->map(static fn ($v): int => (int) $v)->all(),
            'the fixture is not a dead heat, so the comparator has a real tiebreak to '
            . 'use and this test is not about what it says it is about');

        $this->seal($second, standingRank: 1);
        $this->seal($first,  standingRank: 2);

        $r = PublicResults::category($this->categoryId);

        $this->assertSame('Announced second', $r['winner']['name'],
            'the page re-decided a dead heat under today\'s tiebreak instead of '
            . 'publishing the placing that was announced');
        $this->assertSame(['Announced second', 'Announced first'],
            array_map(static fn (array $row): string => $row['name'], $r['rows']));
        $this->assertSame([1, 2],
            array_map(static fn (array $row): ?int => $row['rank'], $r['rows']));
        $this->assertFalse($r['rank_recomputed'],
            'the order came straight off the seal, so nothing was reconstructed');
    }

    /**
     * AND WHERE THE SEAL DID NOT RANK EVERYBODY, THE PAGE SAYS SO.
     *
     * The fallback the old reasoning was actually about: a placing that failed to write.
     * The figures are still the announced ones and the list still has to be printed in
     * SOME order, so it is reconstructed from them — and a reader is told, because the two
     * cases are indistinguishable by looking and one of them is a reconstruction.
     */
    public function test_a_seal_missing_a_placing_is_reconstructed_and_labelled(): void
    {
        $big   = $this->nominee('Clear leader', 4000);
        $small = $this->nominee('Second', 400);
        $this->panel($big, 9);
        $this->panel($small, 8);

        (new SnapshotService())->captureRelease($this->cycleId);
        $this->seal($small, standingRank: null);

        $r = PublicResults::category($this->categoryId);

        $this->assertTrue($r['rank_recomputed'],
            'a reconstructed order is being published as the announced one');
        $this->assertSame('Clear leader', $r['winner']['name']);
        $this->assertSame([1, 2],
            array_map(static fn (array $row): ?int => $row['rank'], $r['rows']),
            'the list still has to carry placings, reconstructed or not');

        $this->assertStringContainsString('order reconstructed',
            $this->renderShow($r),
            'the page presents a reconstructed order as the announcement');
    }

    /**
     * THE REACH DENOMINATOR IS SEALED, AND THE PAGE STOPS ASKING TODAY'S ROWS.
     *
     * `cohort_max_unique` was read out of the seal and then never applied, so the public
     * page gated its backer count on the LIVE figure: where the leader's ballot rows have
     * since gone — an import, a purged cycle — the number of supporters vanishes from an
     * announcement that counted them, beside a percentage that plainly came from
     * somewhere.
     */
    public function test_the_sealed_reach_denominator_survives_the_rows_being_purged(): void
    {
        // `nominee()` writes one ballot row per vote, so this nominee's reach is their
        // whole tally — which is what makes the purge below a real loss rather than the
        // loss of a single row.
        $a = $this->nominee('Ajayi Temitope', 1200);
        $this->panel($a, 8);

        (new SnapshotService())->captureRelease($this->cycleId);
        $announced = (int) PublicResults::category($this->categoryId)['cohort_max_unique'];
        $this->assertSame(1200, $announced, 'the fixture sealed no reach to lose');

        // The ballot rows go; the tally on the nominee does not. This is what an import
        // from before this platform held rows looks like from the page's side.
        DB::table('gates_votes')->where('category_id', $this->categoryId)->delete();

        $this->assertSame($announced,
            (int) PublicResults::category($this->categoryId)['cohort_max_unique'],
            'the published denominator moved when rows behind an announced result were '
            . 'purged, so the page stopped printing supporters it had counted');
    }

    // ══ and what the operator's screen can see ═══════════════════════════════

    /**
     * THE NOMINEE TODAY'S RULES WOULD RANK INTO A PUBLISHED AWARD IS NAMED.
     *
     * The public page keeps them out, which is exactly what sealing `in_running` is for.
     * But an operator has to be able to SEE that this is what happened rather than
     * discover it from a complaint — and until `divergence()` existed no screen anywhere
     * held the announced figure and the live one at once, so the person taking the call
     * that begins "my score has changed" had only the recomputed number in front of them.
     */
    public function test_a_nominee_the_live_draw_would_rank_in_is_reported_to_the_operator(): void
    {
        $won     = $this->nominee('Announced winner', 500);
        $pending = $this->nominee('Panel unfinished', 5000);
        $this->panel($won, 8);
        $this->panel($pending, 10, judges: 1);          // below quorum at the announcement

        (new SnapshotService())->captureRelease($this->cycleId);
        $this->panel($pending, 10, judges: 1);          // and finished a week later

        $d = ReleasedStanding::divergence(ResultRelease::forCycle($this->cycleId), $this->cycleId);

        $this->assertNotNull($d, 'a sealed cycle is being reported as never announced');
        $this->assertNotSame('', $d['sealed_at']);
        $this->assertSame(2, $d['checked']);
        $this->assertSame(0, $d['added']);
        $this->assertSame(0, $d['gone']);

        $by = [];
        foreach ($d['moved'] as $m) $by[$m['name']] = $m;

        $this->assertArrayHasKey('Panel unfinished', $by,
            'a nominee today\'s rules would rank into a published award is not reported');
        $this->assertFalse($by['Panel unfinished']['in_sealed']);
        $this->assertTrue($by['Panel unfinished']['in_now']);
        $this->assertSame('Panel unfinished', $d['moved'][0]['name'],
            'the row that would rewrite a published award is not the first one an '
            . 'operator reads');
    }

    /**
     * AND A CYCLE TODAY'S RULES STILL AGREE WITH REPORTS NOTHING MOVED.
     *
     * The common case. An empty `moved` and a null return mean opposite things — "checked,
     * and they agree" against "never announced, nothing to check" — and a screen that
     * cannot tell them apart says nothing in both.
     */
    public function test_a_seal_the_rules_still_agree_with_reports_no_movement(): void
    {
        $a = $this->nominee('Ajayi Temitope', 1955);
        $this->panel($a, 8);

        $unsealed = ReleasedStanding::divergence(
            ResultRelease::forCycle($this->cycleId), $this->cycleId);
        $this->assertNull($unsealed, 'an unsealed cycle is being reported as an announcement');

        (new SnapshotService())->captureRelease($this->cycleId);

        $d = ReleasedStanding::divergence(ResultRelease::forCycle($this->cycleId), $this->cycleId);
        $this->assertSame([], $d['moved']);
        $this->assertSame(1, $d['checked']);
    }

    /**
     * A NOMINEE ADDED AFTER THE ANNOUNCEMENT IS NOT A DISCREPANCY.
     *
     * They are a different field, not a disagreement about a sealed figure, and counting
     * them as one would put a number beside "these have moved" on every cycle that has
     * taken an entry since — which teaches an operator to stop reading the panel.
     */
    public function test_a_nominee_entered_after_the_announcement_is_counted_apart(): void
    {
        $a = $this->nominee('Announced', 500);
        $this->panel($a, 8);

        (new SnapshotService())->captureRelease($this->cycleId);

        $late = $this->nominee('Arrived afterwards', 5000);
        $this->panel($late, 9);

        $d = ReleasedStanding::divergence(ResultRelease::forCycle($this->cycleId), $this->cycleId);

        $this->assertSame(1, $d['added'], 'the late entry is not counted as an entry');
        $this->assertSame(0, $d['gone']);
        foreach ($d['moved'] as $m) {
            $this->assertNotSame('Arrived afterwards', $m['name'],
                'a nominee who was never in the announced standing is being reported as '
                . 'having moved within it');
        }
    }

    /**
     * A PAGE LISTING SIXTY AWARDS READS EACH CYCLE'S SEAL ONCE, NOT ONCE AN AWARD.
     *
     * The seal is per CYCLE and it is read per CATEGORY, so the results index re-read the
     * whole of a cycle's archive for every award on the page. Exactly the shape the
     * edition scale had one commit earlier, in exactly the same loop — the scorer is
     * threaded through it so that sixty results read each cycle once, and the seal lookup
     * went straight back to once per award.
     *
     * Counted rather than reasoned about, because the cost is invisible at the call site:
     * `PublicResults::category()` looks like it draws one award.
     */
    public function test_the_results_index_reads_a_cycles_seal_once(): void
    {
        $a = $this->nominee('First award', 900);
        $this->panel($a, 8);

        $second = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'secondary',
            'title' => 'Secondary School Principal', 'sort_order' => 2,
        ]);
        $b = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $second, 'name' => 'Second award', 'status' => 'approved',
            'organic_vote_count' => 700, 'vote_count' => 700,
        ]);
        $this->panel($b, 8);

        (new SnapshotService())->captureRelease($this->cycleId);
        ReleasedStanding::forget();

        $conn = DB::connection();
        $conn->flushQueryLog();
        $conn->enableQueryLog();
        $listed = PublicResults::index();
        $seals  = 0;
        foreach ($conn->getQueryLog() as $q) {
            if (str_contains((string) $q['query'], 'gates_vote_snapshots')) $seals++;
        }
        $conn->disableQueryLog();

        $this->assertGreaterThanOrEqual(2, count($listed['items']) + $listed['held'],
            'the fixture is not listing two awards, so this counts the queries of nothing');
        $this->assertSame(1, $seals,
            'the results index reads the sealed standing once per award rather than once '
            . 'per cycle, so every card it draws pulls the whole archive again');
    }

    /**
     * Rewrite one sealed row's placing, standing in for an announcement made under a
     * comparator this code no longer has. Touches `standing_rank` ONLY: it sits outside
     * the hash payload (`cycleId|nomineeId|votes|cpi|at`) by design, so the chain stays
     * verifiable and the test is not quietly asserting that tampering is undetectable.
     */
    private function seal(int $nomineeId, ?int $standingRank): void
    {
        DB::table('gates_vote_snapshots')
            ->where('cycle_id', $this->cycleId)
            ->where('capture_kind', SnapshotService::KIND_RELEASE)
            ->where('nominee_id', $nomineeId)
            ->update(['standing_rank' => $standingRank]);
    }

    /**
     * AND SEALING NEVER BREAKS THE CHAIN IT IS WRITTEN INTO.
     *
     * The release capture goes through the same appender as the routine sweep — one tail
     * lock, one hash, one transaction — because a fork is the worst failure this structure
     * has: every row stays honest and `verify()` reports the record as altered for ever,
     * indistinguishable from real tampering and unclearable.
     */
    public function test_the_seal_leaves_the_chain_verifiable(): void
    {
        $a = $this->nominee('Ajayi Temitope', 1955);
        $this->panel($a, 8);

        $snap = new SnapshotService();
        $snap->capture();
        $snap->captureRelease($this->cycleId);
        $snap->capture();

        $v = $snap->verify();
        $this->assertTrue($v['ok'], 'sealing a release broke the tamper-evidence chain');
        $this->assertNull($v['broken_at']);
    }
}
