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
 * reproduce what it published cannot show its working for it either. Reproducing one
 * through the scorer now takes THREE settings rather than two: the denominator's scope
 * moved to the whole edition and `community_scope = category` is what puts it back.
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
     * but the old settings must go on producing them exactly, because the working behind an
     * announced result has to stay checkable after the rule changes. A platform whose whole
     * claim is that a ranking can be verified cannot lose the ability to verify the ones it
     * already published.
     *
     * ── AND IT IS THREE SETTINGS, NOT TWO ───────────────────────────────────
     *
     * `relative` + `curved` is the arithmetic, and this test drives it directly with each
     * nominee's own cohort maximum, so that is all it needs. Reproducing a cycle THROUGH
     * THE SCORER needs `community_scope = category` as well: the denominator is the whole
     * edition's maximum now, and every cohort figure in the table below is a per-CATEGORY
     * one. Two of the three would reproduce the shape of an announced cycle and not its
     * numbers, which is the worst of the three outcomes — it looks like a reproduction.
     * {@see \Tests\Unit\EditionScaleTest::test_the_category_scope_setting_reproduces_the_old_denominator}.
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

    // ══ ideal: both counts against one yardstick ═════════════════════════════

    /**
     * THE OPERATOR'S OWN WORKED EXAMPLES, TO THE DIGIT.
     *
     * `ideal` divides BOTH community terms by the largest tally in the edition, read as a
     * number of people: in the perfect case those votes were one each from that many
     * separate human beings. So the full 450 means exactly one thing — as many separate
     * supporters as the biggest total anybody managed.
     *
     *     315 × (unique voters ÷ ideal)  +  135 × (total votes ÷ ideal)
     *
     * If these drift, the rule that was agreed is not the rule being applied.
     */
    public function test_the_ideal_basis_pays_both_counts_from_one_yardstick(): void
    {
        $ideal = 2000;   // the biggest tally in the edition
        $anyone = 1000;  // somebody has countable rows, so reach is measurable

        // The perfect nominee: 2,000 supporters, one vote each. Nothing softer earns 450.
        $this->assertSame(450, (int) round(
            CpiService::idealPart(2000, 2000, $ideal, $anyone) * 450));

        // 1,000 supporters casting 2,000 votes — half the ideal in people, all of it in
        // votes. 0.7×0.5 + 0.3×1.0 = 0.65.
        $this->assertSame(292.5, round(
            CpiService::idealPart(1000, 2000, $ideal, $anyone) * 450, 1));

        // Two supporters, 2,000 votes. The volume term is full and the people term is
        // almost nothing, which is the whole point of weighting people at 70%.
        $this->assertSame(135.3, round(
            CpiService::idealPart(2, 2000, $ideal, $anyone) * 450, 1));
    }

    /**
     * AND THE YARDSTICK CANCELS OUT OF EVERY COMPARISON, WHICH IS THE REAL PROPERTY.
     *
     * Because both terms divide by the same figure, the community half is proportional to
     * `0.7 × people + 0.3 × votes` — so the ORDER of two nominees never depends on the
     * ideal at all, and one supporter is worth exactly 0.7/0.3 = 2.33 votes in every
     * edition whatever the figures.
     *
     * That fixed exchange rate is the substance of this basis and the whole of its cost.
     * On `reach` the same supporter is worth (maxVotes ÷ maxPeople) × 2.33, which grows
     * with every vote anybody buys — so buying past genuine support gets harder there and
     * does not here. Asserted rather than described, because it is the sentence the help
     * centre and the integrity page now publish.
     */
    public function test_a_supporter_is_worth_exactly_two_and_a_third_votes(): void
    {
        foreach ([[10, 102], [10, 1000], [10, 20000]] as [$maxPeople, $maxVotes]) {
            $perPerson = CpiService::idealPart(4, 10, $maxVotes, $maxPeople)
                       - CpiService::idealPart(3, 10, $maxVotes, $maxPeople);
            $perVote   = CpiService::idealPart(3, 11, $maxVotes, $maxPeople)
                       - CpiService::idealPart(3, 10, $maxVotes, $maxPeople);

            $this->assertSame(2.33, round($perPerson / $perVote, 2),
                'the exchange rate between a supporter and a vote moved with the '
                . 'denominators, so this is no longer the ideal basis');
        }

        // And on `reach` it is elastic — the property `ideal` gives up.
        $rP = CpiService::reachPart(4, 10, 10, 20000) - CpiService::reachPart(3, 10, 10, 20000);
        $rV = CpiService::reachPart(3, 10, 11, 20000) - CpiService::reachPart(3, 10, 10, 20000);
        $this->assertGreaterThan(1000.0, $rP / $rV,
            'reach has stopped making a supporter worth more as tallies grow, which is '
            . 'the one thing it does that the default does not');
    }

    /**
     * WHERE NOBODY IN THE EDITION HAS A COUNTABLE ROW, ONLY THE TALLY TERM IS PAID.
     *
     * ── THIS USED TO PAY THE WHOLE HALF, AND THAT WAS THE BUG ───────────────
     *
     * A zero `cohortMaxUnique` means vote ROWS are missing while tallies are not — an
     * import from before this platform held rows, a fixture, a purged cycle. The code used
     * to answer that by handing the tally the WHOLE community half, so the leading tally
     * collected 450 with no counted supporters at all.
     *
     * The argument was that a field capped at 135 "still reads like one scored out of
     * 450". That is an argument for saying so on the screen, which is now done on both
     * surfaces — not for paying out 315 points against a measurement nobody made. It also
     * contradicts the rule as specified, which has no fallback clause: `315 × (unique ÷
     * highest total votes)` is zero when the unique voters are unknown.
     */
    public function test_an_edition_with_no_countable_rows_pays_the_tally_term_only(): void
    {
        // Leader of the edition on tally, no rows anywhere: 30% of the half, not all of it.
        $this->assertSame(135.0, round(CpiService::idealPart(0, 2000, 2000, 0) * 450, 1));
        // And half the tally is half of that.
        $this->assertSame(67.5, round(CpiService::idealPart(0, 1000, 2000, 0) * 450, 1));
    }

    /**
     * AND RECOVERING A ROW NOW HELPS THE NOMINEE WHO GAINED IT.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE MEASUREMENT CLIFF IS GONE, AND IT WENT WITH THE FALLBACK
     * ══════════════════════════════════════════════════════════════════════════
     *
     * While the all-or-nothing fallback existed, an edition with imported tallies and no
     * rows paid the tally the whole half — so a nominee on 80 of a 100-vote maximum had
     * 0.8 × 450 = 360. The FIRST countable row anywhere in the cycle switched the people
     * term on for every nominee at once, against a tally denominator, and that same
     * nominee dropped from 360 to 122 by gaining three counted supporters.
     *
     * `VoteRecoveryTest` found it by failing: a test asserting that a recovered vote helps
     * its nominee, failing because it did the opposite. It was documented as unsmoothable,
     * and it was — as long as the fallback was there to fall off.
     *
     * With the people term simply unpaid when nothing measured it, the same three rows are
     * a RISE (108 → 121.5) rather than a fall, because there is no longer a cliff edge to
     * be standing on. Nothing was smoothed; the discontinuity was an artefact of paying a
     * term that had not been measured.
     *
     * The partially-measured case is unchanged and is still the one to know: a nominee
     * with three real rows behind an imported eighty-vote tally is not flagged — they have
     * rows — and is scored as though the eighty had been measured and found to be three
     * people.
     */
    public function test_recovering_the_first_row_raises_the_nominee_who_gained_it(): void
    {
        $ideal = 100;   // the edition's biggest tally

        // Nothing counted anywhere: the tally term alone.
        $this->assertSame(108.0, round(CpiService::idealPart(0, 80, $ideal, 0) * 450, 1));

        // Three rows recovered for that nominee. Their score RISES — under the old
        // fallback this same step was 360 → 121.5.
        $this->assertSame(121.5, round(CpiService::idealPart(3, 83, $ideal, 3) * 450, 1),
            'the people term is being scaled to whoever holds the most rows, which is '
            . '`reach` and not this basis');
        $this->assertGreaterThan(
            CpiService::idealPart(0, 80, $ideal, 0),
            CpiService::idealPart(3, 83, $ideal, 3),
            'recovering a vote must never cost its nominee points');

        // The nominee with a tally and no rows at all keeps their tally share of the
        // thirty per cent, and is the one the platform flags.
        $this->assertSame(135.0, round(CpiService::idealPart(0, 100, $ideal, 3) * 450, 1));
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
        foreach (['', 'IDEAL', 'relatve', 'abolute', 'reech', 'true', '1', 'Absolute '] as $raw) {
            $this->assertSame(CpiService::BASIS_IDEAL, CpiService::basis($raw),
                '"' . $raw . '" was accepted as a scoring basis');
        }
        $this->assertSame(CpiService::BASIS_ABSOLUTE, CpiService::basis('absolute'));
        $this->assertSame(CpiService::BASIS_RELATIVE, CpiService::basis('relative'));
        // `reach` is matched EXPLICITLY now that it is no longer the default. Without its
        // own arm it would fall through to `ideal`, and every cycle announced under it
        // would stop being reproducible from the settings that produced it — which is the
        // one job these settings have.
        $this->assertSame(CpiService::BASIS_REACH,    CpiService::basis('reach'));
        $this->assertSame(CpiService::BASIS_IDEAL,    CpiService::basis(null));

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
        // ── MERGED, NEVER REPLACED ──────────────────────────────────────────
        //
        // The global rule set also carries the weights, the fraud bands, the quorum and the
        // community-return accrual, and `set()` replaces the whole document — so a writer
        // that passes five keys erases the rest, silently, and the first symptom is every
        // cycle scored by defaults nobody chose.
        //
        // Asserted on WHICH METHOD the writer calls, not on the spelling of the merge. This
        // used to look for the literal `array_merge($current, [` — seven lines of
        // read-and-merge that this file required to be duplicated in both writers, and
        // which broke the moment that discipline was given one implementation. A test that
        // pins an implementation fails on the fix rather than on the fault.
        //
        // The behaviour itself is held by RuleEngineTest::test_merge_keeps_the_keys_it_was_not_given.
        $this->assertStringContainsString("\$engine->merge('global', null, [", $ctrl,
            'the scoring settings are written with set(), which replaces the whole rule '
            . 'document and erases the weights, the quorum and the fraud bands');
        $this->assertStringNotContainsString("\$engine->set('global'", $ctrl,
            'set() at the global scope replaces every rule it does not carry');
    }
}
