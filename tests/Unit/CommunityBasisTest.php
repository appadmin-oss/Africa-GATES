<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CpiService, NomineeScoringService, RuleEngine};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * WHAT THE COMMUNITY HALF IS A SHARE OF — AND WHY IT IS A SETTING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The community half measures a nominee against the leader of their OWN category. That
 * is the right question for deciding a category and it is meaningless outside one. Three
 * real rows off one released cycle, all on the same 0–1000 index:
 *
 *     Mr Aoyera Kayode John        19 votes · LEADER of a 19-vote field  → community 62
 *     Ogunyemi Olusola Titilope   691 votes · 35% of a 1,955-vote field  → community 56
 *     Amb. Ojewola Olawale        161 votes ·  8% of the same field      → community  3
 *
 * Nineteen votes out-scoring six hundred and ninety-one. Both figures are correct and
 * neither is comparable to the other. The depth discount cut the small category's credit
 * to 0.138 of full — and its leader still collects all of that, while 8% squared is
 * 0.68% of a category whose discount is waived entirely.
 *
 * It matters because this platform crowns an OVERALL winner across categories, which is
 * that incomparable comparison made into an award.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SETTING RATHER THAN A CORRECTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Results here are published and printed onto physical awards. A cycle that has announced
 * its standings must keep them to the digit, and switching basis moves them: on the two
 * released cycles no winner and no overall position changed, but a 3rd/4th place did —
 * 918 votes at 6.8 overtaking 398 votes at 7.6.
 *
 * So the default is today's behaviour exactly, and a later cycle opts in. RuleEngine
 * resolves it per programme and per cycle, which is what makes that possible.
 *
 * `test_the_default_reproduces_the_published_index_exactly` is the load-bearing one: it
 * pins the figures that are on an award right now.
 */
final class CommunityBasisTest extends TestCase
{
    /** The two figures that make the incomparability concrete, off the released cycle. */
    private const LEADER_OF_A_TINY_FIELD = [19, 19];      // votes, cohort max
    private const THIRD_OF_A_BIG_FIELD   = [691, 1955];

    // ══ the default cannot move ══════════════════════════════════════════════

    /**
     * THE PUBLISHED NUMBERS, PINNED.
     *
     * These are the community halves and indexes of real nominees in two released cycles.
     * They are printed. If this test fails, something has changed a result that has
     * already been announced — which is not a regression to be triaged, it is a thing
     * that must not ship.
     */
    public function test_the_default_reproduces_the_published_index_exactly(): void
    {
        $s = new CpiService();

        // [votes, cohortMax, judgeAvg, expected CPI, expected community]
        foreach ([
            [620,  620, 8.0, 610, 354],   // Afolabi Habeebah — VOLUNTEER SERVICE
            [500,  500, 7.1, 468, 318],   // Awe-Olola Champion Victoria — TEACHERS' CHOICE
            [113,  113, 7.0, 290, 151],   // Demilade Idogun — YOUNG PEACEMAKER
            [1955,1955, 7.9, 693, 450],   // Ajayi Temitope Oluwarotimi — ACADEMIC EXCELLENCE
            [89,    89, 8.1, 403, 134],   // Idowu Olayemi Olubukunola — SOCIAL DEVELOPMENT
            [19,    19, 6.1, 119,  62],   // Mr Aoyera Kayode John — LEADERSHIP
        ] as [$v, $m, $j, $cpi, $comm]) {
            $this->assertSame($cpi, $s->nomineeScore($v, $m, $j),
                "a published index moved: {$v} votes, cohort {$m}, panel {$j}");
            $this->assertSame($comm, CpiService::split(
                CpiService::communityPart($v, $m), CpiService::judgePart($j), .45, .55)['community']);
        }
    }

    /** And an unrecognised value is today's behaviour, never a guess. */
    public function test_an_unknown_basis_falls_back_to_the_published_one(): void
    {
        foreach (['', 'RELATIVE', 'abolute', 'true', '1', 'Absolute '] as $raw) {
            $this->assertSame(CpiService::BASIS_RELATIVE, CpiService::basis($raw),
                '"' . $raw . '" was accepted as a scoring basis');
        }
        $this->assertSame(CpiService::BASIS_ABSOLUTE, CpiService::basis('absolute'));
        $this->assertSame(CpiService::BASIS_RELATIVE, CpiService::basis(null));

        [$v, $m] = self::LEADER_OF_A_TINY_FIELD;
        $this->assertSame(
            CpiService::communityPart($v, $m, null, null, 'nonsense'),
            CpiService::communityPart($v, $m),
            'a stray string quietly switched how every award is decided');
    }

    // ══ what the switch actually does ════════════════════════════════════════

    public function test_relative_lets_a_tiny_field_leader_outscore_far_more_support(): void
    {
        [$tv, $tm] = self::LEADER_OF_A_TINY_FIELD;
        [$bv, $bm] = self::THIRD_OF_A_BIG_FIELD;

        $this->assertGreaterThan(
            CpiService::communityPart($bv, $bm),
            CpiService::communityPart($tv, $tm),
            'the fault this setting exists for has gone away on its own, which means the '
            . 'relative basis has been changed rather than left as the published default');
    }

    public function test_turnout_puts_them_the_right_way_round(): void
    {
        [$tv, $tm] = self::LEADER_OF_A_TINY_FIELD;
        [$bv, $bm] = self::THIRD_OF_A_BIG_FIELD;
        $abs = CpiService::BASIS_ABSOLUTE;

        $this->assertGreaterThan(
            CpiService::communityPart($tv, $tm, null, null, $abs),
            CpiService::communityPart($bv, $bm, null, null, $abs),
            '691 votes still scores below 19 under the turnout basis');
    }

    /**
     * AND IT CANNOT MOVE A CATEGORY WINNER BY ITSELF.
     *
     * The two bases agree exactly for a category leader — at v = cohortMax the relative
     * share is 1 and the formula reduces to depth(v) — and within a category both are
     * monotonic in votes, so the ORDER never changes. What a switch changes is the
     * balance between the halves, which is why the released cycles were checked
     * individually rather than trusted to this property.
     */
    public function test_a_category_leader_scores_the_same_under_both(): void
    {
        foreach ([19, 89, 500, 620, 1955] as $v) {
            $this->assertSame(
                CpiService::communityPart($v, $v),
                CpiService::communityPart($v, $v, null, null, CpiService::BASIS_ABSOLUTE),
                'the bases disagree for the leader of a ' . $v . '-vote category, so '
                . 'switching one on could crown somebody else');
        }
    }

    // ══ and it is reachable ══════════════════════════════════════════════════

    /**
     * THE SCORER READS THE RULE.
     *
     * A basis nothing consults is a declared setting with no reader — six of those have
     * shipped here. Driven through scoreCategory() rather than asserted from source.
     */
    public function test_the_scorer_honours_the_rule_it_is_configured_with(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'judged',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'cat-10', 'title' => 'Category',
        ]);
        // The leader on 1,955 and a nominee on 161 — 8% of them.
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 10, 'name' => 'Leader', 'country_code' => 'NG',
             'status' => 'approved', 'vote_count' => 1955, 'organic_vote_count' => 0],
            ['id' => 2, 'category_id' => 10, 'name' => 'Eight per cent', 'country_code' => 'NG',
             'status' => 'approved', 'vote_count' => 161, 'organic_vote_count' => 0],
        ]);

        $rules = new RuleEngine();

        $rules->set('global', null, ['community_basis' => CpiService::BASIS_RELATIVE]);
        $rel = (new NomineeScoringService())->scoreCategory(10);

        $rules->set('global', null, ['community_basis' => CpiService::BASIS_ABSOLUTE]);
        $abs = (new NomineeScoringService())->scoreCategory(10);

        $this->assertGreaterThan($rel[2]['cpi_score'], $abs[2]['cpi_score'],
            'the scorer ignores community_basis, so the setting is unreachable from the '
            . 'only place that scores an award');

        // The leader is untouched by the switch, which is the property that makes it safe
        // to offer at all.
        $this->assertSame($rel[1]['cpi_score'], $abs[1]['cpi_score']);
    }

    /** And an operator can actually set it — no shell on production. */
    public function test_the_rule_has_a_form_behind_it(): void
    {
        $twig = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $ctrl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');

        $this->assertStringContainsString('name="community_basis"', $twig,
            'the basis has no input, so it can only be changed by hand-editing JSON on a '
            . 'host with no shell');
        $this->assertStringContainsString("'community_basis' => \\AfricaGates\\Services\\CpiService::basis(", $ctrl,
            'the form posts a basis that nothing saves');
        // Merged, never replaced — the global rule set also carries the weights, the
        // fraud bands and the quorum, and writing five keys would erase them.
        $this->assertStringContainsString('array_merge($current, [', $ctrl);
    }
}
