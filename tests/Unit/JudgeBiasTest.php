<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\JudgeBiasService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Detecting a judge who scores systematically differently by group.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE REALLY GUARDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Not "does the arithmetic run". The arithmetic is a mean and a subtraction. What matters is
 * that the three ways this measurement can be quietly, plausibly WRONG are closed — because
 * each of them produces a confident finding about a named person that is not true:
 *
 *  1 · CONFOUNDING BY ASSIGNMENT. Compare raw scores by country and you discover that the
 *      judge given the stronger entries scores higher. That is the assignment, not bias.
 *      Closed by differencing against the other judges on the SAME nominee and criterion.
 *
 *  2 · MISTAKING STRICTNESS FOR BIAS. A judge half a point below everybody, everywhere, is
 *      strict. Reporting them buries the real finding under a dozen harmless ones. Closed
 *      by measuring each group against that judge's OWN baseline.
 *
 *  3 · READING NOISE AS A PATTERN. Three scores in a group tells you nothing, and hundreds
 *      of comparisons will throw up striking numbers on a panel behaving perfectly. Closed
 *      by a floor on n, and by returning the comparison count so the screen can print it.
 *
 * The last one has no clean unit test for "is the caveat convincing", so what is asserted
 * instead is that the denominator is always present and always travels with the numerator.
 */
final class JudgeBiasTest extends TestCase
{
    private int $cycleId = 0;
    private int $categoryId = 0;
    /** @var array<string,int> */
    private array $criteria = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_judge_criteria_scores')->delete();

        $progId = (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Bias Test Programme', 'slug' => 'bias-' . bin2hex(random_bytes(3)),
            'is_active' => 1, 'sort_order' => 60,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $progId, 'year' => 2026, 'status' => 'judging',
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'title' => 'Community Impact',
            'slug' => 'impact-' . bin2hex(random_bytes(3)),
        ]);

        foreach (['Evidence of impact', 'Originality'] as $name) {
            $this->criteria[$name] = (int) DB::table('gates_judge_criteria')->insertGetId([
                'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(3)),
                'label' => $name, 'is_active' => 1, 'sort_order' => 1,
            ]);
        }
    }

    private function judge(string $name): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name))
                . '-' . bin2hex(random_bytes(3)) . '@example.test',
            'is_active' => 1,
        ]);
    }

    private function nominee(string $name, string $country): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name,
            'country_code' => $country, 'status' => 'approved',
        ]);
    }

    private function score(int $judgeId, int $nomineeId, string $criterion, int $score): void
    {
        DB::table('gates_judge_criteria_scores')->insert([
            'judge_id' => $judgeId, 'nominee_id' => $nomineeId,
            'category_id' => $this->categoryId, 'criterion_id' => $this->criteria[$criterion],
            'score' => $score,
        ]);
    }

    /**
     * A panel of three, `$n` nominees per country, everybody agreeing — except that
     * `$biased` shifts their score by `$shift` on entries from `$target`.
     *
     * @return array{judges:array<string,int>}
     */
    private function panel(int $n, string $biased = '', int $shift = 0, string $target = 'KE'): array
    {
        $judges = [];
        foreach (['Amina', 'Bola', 'Chidi'] as $who) $judges[$who] = $this->judge($who);

        $i = 0;
        foreach (['NG', 'KE'] as $country) {
            for ($k = 0; $k < $n; $k++) {
                $nom = $this->nominee('Nominee ' . (++$i), $country);
                // A different base per nominee, so the test is not accidentally passing on
                // a dataset where every nominee is identical.
                $base = 5 + ($i % 3);

                foreach (array_keys($this->criteria) as $criterion) {
                    foreach ($judges as $who => $jid) {
                        $s = $base;
                        if ($who === $biased && $country === $target) $s += $shift;
                        $this->score($jid, $nom, $criterion, max(0, min(10, $s)));
                    }
                }
            }
        }

        return ['judges' => $judges];
    }

    // ══ the measurement ══════════════════════════════════════════════════════

    public function test_a_panel_that_agrees_produces_no_findings(): void
    {
        $this->panel(6);

        $r = JudgeBiasService::forCycle($this->cycleId);

        $this->assertSame([], $r['findings']);
        $this->assertGreaterThan(0, $r['comparisons'], 'comparisons were actually made');
        $this->assertStringContainsString('expected result', $r['note']);
    }

    public function test_a_judge_leaning_against_one_country_is_found_and_named(): void
    {
        $this->panel(6, 'Bola', -2, 'KE');

        $r = JudgeBiasService::forCycle($this->cycleId);
        $this->assertNotSame([], $r['findings']);

        $top = $r['findings'][0];
        $this->assertSame('Bola', $top['judge']);
        $this->assertSame('country', $top['axis']);
        $this->assertSame('KE', $top['group']);
        $this->assertSame('lower', $top['direction']);
        // Bola is 2 below on KE and level on NG, so their own baseline is about −1 and the
        // KE group sits about −1 from it.
        $this->assertLessThan(0, $top['relative']);
        $this->assertGreaterThan(JudgeBiasService::MIN_EFFECT, abs($top['relative']));
    }

    public function test_the_finding_names_the_group_and_not_the_judges_own_strictness(): void
    {
        // 2 · MISTAKING STRICTNESS FOR BIAS. Bola marks EVERYBODY down by two — every
        // country, every criterion. That is a strict judge and there is nothing to find.
        $judges = [];
        foreach (['Amina', 'Bola', 'Chidi'] as $who) $judges[$who] = $this->judge($who);

        $i = 0;
        foreach (['NG', 'KE'] as $country) {
            for ($k = 0; $k < 6; $k++) {
                $nom = $this->nominee('Nominee ' . (++$i), $country);
                foreach (array_keys($this->criteria) as $criterion) {
                    foreach ($judges as $who => $jid) {
                        $this->score($jid, $nom, $criterion, $who === 'Bola' ? 5 : 7);
                    }
                }
            }
        }

        $r = JudgeBiasService::forCycle($this->cycleId);

        $this->assertSame([], $r['findings'],
            'a judge who marks everybody down is strict, not biased');
    }

    public function test_a_judge_given_stronger_entries_is_not_reported_as_biased(): void
    {
        // 1 · CONFOUNDING BY ASSIGNMENT — the failure mode that makes a naive version of
        // this feature actively harmful. Every judge agrees exactly; the KE nominees are
        // simply better. A comparison of raw scores by country would report all three
        // judges as favouring KE.
        $judges = [];
        foreach (['Amina', 'Bola', 'Chidi'] as $who) $judges[$who] = $this->judge($who);

        $i = 0;
        foreach (['NG' => 4, 'KE' => 9] as $country => $quality) {
            for ($k = 0; $k < 6; $k++) {
                $nom = $this->nominee('Nominee ' . (++$i), $country);
                foreach (array_keys($this->criteria) as $criterion) {
                    foreach ($judges as $jid) $this->score($jid, $nom, $criterion, $quality);
                }
            }
        }

        $r = JudgeBiasService::forCycle($this->cycleId);

        $this->assertSame([], $r['findings'],
            'differencing against the same nominee must remove the nominee’s own quality');
    }

    public function test_a_lean_on_one_criterion_is_found_too(): void
    {
        // Often the real finding, and invisible to an anomaly detector: a judge who marks
        // everybody down on "evidence of impact" and normally on everything else.
        $judges = [];
        foreach (['Amina', 'Bola', 'Chidi'] as $who) $judges[$who] = $this->judge($who);

        for ($i = 1; $i <= 8; $i++) {
            $nom = $this->nominee('Nominee ' . $i, 'NG');
            foreach (array_keys($this->criteria) as $criterion) {
                foreach ($judges as $who => $jid) {
                    $s = 7;
                    if ($who === 'Chidi' && $criterion === 'Evidence of impact') $s = 4;
                    $this->score($jid, $nom, $criterion, $s);
                }
            }
        }

        $r = JudgeBiasService::forCycle($this->cycleId);

        $hit = array_values(array_filter($r['findings'],
            fn (array $f): bool => $f['axis'] === 'criterion' && $f['judge'] === 'Chidi'));

        $this->assertNotSame([], $hit);
        $this->assertSame('Evidence of impact', $hit[0]['group']);
        $this->assertSame('lower', $hit[0]['direction']);
    }

    // ══ refusing to say what cannot be said ══════════════════════════════════

    public function test_a_group_too_small_to_measure_is_named_rather_than_dropped(): void
    {
        // 3 · READING NOISE AS A PATTERN. Two nominees from one country tells you nothing —
        // but if the group vanishes silently, the absence of a finding reads as a clean bill
        // of health for a group nobody could actually test.
        $judges = [];
        foreach (['Amina', 'Bola', 'Chidi'] as $who) $judges[$who] = $this->judge($who);

        $i = 0;
        foreach (['NG' => 8, 'GH' => 1] as $country => $count) {
            for ($k = 0; $k < $count; $k++) {
                $nom = $this->nominee('Nominee ' . (++$i), $country);
                foreach (array_keys($this->criteria) as $criterion) {
                    foreach ($judges as $who => $jid) {
                        $this->score($jid, $nom, $criterion, $who === 'Bola' && $country === 'GH' ? 1 : 8);
                    }
                }
            }
        }

        $r = JudgeBiasService::forCycle($this->cycleId);

        // Bola is dramatically harsher on the single GH entry, and it is STILL not reported.
        foreach ($r['findings'] as $f) {
            $this->assertNotSame('GH', $f['group'], 'one nominee is not a pattern');
        }

        $thin = array_column($r['thin'], 'group');
        $this->assertContains('GH', $thin, 'the group must be named as untestable');
    }

    public function test_a_judge_alone_on_a_nominee_contributes_nothing(): void
    {
        // There is nobody to deviate from. Not an error — a fact about the panel, and the
        // note says so rather than the page looking broken.
        $jid = $this->judge('Solo');
        for ($i = 1; $i <= 8; $i++) {
            $nom = $this->nominee('Nominee ' . $i, 'NG');
            foreach (array_keys($this->criteria) as $criterion) {
                $this->score($jid, $nom, $criterion, 6);
            }
        }

        $r = JudgeBiasService::forCycle($this->cycleId);

        $this->assertSame([], $r['findings']);
        $this->assertStringContainsString('one judge', $r['note']);
    }

    public function test_a_cycle_with_no_scores_says_so_rather_than_looking_clean(): void
    {
        $r = JudgeBiasService::forCycle($this->cycleId);

        $this->assertSame([], $r['findings']);
        $this->assertStringContainsString('No scores', $r['note']);
    }

    public function test_a_judge_who_only_saw_one_group_is_not_compared_against_nothing(): void
    {
        // No contrast to show, so it is not a comparison — and counting it would inflate the
        // denominator the screen prints, making a real finding look rarer than it is.
        $judges = [];
        foreach (['Amina', 'Bola', 'Chidi'] as $who) $judges[$who] = $this->judge($who);

        for ($i = 1; $i <= 8; $i++) {
            $nom = $this->nominee('Nominee ' . $i, 'NG');
            foreach (array_keys($this->criteria) as $criterion) {
                foreach ($judges as $jid) $this->score($jid, $nom, $criterion, 6);
            }
        }

        $r = JudgeBiasService::forCycle($this->cycleId);

        // Country has one group, so it contributes nothing; criterion has two and does.
        $this->assertGreaterThan(0, $r['comparisons']);
    }

    // ══ the denominator always travels with the numerator ════════════════════

    public function test_the_comparison_count_is_always_returned(): void
    {
        // "Three judges show a lean" is alarming; "three out of two hundred and forty
        // comparisons" is normal, and they are the same fact. A screen that can only print
        // the first is the most misleading thing this feature could produce.
        $this->panel(6, 'Bola', -2, 'KE');

        $r = JudgeBiasService::forCycle($this->cycleId);

        $this->assertArrayHasKey('comparisons', $r);
        $this->assertGreaterThan(0, $r['comparisons']);
        $this->assertStringContainsString((string) $r['comparisons'], $r['note']);
    }

    public function test_the_note_warns_when_the_findings_are_within_what_chance_would_give(): void
    {
        // Not a real lean — just enough comparisons that something was bound to stand out.
        // The honest reading is "this is what a clean panel looks like at this many tests".
        $this->panel(6);
        $r = JudgeBiasService::forCycle($this->cycleId);

        $this->assertStringContainsString('comparison', $r['note']);
    }

    public function test_the_brief_input_carries_the_caveat_to_the_model(): void
    {
        // A model handed only findings writes the alarming version. It is handed the
        // denominator and the caveat sentence in the same payload.
        $this->panel(6, 'Bola', -2, 'KE');

        $b = JudgeBiasService::briefInput($this->cycleId);

        $this->assertArrayHasKey('comparisons', $b);
        $this->assertArrayHasKey('caveat', $b);
        $this->assertNotSame('', $b['caveat']);
        $this->assertNotSame([], $b['findings']);
        // Trimmed: a model handed forty findings summarises them into "there are several",
        // which is less useful than the table the reader already has.
        $this->assertLessThanOrEqual(6, count($b['findings']));
    }

    // ══ every finding is a sentence a person can read out ════════════════════

    public function test_a_finding_reads_as_a_question_and_never_as_a_verdict(): void
    {
        $this->panel(6, 'Bola', -2, 'KE');
        $f = JudgeBiasService::forCycle($this->cycleId)['findings'][0];

        $this->assertStringContainsString('Bola', $f['sentence']);
        $this->assertStringContainsString('across ' . $f['scores'] . ' scores', $f['sentence']);
        // It states the judge's own baseline in the same sentence, so "harsh generally" and
        // "harsh about this group" cannot be confused by somebody reading only the summary.
        $this->assertStringContainsString('panel overall', $f['sentence']);

        foreach (['biased', 'bias', 'unfair', 'prejudice'] as $accusation) {
            $this->assertStringNotContainsStringIgnoringCase($accusation, $f['sentence'],
                'a measurement must not editorialise about a named person');
        }
    }

    public function test_a_two_group_axis_is_counted_once_and_says_so(): void
    {
        // Relative deviations sum to zero across an axis by construction, so with exactly
        // two groups "harsher on Kenya" and "softer on Nigeria" are mirror images — one
        // fact stated from either end. Reporting both doubles the count on the screen and
        // doubles the apparent seriousness of something that happened once.
        $this->panel(6, 'Bola', -2, 'KE');

        $country = array_values(array_filter(
            JudgeBiasService::forCycle($this->cycleId)['findings'],
            fn (array $f): bool => $f['axis'] === 'country' && $f['judge'] === 'Bola'
        ));

        $this->assertCount(1, $country, 'a two-group axis is one finding');
        // Phrased from the side the lean goes AGAINST: that is the direction a person will
        // be asked about, and it is the sentence somebody can actually check.
        $this->assertSame('KE', $country[0]['group']);
        $this->assertSame('lower', $country[0]['direction']);
        // And it names the other side, because otherwise somebody asks why it is missing.
        $this->assertSame('NG', $country[0]['mirror']);
        $this->assertStringContainsString('counted once', $country[0]['sentence']);
    }

    public function test_nothing_here_changes_a_score(): void
    {
        // The service is read-only by construction. Asserted because "advisory" is a claim
        // that has to be checkable, and the cheapest check is that the table is untouched.
        $this->panel(6, 'Bola', -2, 'KE');
        $before = DB::table('gates_judge_criteria_scores')->orderBy('id')->get()->toJson();

        JudgeBiasService::forCycle($this->cycleId);

        $this->assertSame($before,
            DB::table('gates_judge_criteria_scores')->orderBy('id')->get()->toJson());
    }
}
