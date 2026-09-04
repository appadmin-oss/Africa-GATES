<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{JudgeRubric, ResultRelease};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * The result-release screen, actually rendered.
 *
 * ── WHY THIS EXISTS SEPARATELY FROM ResultReleaseTest ────────────────────────
 *
 * {@see ResultReleaseTest} proves the ARITHMETIC: who is ranked where, who is excluded and
 * why, and that the promotion crowns whoever the screen put first. It then asserts the
 * template is a file on disk — which proves the screen is REACHABLE and says nothing at
 * all about whether it draws.
 *
 * Everything that takes an admin page down here happens at render, and none of it shows
 * up in a syntax check: an undefined key on a row shape that changed, `|round` asked of a
 * null judge mark, an attribute read off the `category` object when the row it came from
 * was deleted. It shows up as a 500 on the one morning somebody opens this page — the
 * morning of a release, which is the single worst day of the year for this screen to be
 * the thing that is broken.
 *
 * ── THE FIXTURE IS THE REAL SERVICE, NOT A HAND-WRITTEN SHAPE ────────────────
 *
 * The rows come from {@see ResultRelease::forCycle()} and the counters from
 * {@see ResultRelease::attention()} — the same two calls the controller makes. A test that
 * rendered a hand-built array would prove the template agrees with the array the test
 * wrote, which is the one shape guaranteed never to reach production. Renaming a key in
 * the service has to break this file, and with a literal fixture it would not.
 *
 * The layout is stubbed. This is a test of the release screen, and pulling in the real
 * admin chrome would make every failure here ambiguous between the two — which is how a
 * render test stops being run.
 */
final class ResultReleaseScreenRenderTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;

    /** The layout's blocks, and nothing else. */
    private const LAYOUT = <<<'TWIG'
        <!doctype html><title>{% block topbar_title %}{% endblock %}</title>
        {% block head_styles %}{% endblock %}
        <main>{% block content %}{% endblock %}</main>
        TWIG;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'render-' . bin2hex(random_bytes(3)),
            'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'judging',
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'primary', 'title' => 'Primary School Principal',
        ]);
        foreach (['impact' => 'Impact', 'rigour' => 'Rigour'] as $slug => $label) {
            DB::table('gates_judge_criteria')->insert([
                'programme_id' => $this->programmeId, 'slug' => $slug,
                'label' => $label, 'weight' => 50, 'is_active' => 1,
            ]);
        }
    }

    private function nominee(string $name, int $organic = 0, int $paid = 0): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $organic, 'vote_count' => $organic + $paid,
        ]);
    }

    private function judge(string $name): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $name, 'is_active' => 1,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'programme_ids' => json_encode([$this->programmeId]),
        ]);
    }

    private function scoreAll(int $judge, int $nominee, int $score): void
    {
        foreach (JudgeRubric::effective($this->programmeId) as $c) {
            if ((int) $c->is_active !== 1) continue;
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judge, 'nominee_id' => $nominee,
                'category_id' => $this->categoryId, 'criterion_id' => (int) $c->id,
                'score' => $score,
                'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
            ]);
        }
    }

    /**
     * Render exactly what the controller renders: the service's own output, under
     * `strict_variables`, so an undefined key is a failure here rather than a blank cell
     * in production.
     */
    private function render(?string $resultsDate = null): string
    {
        $categories = ResultRelease::forCycle($this->cycleId);

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.twig' => self::LAYOUT]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig->render('admin/result-release.twig', [
            'page_title' => 'Result release',
            'admin_page' => 'result-release',
            'cycles'     => [['id' => $this->cycleId, 'year' => 2026, 'status' => 'judging',
                              'edition_label' => null, 'results_date' => $resultsDate,
                              'programme' => 'Incredible Principal Awards']],
            'cycle_id'   => $this->cycleId,
            'cycle'      => ['id' => $this->cycleId, 'year' => 2026, 'status' => 'judging',
                             'edition_label' => null, 'results_date' => $resultsDate,
                             'programme' => 'Incredible Principal Awards'],
            'categories' => $categories,
            'attention'  => ResultRelease::attention($categories),
            // The service's own output here too, and for the same reason as `attention`:
            // a payload this test invents can render a card the controller would never
            // produce. Passed the categories already drawn, exactly as the controller
            // does, so the cycle is not scored twice to reach the same answer.
            'overall'    => ResultRelease::overall($this->cycleId, $categories),
            // What the last recount said, or nothing. Passed here because the controller
            // always passes it and `strict_variables` is on — an undefined key is a failure
            // in this file rather than a blank space in production.
            'recount_said' => null,
            'failed'     => false,
        ]);
    }

    // ══ the overall award ════════════════════════════════════════════════════

    /**
     * The cycle's one award draws, WITH the caveat that makes it defensible.
     *
     * A CPI compares cleanly inside a category and only half compares across them — the
     * judge half is absolute, the community half is a share of that category's own leader.
     * The screen cannot fix that, so it has to say it, next to the figures that let an
     * operator check it: how big each field was and what denominator each winner's
     * community half was measured against.
     */
    public function test_the_overall_winner_draws_with_its_comparability_caveat(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $win = $this->nominee('Yetunde Adeyemi', 1900);
        $two = $this->nominee('Samuel Oyelaran', 900);
        foreach ([$win, $two] as $n) { $this->scoreAll($j1, $n, 7); $this->scoreAll($j2, $n, 7); }

        $html = (string) preg_replace('~\s+~', ' ', $this->render());

        $this->assertStringContainsString('Overall winner', $html);
        $this->assertStringContainsString('Yetunde Adeyemi', $html);
        $this->assertStringContainsString('only half compares', $html,
            'the overall award is published with no word about comparing across categories');
        $this->assertStringContainsString('Cohort max', $html,
            'the denominator behind each contender is not on the screen');
        $this->assertStringContainsString('Field', $html,
            'the size of the field each winner beat is not on the screen');
    }

    // ══ it draws at all ══════════════════════════════════════════════════════

    /** The scores reach the page: every nominee, the index, and the placing. */
    public function test_the_scored_nominees_are_drawn(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $strong = $this->nominee('Grace Abiodun', 420);
        $weak   = $this->nominee('Musa Danjuma', 90);
        $this->scoreAll($j1, $strong, 9); $this->scoreAll($j2, $strong, 9);
        $this->scoreAll($j1, $weak, 4);   $this->scoreAll($j2, $weak, 4);

        $html = $this->render();

        $this->assertStringContainsString('Primary School Principal', $html);
        $this->assertStringContainsString('Grace Abiodun', $html);
        $this->assertStringContainsString('Musa Danjuma', $html);

        // The winner is drawn ABOVE the runner-up. The template sorts nothing itself; if
        // this inverts, the page is disagreeing with the promotion in the one direction
        // an operator would never think to check.
        $this->assertLessThan(strpos($html, 'Musa Danjuma'), strpos($html, 'Grace Abiodun'),
            'the page drew the runner-up above the winner');

        $this->assertStringContainsString('wins', $html);

        // The split that produced these numbers, read off the service rather than written
        // down here: a hardcoded "45%" would fail the day somebody re-weights the
        // programme, which is a supported change and not a defect.
        $w = ResultRelease::category($this->categoryId)['weights'];
        $this->assertStringContainsString(round($w['community'] * 100) . '% community', $html);
        $this->assertStringContainsString(round($w['judge'] * 100) . '% judges', $html);
    }

    /** The organic/paid split reaches the page, because that is the claim being made. */
    public function test_purchased_support_is_drawn_as_not_organic(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $n = $this->nominee('Bought Support', 100, 900);
        $this->scoreAll($j1, $n, 8); $this->scoreAll($j2, $n, 8);

        $html = $this->render();

        $this->assertStringContainsString('1,000', $html, 'the public vote total is not drawn');
        $this->assertStringContainsString('900 not organic', $html,
            'the page showed a purchased total with nothing saying it was purchased');

        // The organic column holds the ORGANIC figure. Drawing `vote_count` in both
        // columns leaves the caption "900 not organic" sitting beside two identical
        // numbers that contradict it, and the page still looks entirely plausible.
        $this->assertStringContainsString('<td class="ja-num">100</td>', $html,
            'the organic column drew the purchased total');
    }

    // ══ the two sentences that only ever reached a log ════════════════════════

    /** A dead heat is drawn as a banner, not resolved silently by id. */
    public function test_a_dead_heat_is_drawn(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        foreach (['Chidi Okafor', 'Amara Nwosu'] as $name) {
            $n = $this->nominee($name, 60);
            $this->scoreAll($j1, $n, 8);
            $this->scoreAll($j2, $n, 8);
        }

        $html = $this->render();

        $this->assertStringContainsString('Dead heat for first place', $html);
        $this->assertStringContainsString('needs a person to decide', $html);
    }

    /** A category the quorum blocks says so on the page. */
    public function test_a_category_that_crowns_nobody_says_so(): void
    {
        $j = $this->judge('Ada Obi');
        $n = $this->nominee('Half Judged', 50);
        $this->scoreAll($j, $n, 9);   // one judge, below quorum

        $html = $this->render();

        $this->assertStringContainsString('This category crowns nobody', $html);
        $this->assertStringContainsString('quorum', $html);

        // The excluded nominee is still listed with the reason beside them.
        $this->assertStringContainsString('Half Judged', $html);
        $this->assertStringContainsString(ResultRelease::OUT_QUORUM, $html);

        // A nominee whose judge half was not counted must not be drawn as if it had been.
        $this->assertStringContainsString('judge half not counted', $html);
    }

    /** A nominee off the published shortlist is drawn with that reason. */
    public function test_an_unshortlisted_nominee_is_drawn_as_excluded(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $on  = $this->nominee('On The List', 40);
        $off = $this->nominee('Off The List', 100);
        $this->scoreAll($j1, $on, 6);   $this->scoreAll($j2, $on, 6);
        $this->scoreAll($j1, $off, 10); $this->scoreAll($j2, $off, 10);

        $this->publishShortlist($this->cycleId, $this->categoryId, [$on]);

        $html = $this->render();

        $this->assertStringContainsString(ResultRelease::OUT_SHORTLIST, $html);
        $this->assertStringContainsString('shortlisted field of 1', $html);
    }

    // ══ the states that are not a table ══════════════════════════════════════

    /**
     * "Nothing scored yet" and "the query failed" must not look the same.
     *
     * They are opposite facts and an empty table is how a screen says both at once.
     */
    public function test_an_unscored_cycle_and_a_failed_read_are_different_pages(): void
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.twig' => self::LAYOUT]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $base = [
            'page_title' => 'Result release', 'admin_page' => 'result-release',
            'cycles' => [], 'cycle_id' => 0, 'cycle' => null,
            'categories' => [], 'attention' => ResultRelease::attention([]),
        ];

        $empty  = $twig->render('admin/result-release.twig', $base + ['failed' => false]);
        $failed = $twig->render('admin/result-release.twig', $base + ['failed' => true]);

        $this->assertStringContainsString('Nothing has been scored in this cycle', $empty);
        $this->assertStringNotContainsString('could not be read', $empty);

        $this->assertStringContainsString('The scores could not be read', $failed);
        $this->assertStringNotContainsString('Nothing has been scored', $failed,
            'a failed read was drawn as an empty cycle — opposite facts, same page');
    }

    /**
     * A thin margin is called out, because it is the result most likely to be challenged.
     */
    public function test_a_thin_margin_is_called_out(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        // Same marks, one organic vote apart: the index separates them by almost nothing.
        $a = $this->nominee('Barely Ahead', 101);
        $b = $this->nominee('Barely Behind', 100);
        $this->scoreAll($j1, $a, 8); $this->scoreAll($j2, $a, 8);
        $this->scoreAll($j1, $b, 8); $this->scoreAll($j2, $b, 8);

        $c = ResultRelease::category($this->categoryId);
        $this->assertNotNull($c['margin']);
        $this->assertLessThanOrEqual(10, $c['margin'], 'the fixture stopped being a thin margin');

        $html = $this->render();
        $this->assertStringContainsString('apart on a', $html);
        $this->assertStringContainsString('judging-audit', $html,
            'the thin-margin note must point at the screen that says whether a mark moved');
    }

    // ══ the traps this codebase has shipped before ═══════════════════════════

    /**
     * §CSP · the admin policy has no `'unsafe-inline'`, so an inline handler is dead code
     * that looks alive. The cycle picker must submit through the delegated listener.
     */
    public function test_the_cycle_picker_uses_the_delegated_listener(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/result-release.twig');

        $this->assertStringContainsString('data-ag-do="submit-form"', $tpl);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit)=/i', $tpl,
            'an inline handler cannot run under the admin CSP');

        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/js/admin.js');
        $this->assertStringContainsString('submit-form', $js,
            'the picker names a behaviour nothing in admin.js implements');
    }

    // ══ what the page says will happen ═══════════════════════════════════════

    /**
     * THE SENTENCE THE PAGE WAS MISSING, and the reason it read as confusing.
     *
     * "Result release" and "what the release WILL do" describe a screen with a button on
     * it, and there is none: nobody releases anything. `CycleMaterialiser` advances the
     * cycle on its results_date, from the unattended maintenance run, and crowns the
     * winners drawn below. So the page was asking somebody to review a decision without
     * telling them it takes effect by itself, or when — and "this one needs a person to
     * decide" beside a dead heat means something quite different once you know there is a
     * date on it.
     *
     * The date was already handed to this template and nothing read it.
     */
    public function test_the_page_says_the_release_happens_by_itself_and_when(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $n  = $this->nominee('Grace Abiodun', 420);
        $this->scoreAll($j1, $n, 9); $this->scoreAll($j2, $n, 9);

        $html = $this->render('2026-12-01 09:00:00');

        $this->assertStringContainsString('Nobody presses anything', $html);
        $this->assertStringContainsString('1 December 2026', $html,
            'the page does not say WHEN this becomes a published result');
    }

    /** And says so differently when there is no date, rather than saying nothing. */
    public function test_a_cycle_with_no_results_date_is_told_it_will_not_publish(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $n  = $this->nominee('Grace Abiodun', 420);
        $this->scoreAll($j1, $n, 9); $this->scoreAll($j2, $n, 9);

        // Whitespace-collapsed: the sentence wraps in the template, so a literal match on
        // the phrase asserts the line breaks rather than the words.
        $html = (string) preg_replace('/\s+/', ' ', $this->render());

        $this->assertStringContainsString('has no results date set', $html);
        $this->assertStringContainsString('set it on the cycle', $html,
            'told it will not publish, and not told what to do about it');
        $this->assertStringNotContainsString('This cycle turns into a published result on', $html);
    }

    /**
     * The working reaches the page, and adds up on the page.
     *
     * The service holding halves that sum to the index is worth nothing if the template
     * prints a different pair, and the whole reason to print them is that somebody can
     * check the arithmetic without leaving the screen.
     */
    public function test_the_working_is_drawn_beside_the_index(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $lead = $this->nominee('Grace Abiodun', 400);
        $half = $this->nominee('Fatima Bello', 200);
        $this->scoreAll($j1, $lead, 9); $this->scoreAll($j2, $lead, 9);
        $this->scoreAll($j1, $half, 8); $this->scoreAll($j2, $half, 8);

        $html = $this->render();

        $c  = ResultRelease::category($this->categoryId);
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        foreach ($by as $name => $r) {
            $this->assertStringContainsString(
                $r['community_points'] . ' + ' . $r['judge_points'], $html,
                "{$name}'s working is not on the page");
        }

        // And the leader is not told they have 100% of their own votes.
        $this->assertStringContainsString('sets the scale', $html);
        $this->assertStringNotContainsString('100% of Grace Abiodun&rsquo;s 400', $html);
    }

    /**
     * The rule marks WHERE THE AWARD FALLS, so it may only be drawn with a row below it.
     *
     * Applied to rank 2 unconditionally, it put a heavy line under the last row of every
     * two-nominee category — a boundary with nothing on the other side, which reads as a
     * total rather than a cut.
     */
    public function test_the_cut_rule_is_not_drawn_under_the_last_row(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        foreach (['Grace Abiodun', 'Fatima Bello'] as $i => $name) {
            $n = $this->nominee($name, 400 - $i * 100);
            $this->scoreAll($j1, $n, 9 - $i); $this->scoreAll($j2, $n, 9 - $i);
        }

        // The BODY, not the stylesheet: `.ja-t tr.rr--cut` is defined in the page's own
        // <style> block, so a scan of the whole document finds the class name whether or
        // not any row carries it. This codebase has shipped that mistake four times.
        $body = (string) preg_replace('~<style\b.*?</style>~s', '', $this->render());

        $this->assertStringNotContainsString('rr--cut', $body,
            'a two-nominee category drew the award line under its own last row');
    }
}
