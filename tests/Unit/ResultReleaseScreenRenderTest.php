<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{JudgeRubric, ReleasedStanding, ResultRelease, SnapshotService};
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
        // The community half is scaled by how deep the support in a category actually was
        // (CpiService::depth) — a leader on 89 votes no longer collects what a leader on
        // 1,955 collects. These fixtures use small counts to keep their arithmetic legible,
        // so the mark is set to 1: depth becomes 1.0 and the test is about the thing it is
        // about. The discount has its own tests in CpiServiceTest.
        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['community_full_credit_votes' => 1]);

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

    /** $n real, distinct, verified voters — the vote ROWS reach is counted from. */
    private function backers(int $nominee, int $n, string $tag): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => $nominee, 'category_id' => $this->categoryId,
                'vote_type' => 'standard', 'weight' => 1,
                'voter_email_hash' => \AfricaGates\Services\VoteService::voterHash(
                    $tag . $i . '@x.test'),
            ]);
        }
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
            // Whether a free vote was on offer at all. Read from the same resolver the
            // controller uses rather than hardcoded: this screen prints an ORGANIC column
            // and a per-row "N bought or awarded", and where the free path answers 403
            // both mark the entire field rather than singling anybody out.
            'paid_only'  => \AfricaGates\Services\PaidVoteService::freeVotingDisabled(),
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
            // Whether this cycle has been announced, and where today's arithmetic has
            // moved away from what it was announced as. The service's own output again:
            // this screen draws LIVE by design, which stopped being what a released
            // cycle's public page shows, and the panel it feeds is the only place both
            // figures appear at once.
            'sealed'     => \AfricaGates\Services\ReleasedStanding::divergence(
                                $categories, $this->cycleId),
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
     * ── AND THE CAVEAT CHANGED, WHICH IS WHY THIS TEST IS WORTH READING ─────
     *
     * It used to be that a CPI "only half compares" across categories: the judge half was
     * absolute and the community half was a share of that category's OWN leader, so the
     * two were shares of different things and this box existed to admit it.
     *
     * The community denominator is the whole cycle's now, so the figures ARE comparable
     * and that admission is no longer true. This test kept passing on the old wording for
     * exactly as long as nobody read it — which is the failure mode the box exists to
     * prevent, reproduced in the test that guards it.
     *
     * What has to be on the screen now is the caveat that REMAINS, and it is a different
     * one: the figures compare, and beating one rival is still not beating five. So the
     * field size stays, and the per-row denominator column — identical down the page once
     * the scale is the edition's — is gone in favour of the count that still varies.
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
        $this->assertStringContainsString('comparable', $html,
            'the overall award is published with no word about how the figures compare');
        $this->assertStringNotContainsString('only half compares', $html,
            'the screen still admits a bias that the edition-wide denominator removed, '
            . 'directly above a table of figures produced by the rule that removed it');
        // The lede's own description of the rule, which was wrong on BOTH terms: it said
        // the community half is measured against the largest ORGANIC vote count in the
        // CATEGORY, long after organic stopped deciding anything and after the denominator
        // became the edition's.
        $this->assertStringNotContainsString('largest organic vote count', $html,
            'the one paragraph that tells an operator how to read these numbers still '
            . 'describes a rule the scorer stopped following');
        $this->assertStringContainsString('Field', $html,
            'the size of the field each winner beat is not on the screen');
        $this->assertStringContainsString('had to beat', $html,
            'nothing says what is still uneven between these figures');
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
        $this->assertStringContainsString('900 bought or awarded', $html,
            'the page showed a purchased total with nothing saying it was purchased');

        // The organic column holds the ORGANIC figure. Drawing `vote_count` in both
        // columns leaves the caption "900 bought or awarded" sitting beside two identical
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
            // No cycle selected, so nothing to have been sealed. The controller passes
            // null here for the same reason and `strict_variables` would fail on neither
            // of these two pages being about the seal.
            'sealed' => null,
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
        //
        // TWO scales are named, not one. The community half has two denominators — the
        // largest tally in the cycle and the largest number of backers in it — and they are
        // held by different people as often as not. A row that said "sets the scale" twice,
        // once under each, would be two sentences that read identically and mean different
        // things on the one screen an award is signed off from.
        $this->assertStringContainsString('sets the tally scale', $html);
        $this->assertStringNotContainsString('100% of Grace Abiodun&rsquo;s 400', $html);

        // AND NO REACH SCALE IS INVENTED. These nominees have tallies and no ballot rows,
        // so there are no people to count anywhere in the cycle and the tally takes the
        // whole community half. A page that named a reach denominator here would be naming
        // a number nothing measured.
        $this->assertStringNotContainsString('backer', $html,
            'the screen is reporting backers for a cycle whose ballot holds no rows at all');
    }

    /**
     * AND WHERE THERE ARE BALLOT ROWS, THE BIGGER TERM OF THE TWO IS ON THE PAGE.
     *
     * Seventy per cent of the community half is people, counted from `gates_votes` and
     * measured against the most any nominee in the cycle has. The scorer computed that
     * denominator and named who held it, and no screen printed either — so the working
     * under each nominee explained 135 of the 450 while reading like the whole of it, and
     * the term that actually decides the half was invisible on the screen an award is
     * signed off from.
     */
    public function test_the_reach_term_is_drawn_beside_the_tally_term(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $lead = $this->nominee('Grace Abiodun', 4);
        $half = $this->nominee('Fatima Bello', 2);
        $this->scoreAll($j1, $lead, 9); $this->scoreAll($j2, $lead, 9);
        $this->scoreAll($j1, $half, 8); $this->scoreAll($j2, $half, 8);
        $this->backers($lead, 4, 'lead');
        $this->backers($half, 2, 'half');

        $html = $this->render();

        $this->assertStringContainsString('sets the reach scale', $html,
            'nothing on the page says whose backers the 70% is measured against');
        $this->assertStringContainsString('2 backers of 4', $html,
            'a nominee is shown a tally share and not the count that decides most of '
            . 'their community half');
    }

    /**
     * THE PANEL MARK IS SHOWN AT THE PRECISION THAT DECIDES IT.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * TWO NOMINEES, THE SAME MARK ON SCREEN, DIFFERENT JUDGE HALVES
     * ══════════════════════════════════════════════════════════════════════════
     *
     *     419   7.6/10 from 2 judges
     *     420   7.6/10 from 2 judges
     *
     * The scorer keeps the panel average to TWO places and pays the judge half from that;
     * this screen printed ONE. A panel that averages 7.625 is stored as 7.63 and paid as
     * 7.63, and was drawn as "7.6" — an input that cannot explain the output beside it, on
     * the page whose whole job is showing how a number was reached.
     *
     * One judge marking every criterion 8 and another dropping a single criterion to 7 is
     * the smallest way to land the panel average off a tenth on any rubric — which is the
     * one case a single decimal collapses.
     */
    public function test_the_panel_mark_is_drawn_precisely_enough_to_explain_its_points(): void
    {
        // The EFFECTIVE rubric, exactly as scoreAll() and the scorer resolve it. Reading
        // `gates_judge_criteria` directly returns rows the programme's set does not use, so
        // a card written against those ids is INCOMPLETE and dropped whole — the panel comes
        // out as one judge and the fixture silently stops being about two.
        $ids = [];
        foreach (JudgeRubric::effective($this->programmeId) as $c) {
            if ((int) $c->is_active === 1) $ids[] = (int) $c->id;
        }
        $this->assertGreaterThanOrEqual(2, count($ids), 'the rubric is too small to differ');

        // One judge marks every criterion 8; the other drops a single criterion to 7. On
        // any rubric of two or more that lands the panel average off a tenth, which is the
        // whole case one decimal collapses. Derived rather than hardcoded so a change to
        // the shipped rubric moves the number instead of breaking the test's premise.
        $n  = $this->nominee('Grace Abiodun', 400);
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        foreach ([[$j1, false], [$j2, true]] as [$j, $drop]) {
            foreach ($ids as $i => $cid) {
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $n, 'category_id' => $this->categoryId,
                    'criterion_id' => $cid, 'score' => ($drop && $i === 0) ? 7 : 8,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }

        $row  = ResultRelease::category($this->categoryId)['rows'][0];
        $mark = (float) $row['judge_score'];

        $this->assertSame(2, (int) $row['judges'],
            'one of the two scorecards was dropped, so this is no longer a two-judge panel');

        $this->assertNotSame(round($mark, 1), $mark,
            'the fixture no longer produces a mark whose second decimal a single place '
            . 'would hide, so this test measures nothing');

        $html = (string) preg_replace('~\s+~', ' ', $this->render());

        $this->assertStringContainsString(round($mark, 2) . '/10', $html,
            'the panel mark is drawn at a precision that cannot explain the judge half '
            . 'printed beside it, so two nominees can read as the same mark and be paid '
            . 'differently');
    }

    /**
     * AND THE ±1 THAT SURVIVES SHOWING THE MARK PRECISELY IS EXPLAINED ON THE PAGE.
     *
     * Even at full precision, two nominees on the SAME panel mark can show judge halves a
     * point apart: {@see \AfricaGates\Services\CpiService::split()} rounds the index once
     * and the community half separately, and prints the judge half as the remainder — so
     * the judge column absorbs the difference. That is deliberate, because the alternative
     * is two halves that do not add up to the figure beside them, which defeats the only
     * reason to publish the working.
     *
     * Deliberate and invisible is how a correct number comes to look like a miscount on the
     * screen an award is signed off from. So the page says it.
     */
    public function test_the_page_says_the_judge_half_carries_the_rounding(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $n  = $this->nominee('Grace Abiodun', 400);
        $this->scoreAll($j1, $n, 8); $this->scoreAll($j2, $n, 8);

        $html = (string) preg_replace('~\s+~', ' ', $this->render());

        $this->assertStringContainsString('judge</em> half carries the rounding', $html,
            'two nominees on one panel mark can show judge halves a point apart and the '
            . 'screen offers no reason, which reads as arithmetic nobody can trust');
        $this->assertStringContainsString('add to the index exactly', $html);
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

    // ══ the announcement, beside today's arithmetic ══════════════════════════

    /**
     * The page's prose, whitespace-normalised and with the stylesheet gone.
     *
     * Both halves matter. The `<style>` block names every class this page can draw, so a
     * scan of the whole document finds `rr--out` whether or not a row carries it — this
     * codebase has shipped that mistake four times. And the template wraps its sentences,
     * so a claim about what the screen SAYS must not turn into a claim about where Twig
     * happened to break the line.
     */
    private function prose(): string
    {
        return (string) preg_replace('~\s+~', ' ',
            (string) preg_replace('~<style\b.*?</style>~s', '', $this->render()));
    }

    /** A complete panel at quorum, so the judge half is actually paid. */
    private function panel(int $nominee, int $mark): void
    {
        foreach (['Ada Obi', 'Tunde Cole'] as $name) {
            $this->scoreAll($this->judge($name), $nominee, $mark);
        }
    }

    /**
     * A SEALED CYCLE SAYS SO, AND STOPS PROMISING THAT THIS TABLE IS WHAT PUBLISHES.
     *
     * The lede's opening claim — "it is the same ranking the promotion itself uses, so what
     * is drawn here is what will be published" — was exactly right while every published
     * page recomputed on view, and became a false promise the moment a released cycle began
     * publishing its sealed standing instead. On the page an award is signed off from.
     *
     * This screen still draws live, deliberately: asking the promotion's own comparator is
     * what makes it an audit of a release rather than a report about one. So it has to say
     * which of the two it is showing, and a cycle sitting in `results` with nothing sealed
     * is a third state rather than the second.
     */
    public function test_a_sealed_cycle_is_not_told_that_this_table_is_what_publishes(): void
    {
        $n = $this->nominee('Grace Abiodun', 400);
        $this->backers($n, 4, 'g');
        $this->panel($n, 9);

        $live = $this->prose();
        $this->assertStringContainsString('what is drawn here is what will be published', $live,
            'the fixture is not rendering the unsealed lede, so this test proves nothing');
        $this->assertStringNotContainsString('not what the public pages are showing', $live);

        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update(['status' => 'results']);
        $this->assertGreaterThan(0, (new SnapshotService())->captureRelease($this->cycleId),
            'nothing was sealed, so there is no announcement for the screen to name');

        $sealed = $this->prose();
        $this->assertStringContainsString('not what the public pages are showing', $sealed,
            'the screen still promises that its own live table is what the public sees');
        $this->assertStringContainsString('standing is sealed', $sealed);
        $this->assertStringNotContainsString('what is drawn here is what will be published', $sealed,
            'the false promise is still on the page beside the correction');
    }

    /**
     * AND WHERE TODAY'S RULES MOVE A NOMINEE, BOTH FIGURES ARE ON ONE SCREEN.
     *
     * The support call this exists for begins "my score has changed". The operator taking
     * it had the recomputed figure here and the nominee had the sealed one on their own
     * page, and no screen anywhere held both — so the honest answer, that the result has
     * not changed and the method has, was not available to the person who had to give it.
     */
    public function test_a_rules_change_after_the_seal_shows_both_figures(): void
    {
        $n = $this->nominee('Grace Abiodun', 400);
        $this->backers($n, 4, 'g');
        $this->panel($n, 8);

        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update(['status' => 'results']);
        $announced = ResultRelease::category($this->categoryId)['rows'][0]['cpi'];
        (new SnapshotService())->captureRelease($this->cycleId);

        // The judge half goes back to the curve, which pays an 8.0 far less than straight.
        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['judge_scale' => \AfricaGates\Services\CpiService::SCALE_CURVED]);

        $now = ResultRelease::category($this->categoryId)['rows'][0]['cpi'];
        $this->assertNotSame($announced, $now,
            'the rules did not actually move, so the panel has nothing to report and this '
            . 'test is not about what it says it is about');

        $body = $this->prose();

        // 'differently now' rather than the whole clause: the count agrees its own verb
        // ("1 of them scores", "2 of them score"), and an assertion carrying the plural is
        // one that passes or fails on the size of the fixture.
        $this->assertStringContainsString('differently now', $body,
            'the screen does not say that its figures have parted from the announcement');
        $this->assertStringContainsString('Grace Abiodun', $body);
        $this->assertStringContainsString('>' . $announced . '<', $body,
            'the announced index is nowhere on the screen an operator answers from');
        $this->assertStringContainsString('The published pages are unaffected', $body,
            'an operator cannot tell whether the public result has moved too');
    }

    /**
     * AND A SEAL TODAY'S RULES STILL AGREE WITH SAYS THAT, RATHER THAN NOTHING.
     *
     * The common case, and the one worth stating: silence here would be indistinguishable
     * from a screen that does not check. "These agree" is the answer to the support call
     * as often as "they have moved", and it is the answer nobody can give from a blank.
     */
    public function test_a_seal_that_still_agrees_is_said_out_loud(): void
    {
        $n = $this->nominee('Grace Abiodun', 400);
        $this->backers($n, 4, 'g');
        $this->panel($n, 9);

        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update(['status' => 'results']);
        (new SnapshotService())->captureRelease($this->cycleId);

        $body = $this->prose();

        $this->assertStringContainsString('exactly as announced', $body);
        $this->assertStringNotContainsString('differently now', $body,
            'a cycle nothing has moved is being reported as having moved');
    }
}