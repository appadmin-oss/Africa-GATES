<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{JudgeScorecard, NomineeScoringService, ResultRelease};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\{ArrayLoader, ChainLoader, FilesystemLoader};

/**
 * The marks themselves — the thing this platform could not show anybody.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_judge_criteria_scores` is one row per judge per nominee per criterion, and every
 * screen built on it showed arithmetic OVER those rows and never a row: an index, a panel
 * average, a lean against the rest of the panel, a criterion's mean and range, the spread
 * between the highest and lowest judge, a bias score across groups.
 *
 * So "what did the judges give her" — the first question anybody asks about an award, and
 * the one a nominee asks when they lose — had no screen. An organiser could quote a
 * weighted average to two decimal places and could not name one number a judge wrote down.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT IS NOT A SELECT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Three rules sit between a stored mark and the average that decides an award, and each
 * silently drops marks: a recused judge, a judge taken off the panel, and a scorecard
 * short of one criterion. A screen that read the table would disagree with the result and
 * look broken while being right about the rows.
 *
 * The properties held here are the ones that make the screen trustworthy: that every mark
 * appears, that the ones which do not count say WHY, and — the load-bearing one — that
 * the figure at the foot of this page is the figure in the release, because it is the
 * same call rather than a second piece of arithmetic that agrees today.
 */
final class JudgeScorecardTest extends TestCase
{
    private int $prog = 0;
    private int $cycle = 0;
    private int $cat = 0;
    /** @var list<int> */
    private array $crit = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->prog, 'year' => 2026, 'status' => 'judging',
            'edition_label' => '2026 Edition', 'results_date' => '2026-11-20 18:00:00',
        ]);
        // The inherited global rubric is retired, so "complete" means the three criteria
        // this programme actually asks. Otherwise every card here is part-marked and the
        // tests below would all pass for the wrong reason.
        DB::table('gates_judge_criteria')->whereNull('programme_id')->update(['is_active' => 0]);
        foreach (['impact' => ['Impact on learners', 40], 'rigour' => ['Academic rigour', 35],
                  'leadership' => ['Leadership', 25]] as $slug => [$label, $w]) {
            $this->crit[] = (int) DB::table('gates_judge_criteria')->insertGetId([
                'programme_id' => $this->prog, 'slug' => $slug, 'label' => $label,
                'weight' => $w, 'is_active' => 1, 'sort_order' => count($this->crit),
            ]);
        }
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycle, 'slug' => 'primary', 'title' => 'Primary', 'sort_order' => 1,
        ]);
    }

    private function nominee(string $name, int $organic = 0): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->cat, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $organic, 'vote_count' => $organic,
        ]);
    }

    private function judge(string $name, int $active = 1): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $name, 'is_active' => $active,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'programme_ids' => json_encode([$this->prog]),
        ]);
    }

    /** @param array<int,int> $marks criterion index => mark; omit an index to leave it blank */
    private function score(int $judge, int $nominee, array $marks): void
    {
        foreach ($this->crit as $i => $cid) {
            if (!array_key_exists($i, $marks)) continue;
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judge, 'nominee_id' => $nominee, 'category_id' => $this->cat,
                'criterion_id' => $cid, 'score' => $marks[$i],
                'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
            ]);
        }
    }

    private function recuse(int $judge): void
    {
        DB::table('gates_judge_coi')->insert([
            'judge_id' => $judge, 'programme_id' => $this->prog,
            'reason' => 'Sits on the school board', 'created_at' => '2026-11-05 10:00:00',
        ]);
    }

    /** @return array<string,array<string,mixed>> judge name => row */
    private function byJudge(array $card): array
    {
        $out = [];
        foreach ($card['judges'] as $j) $out[$j['judge']] = $j;
        return $out;
    }

    // ══ the marks are visible at all ═════════════════════════════════════════

    /**
     * The number each judge wrote, on each criterion. There was no screen for this.
     */
    public function test_every_mark_is_shown_with_the_judge_and_the_criterion(): void
    {
        $n  = $this->nominee('Grace Abiodun');
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $this->score($j1, $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($j2, $n, [0 => 8, 1 => 9, 2 => 8]);

        $card = JudgeScorecard::forNominee($n);
        $by   = $this->byJudge($card);

        $this->assertCount(2, $card['judges']);
        $this->assertSame([9, 9, 8], array_values($by['Ada Obi']['marks']));
        $this->assertSame([8, 9, 8], array_values($by['Tunde Cole']['marks']));

        // The rubric in ballot order, with what each criterion is worth — a 4 on a
        // criterion carrying a quarter of the mark is a different fact from a 4 on one
        // carrying a twentieth, and the mark alone does not say which.
        $this->assertSame(['Impact on learners', 'Academic rigour', 'Leadership'],
            array_column($card['criteria'], 'label'));
        $this->assertSame(40, $card['criteria'][0]['weight']);
        $this->assertGreaterThan(0, $card['criteria'][0]['share']);
    }

    /** Each judge's own weighted average, which is what the panel is a mean of. */
    public function test_each_judge_carries_their_own_weighted_average(): void
    {
        $n = $this->nominee('Grace Abiodun');
        $this->score($this->judge('Ada Obi'), $n, [0 => 9, 1 => 9, 2 => 8]);

        // (9×40 + 9×35 + 8×25) / 100 = 8.75
        $this->assertSame(8.75, $this->byJudge(JudgeScorecard::forNominee($n))['Ada Obi']['average']);
    }

    // ══ and the ones that did not count ══════════════════════════════════════

    /**
     * A RECUSED JUDGE'S MARKS ARE SHOWN, AND SHOWN AS NOT COUNTING.
     *
     * Recusal drops every mark already given — not merely refuses further ones — so a
     * nominee can have four scorecards on record and a panel of two. A screen that
     * quietly rendered only the counting ones would be the same failure this whole
     * feature exists to fix: the reader would be back to a number they cannot check,
     * and one that disagrees with the row count they can see elsewhere.
     */
    public function test_a_recused_judges_marks_are_shown_and_marked_as_not_counting(): void
    {
        $n  = $this->nominee('Grace Abiodun');
        $ok = $this->judge('Ada Obi');
        $no = $this->judge('Ngozi Eze');

        $this->score($ok, $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($no, $n, [0 => 4, 1 => 5, 2 => 4]);
        $this->recuse($no);

        $card = JudgeScorecard::forNominee($n);
        $by   = $this->byJudge($card);

        $this->assertCount(2, $card['judges'], 'the recused judge vanished from the page');
        $this->assertFalse($by['Ngozi Eze']['counts']);
        $this->assertSame(NomineeScoringService::NOT_COUNTED_RECUSED, $by['Ngozi Eze']['why']);
        $this->assertSame([4, 5, 4], array_values($by['Ngozi Eze']['marks']),
            'the marks a recused judge actually wrote are what an appeal is about');

        // Their assessment is still computed, so the screen can say what it WOULD have
        // been — and the panel still excludes it.
        $this->assertNotNull($by['Ngozi Eze']['average']);
        $this->assertSame(1, $card['panel']['counted']);
        $this->assertSame(1, $card['panel']['ignored']);
        $this->assertSame(8.75, $card['panel']['average'], 'a recused mark moved the panel');
    }

    /** A judge taken off the panel: the same, and it must not be called "recused". */
    public function test_a_removed_judge_is_named_as_removed_and_not_as_recused(): void
    {
        $n  = $this->nominee('Grace Abiodun');
        $this->score($this->judge('Ada Obi'), $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($this->judge('Musa Danjuma', 0), $n, [0 => 10, 1 => 10, 2 => 10]);

        $by = $this->byJudge(JudgeScorecard::forNominee($n));

        $this->assertFalse($by['Musa Danjuma']['counts']);
        $this->assertSame(NomineeScoringService::NOT_COUNTED_REMOVED, $by['Musa Danjuma']['why'],
            'the wrong reason on the screen somebody reads during an appeal');
    }

    /**
     * A part-marked card is dropped WHOLE, not reweighted onto what was answered.
     *
     * Different from the reweighting the questionnaire does, and worth stating on the
     * page: a judge who marked two criteria of three contributes nothing at all rather
     * than a two-thirds opinion.
     */
    public function test_a_part_marked_card_is_shown_as_dropped_whole(): void
    {
        $n = $this->nominee('Grace Abiodun');
        $this->score($this->judge('Ada Obi'), $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($this->judge('Yetunde Cole'), $n, [0 => 7, 2 => 6]);   // rigour blank

        $card = JudgeScorecard::forNominee($n);
        $by   = $this->byJudge($card);

        $this->assertFalse($by['Yetunde Cole']['counts']);
        $this->assertSame(NomineeScoringService::NOT_COUNTED_INCOMPLETE, $by['Yetunde Cole']['why']);
        $this->assertSame(2, $by['Yetunde Cole']['covered']);
        $this->assertSame(3, $by['Yetunde Cole']['required']);
        $this->assertNull($by['Yetunde Cole']['average'],
            'a part-marked card was given an average, which is the reweighting this '
            . 'deliberately does not do');

        // The marks they DID write are still on the page.
        $this->assertSame(7, $by['Yetunde Cole']['marks'][$this->crit[0]]);
        $this->assertArrayNotHasKey($this->crit[1], $by['Yetunde Cole']['marks']);
    }

    /** Removal beats incompleteness: a removed judge's part-marked card says removed. */
    public function test_a_reason_about_the_judge_beats_a_reason_about_the_card(): void
    {
        $n = $this->nominee('Grace Abiodun');
        $this->score($this->judge('Musa Danjuma', 0), $n, [0 => 10]);

        $by = $this->byJudge(JudgeScorecard::forNominee($n));

        $this->assertSame(NomineeScoringService::NOT_COUNTED_REMOVED, $by['Musa Danjuma']['why'],
            'a removed judge\'s card was reported as merely unfinished');
    }

    // ══ the number at the foot is the number in the award ════════════════════

    /**
     * THE PROPERTY THAT MAKES THE PAGE WORTH HAVING.
     *
     * The whole point of a scorecard is that the panel average it prints is the one the
     * award used. Recomputing it from the marks it has just rendered would make the two
     * agree by coincidence — until the day one of the three exclusion rules changed, at
     * which point the page would confidently show a different number from the result and
     * there would be no way to tell which was wrong.
     */
    public function test_the_panel_average_is_the_scorers_own_and_not_a_second_opinion(): void
    {
        $n  = $this->nominee('Grace Abiodun', 400);
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $j3 = $this->judge('Ngozi Eze');

        $this->score($j1, $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($j2, $n, [0 => 8, 1 => 9, 2 => 8]);
        $this->score($j3, $n, [0 => 4, 1 => 5, 2 => 4]);
        $this->recuse($j3);

        $card = JudgeScorecard::forNominee($n);
        $stat = (new NomineeScoringService())->judgeStatsFor([$n])[$n];

        $this->assertSame($stat['avg'], $card['panel']['average']);
        $this->assertSame($stat['judges'], $card['panel']['counted']);
    }

    /**
     * And the points it contributes are the points the release prints.
     *
     * This is the link the screen is reached by: an operator clicks the judge half of an
     * index and expects to land on the marks that made it. If the two figures differ by
     * so much as a point, the link is a lie.
     */
    public function test_the_points_here_are_the_judge_half_on_the_release(): void
    {
        $n  = $this->nominee('Grace Abiodun', 400);
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $this->score($j1, $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($j2, $n, [0 => 8, 1 => 9, 2 => 8]);

        $card = JudgeScorecard::forNominee($n);

        $row = null;
        foreach (ResultRelease::category($this->cat)['rows'] as $r) {
            if ($r['nominee_id'] === $n) $row = $r;
        }

        $this->assertNotNull($row);
        $this->assertSame($row['judge_points'], $card['panel']['points'],
            'the scorecard and the release disagree about the same nominee');
        $this->assertSame(round($row['judge_score'], 2), $card['panel']['average']);
    }

    /**
     * Below quorum the judge half is ABSENT, not zero — and the page must say absent.
     *
     * Printing 0 points would read as "the panel gave them nothing", which is a verdict.
     * What actually happened is that not enough judges looked.
     */
    public function test_below_quorum_the_page_reports_no_points_rather_than_zero(): void
    {
        $n = $this->nominee('Prof. Olusegun Ade', 900);
        $this->score($this->judge('Ada Obi'), $n, [0 => 10, 1 => 10, 2 => 10]);

        $card = JudgeScorecard::forNominee($n);

        $this->assertFalse($card['panel']['eligible']);
        $this->assertSame(1, $card['panel']['counted']);
        $this->assertGreaterThan(1, $card['panel']['quorum']);
        $this->assertNull($card['panel']['points'],
            'a nominee nobody finished judging was shown as having earned zero');
        // The marks are still there. That is the whole point of the page.
        $this->assertCount(1, $card['judges']);
    }

    // ══ it is inert, and it is reachable ═════════════════════════════════════

    public function test_reading_a_scorecard_changes_nothing(): void
    {
        $n = $this->nominee('Grace Abiodun');
        $j = $this->judge('Ada Obi');
        $this->score($j, $n, [0 => 9, 1 => 9, 2 => 8]);

        $before = DB::table('gates_judge_criteria_scores')->orderBy('criterion_id')
            ->pluck('score')->all();
        JudgeScorecard::forNominee($n);

        $this->assertSame($before, DB::table('gates_judge_criteria_scores')
            ->orderBy('criterion_id')->pluck('score')->all());
        $this->assertSame('approved',
            (string) DB::table('gates_nominees')->where('id', $n)->value('status'));
    }

    /** A nominee nobody has scored is a FINDING, not an error. */
    public function test_a_nominee_nobody_has_scored_says_so_rather_than_failing(): void
    {
        $card = JudgeScorecard::forNominee($this->nominee('Unread'));

        $this->assertNotNull($card['nominee']);
        $this->assertSame([], $card['judges']);
        $this->assertNull($card['panel']['average']);
    }

    public function test_an_id_nothing_matches_returns_an_empty_card_rather_than_throwing(): void
    {
        $card = JudgeScorecard::forNominee(987654);

        $this->assertNull($card['nominee']);
        $this->assertSame([], $card['judges']);
    }

    /**
     * §18 · a mechanism with no route in.
     *
     * And here the route is only half of it: the page answers a question somebody is
     * already asking on two OTHER screens, so it has to be reachable from where the
     * question is asked rather than from a menu nobody thinks to open.
     */
    public function test_the_scorecard_is_reachable_from_where_the_question_is_asked(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertStringContainsString("'/scorecard/{id:[0-9]+}'",
            (string) file_get_contents($root . '/src/routes.php'), 'no route reaches it');
        $this->assertFileExists($root . '/templates/admin/scorecard.twig');

        foreach (['result-release', 'judging-audit'] as $from) {
            $this->assertStringContainsString('/admin/scorecard/',
                (string) file_get_contents($root . '/templates/admin/' . $from . '.twig'),
                "the {$from} screen asks the question and does not link to the answer");
        }
    }

    // ══ it draws ═════════════════════════════════════════════════════════════

    private const LAYOUT = <<<'TWIG'
        <!doctype html><title>{% block topbar_title %}{% endblock %}</title>
        {% block head_styles %}{% endblock %}
        <main>{% block content %}{% endblock %}</main>
        TWIG;

    /**
     * Rendered under `strict_variables`, so a key the service stopped returning is a
     * failure here rather than a blank cell on the screen somebody opens during an appeal.
     */
    private function render(int $nomineeId): string
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.twig' => self::LAYOUT]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig->render('admin/scorecard.twig', [
            'page_title' => 'Scorecard',
            'admin_page' => 'result-release',
            'card'       => JudgeScorecard::forNominee($nomineeId),
            'failed'     => false,
        ]);
    }

    /** Strip the stylesheet: `.sc--out` and friends are defined there, and a scan of the
     *  whole document finds a class name whether or not a row carries it. */
    private function body(int $nomineeId): string
    {
        return (string) preg_replace('~<style\b.*?</style>~s', '', $this->render($nomineeId));
    }

    public function test_the_marks_reach_the_page(): void
    {
        $n = $this->nominee('Grace Abiodun', 400);
        $this->score($this->judge('Ada Obi'), $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($this->judge('Tunde Cole'), $n, [0 => 8, 1 => 9, 2 => 8]);

        $html = $this->body($n);

        foreach (['Ada Obi', 'Tunde Cole', 'Impact on learners', 'Academic rigour',
                  'Leadership', '8.75', '8.35'] as $wanted) {
            $this->assertStringContainsString($wanted, $html, "{$wanted} is not on the page");
        }

        // And the number the award uses, with what it is worth in the index.
        $card = JudgeScorecard::forNominee($n);
        $this->assertStringContainsString((string) $card['panel']['average'], $html);
        $this->assertStringContainsString((string) $card['panel']['points'], $html);
    }

    /** A card that did not count is DRAWN, greyed, with its reason in words. */
    public function test_a_card_that_did_not_count_is_drawn_with_its_reason(): void
    {
        $n  = $this->nominee('Grace Abiodun', 400);
        $no = $this->judge('Ngozi Eze');
        $this->score($this->judge('Ada Obi'), $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($no, $n, [0 => 4, 1 => 5, 2 => 4]);
        $this->recuse($no);

        $html = $this->body($n);

        $this->assertStringContainsString('Ngozi Eze', $html, 'the recused judge was hidden');
        $this->assertStringContainsString('recused', $html);
        $this->assertStringContainsString('sc--out', $html,
            'the card that did not count is drawn identically to the ones that did');
        $this->assertStringContainsString('did not count', $html,
            'the page does not say how many cards were ignored');
    }

    /** With nothing ignored, the page does not warn about nothing. */
    public function test_a_clean_panel_gets_no_warning(): void
    {
        $n = $this->nominee('Grace Abiodun', 400);
        $this->score($this->judge('Ada Obi'), $n, [0 => 9, 1 => 9, 2 => 8]);
        $this->score($this->judge('Tunde Cole'), $n, [0 => 8, 1 => 9, 2 => 8]);

        $html = $this->body($n);

        $this->assertStringNotContainsString('did not count', $html);
        $this->assertStringNotContainsString('sc--out', $html);
    }

    /** A nominee nobody scored draws the finding, not an empty table or an error. */
    public function test_an_unscored_nominee_draws_the_finding(): void
    {
        $html = $this->body($this->nominee('Unread'));

        $this->assertStringContainsString('Nobody has scored this nominee', $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
