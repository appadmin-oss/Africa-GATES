<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CpiService, NomineeScoringService, RuleEngine};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * WHAT THE COMMUNITY HALF IS A SHARE OF.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT EVERY TALLY-ONLY BASIS SHARES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `relative` measures a nominee against the leader of their own category; `absolute`
 * measures them against a fixed full-credit mark. Both measure ONE thing — the size of a
 * tally — and a tally is the number money moves most easily:
 *
 *     A   1,000 people, 2,000 votes
 *     B       2 people, 2,000 votes
 *
 * On either basis those are the same nominee. Nothing in the arithmetic was looking at
 * how many human beings were behind either number, so nothing could tell them apart.
 *
 * `reach` splits the half: 70% (315 of 450) is how many verified PEOPLE backed a
 * nominee, 30% (135) is the total tally. B collects 135.63 of 450 and A collects 450.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE DEFAULT MOVED, HAVING BEEN PINNED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This file used to open by saying results are printed onto physical awards, so the
 * default must reproduce the announced standings to the digit. The operator has since
 * decided the other way, in as many words: *do not mind the physical award, be fair.*
 * That is their call to make and it is recorded here because the reasoning below only
 * makes sense against it.
 *
 * So `reach` is the default. The older bases remain settings, and
 * {@see test_the_old_settings_still_reproduce_the_published_index_exactly} still pins the
 * announced figures — not because they may not move, but because a platform that cannot
 * reproduce what it published cannot show its working for it either.
 *
 * ── WHAT SWITCHING COSTS, STATED RATHER THAN DISCOVERED ─────────────────────
 *
 * Reach needs a number no release screen ever recorded: unique voters per nominee. It is
 * recoverable — `gates_votes` still holds one row per voter per category — but it comes
 * from a recompute over those rows, not from `gates_vote_snapshots`. A cycle whose vote
 * rows have been purged can be scored on `relative` and on nothing else.
 */
final class CommunityBasisTest extends TestCase
{
    /** The two figures that make the incomparability concrete, off the released cycle. */
    private const LEADER_OF_A_TINY_FIELD = [19, 19];      // votes, cohort max
    private const THIRD_OF_A_BIG_FIELD   = [691, 1955];

    // ══ the default cannot move ══════════════════════════════════════════════

    /**
     * THE PUBLISHED NUMBERS, STILL REPRODUCIBLE FROM THE SETTINGS THAT MADE THEM.
     *
     * These are the community halves and indexes of real nominees in two released cycles.
     * They are no longer what the DEFAULT produces — the operator moved it deliberately —
     * but `relative` + `curved` must go on producing them exactly, because the working
     * behind an announced result has to stay checkable after the rule changes. A platform
     * whose whole claim is that a ranking can be verified cannot lose the ability to
     * verify the ones it already published.
     */
    public function test_the_old_settings_still_reproduce_the_published_index_exactly(): void
    {
        $s   = new CpiService();
        $old = ['communityBasis' => CpiService::BASIS_RELATIVE,
                'judgeScale'     => CpiService::SCALE_CURVED];

        // [votes, cohortMax, judgeAvg, expected CPI, expected community]
        foreach ([
            [620,  620, 8.0, 610, 354],   // Afolabi Habeebah — VOLUNTEER SERVICE
            [500,  500, 7.1, 468, 318],   // Awe-Olola Champion Victoria — TEACHERS' CHOICE
            [113,  113, 7.0, 290, 151],   // Demilade Idogun — YOUNG PEACEMAKER
            [1955,1955, 7.9, 693, 450],   // Ajayi Temitope Oluwarotimi — ACADEMIC EXCELLENCE
            [89,    89, 8.1, 403, 134],   // Idowu Olayemi Olubukunola — SOCIAL DEVELOPMENT
            [19,    19, 6.1, 119,  62],   // Mr Aoyera Kayode John — LEADERSHIP
        ] as [$v, $m, $j, $cpi, $comm]) {
            $this->assertSame($cpi, $s->nomineeScore($v, $m, $j,
                    communityBasis: $old['communityBasis'], judgeScale: $old['judgeScale']),
                "a published index can no longer be reproduced: {$v} votes, cohort {$m}, panel {$j}");
            $this->assertSame($comm, CpiService::split(
                CpiService::communityPart($v, $m, null, null, $old['communityBasis']),
                CpiService::judgePart($j, null, null, $old['judgeScale']), .45, .55)['community']);
        }
    }

    /**
     * An unrecognised value is the DEFAULT, never a guess and never the old behaviour.
     *
     * The direction matters. If a typo fell back to a tally-only basis, then a deployment
     * that fat-fingered its settings would silently go back to a rule money can move —
     * and every screen would agree with itself while it happened.
     */
    public function test_an_unknown_basis_falls_back_to_the_default(): void
    {
        foreach (['', 'REACH', 'relatve', 'abolute', 'true', '1', 'Absolute '] as $raw) {
            $this->assertSame(CpiService::BASIS_REACH, CpiService::basis($raw),
                '"' . $raw . '" was accepted as a scoring basis');
        }
        $this->assertSame(CpiService::BASIS_ABSOLUTE, CpiService::basis('absolute'));
        $this->assertSame(CpiService::BASIS_RELATIVE, CpiService::basis('relative'));
        $this->assertSame(CpiService::BASIS_REACH,    CpiService::basis(null));

        // And the same for the judge scale, which decides the other 550.
        foreach (['', 'CURVED', 'curvd', 'nonsense', null] as $raw) {
            $this->assertSame(CpiService::SCALE_LINEAR, CpiService::judgeScale($raw),
                'a stray value quietly restored the exponent on every panel mark');
        }
        $this->assertSame(CpiService::SCALE_CURVED, CpiService::judgeScale('curved'));

        [$v, $m] = self::LEADER_OF_A_TINY_FIELD;
        $this->assertSame(
            CpiService::communityPart($v, $m, null, null, 'nonsense', 3, 9),
            CpiService::communityPart($v, $m, null, null, null, 3, 9),
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
    public function test_a_category_leader_scores_the_same_under_both_tally_bases(): void
    {
        foreach ([19, 89, 500, 620, 1955] as $v) {
            $this->assertSame(
                CpiService::communityPart($v, $v, null, null, CpiService::BASIS_RELATIVE),
                CpiService::communityPart($v, $v, null, null, CpiService::BASIS_ABSOLUTE),
                'the bases disagree for the leader of a ' . $v . '-vote category, so '
                . 'switching one on could crown somebody else');
        }
    }

    /**
     * REACH HAS THE SAME PROPERTY, AND IT IS NOT OBVIOUS.
     *
     * A nominee who leads a field on BOTH terms — most people and most votes — collects
     * the whole community half, exactly as they do under either tally basis. It has to be
     * checked rather than assumed, because reach is the first basis with two numerators:
     * a weighted sum of two shares reaches 1.0 only if both shares do, and getting the
     * weights wrong (0.7 + 0.7, say) would quietly pay a leader more than the half.
     */
    public function test_a_leader_on_both_terms_collects_the_whole_community_half(): void
    {
        foreach ([[19, 40], [89, 200], [1955, 4000]] as [$people, $votes]) {
            $this->assertSame(1.0, CpiService::communityPart(
                $votes, $votes, null, null, CpiService::BASIS_REACH, $people, $people),
                'the field leader does not collect the whole community half');
        }

        // And the two terms are exactly 70/30 of it — the figures the rule is stated in.
        $this->assertSame(0.70, round(CpiService::communityPart(
            0, 100, null, null, CpiService::BASIS_REACH, 50, 50), 10),
            'all the people and none of the votes is not worth 315 of 450');
        $this->assertSame(0.30, round(CpiService::communityPart(
            100, 100, null, null, CpiService::BASIS_REACH, 0, 50), 10),
            'all the votes and none of the people is not worth 135 of 450');
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
