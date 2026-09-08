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

    private function nominee(string $name, int $votes): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $votes, 'vote_count' => $votes,
        ]);
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
