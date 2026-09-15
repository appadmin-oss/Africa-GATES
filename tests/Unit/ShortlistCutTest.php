<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShortlistRule;
use AfricaGates\Services\ShortlistService;
use Tests\TestCase;

/**
 * Where the line falls. No database — {@see ShortlistService::apply()} is pure on purpose.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS GUARDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The naive implementation of every rule in here is one SQL clause, and every one of them
 * is wrong in a way nothing would report:
 *
 *   "top 10"          → `ORDER BY vote_count DESC LIMIT 10`, which resolves a three-way
 *                       tie for tenth place by picking whichever row the storage engine
 *                       returns first. That answer can differ between two runs and does
 *                       differ between MySQL and SQLite, and nothing records that a choice
 *                       was made at all.
 *   "top 20%"         → `LIMIT total*0.2`, which truncates: 20% of 11 becomes 2, and the
 *                       rule silently became stricter than the words it is written in.
 *   "organic only"    → changing which column is SUMmed without changing the ORDER BY, so
 *                       the cut is taken from a list sorted by a different number than the
 *                       one being compared against it.
 *
 * Each test below is one of those.
 */
final class ShortlistCutTest extends TestCase
{
    /** @return list<array<string,mixed>> nominees named A, B, C… with the given vote counts */
    private function field(array $counts, ?array $organic = null): array
    {
        $rows = [];
        foreach (array_values($counts) as $i => $n) {
            $rows[] = [
                'id'                 => $i + 1,
                'name'               => chr(65 + $i),
                'vote_count'         => $n,
                'organic_vote_count' => $organic !== null ? array_values($organic)[$i] : $n,
                'country_code'       => 'NG',
                'organisation'       => '',
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $r */
    private function names(array $r): array
    {
        return array_values(array_map(
            fn ($x) => $x['name'],
            array_filter($r['rows'], fn ($x) => $x['in'])
        ));
    }

    // ══ the boundary ═════════════════════════════════════════════════════════

    public function test_top_n_with_no_tie_takes_exactly_n(): void
    {
        $r = ShortlistService::apply(
            new ShortlistRule('top_n', 3, 1),
            $this->field([90, 80, 70, 60, 50])
        );

        $this->assertSame(['A', 'B', 'C'], $this->names($r));
        $this->assertSame(70, $r['cut'], 'the cut is the count sitting on the line');
        $this->assertSame(5, $r['considered']);
    }

    /**
     * THE ONE THAT MATTERS. Three nominees level on 70 in third place: a shortlist of
     * "top 3" contains five names, because the alternative is dropping two of three equal
     * people by coin flip.
     */
    public function test_ties_at_the_cut_are_included_and_the_list_runs_long(): void
    {
        $r = ShortlistService::apply(
            new ShortlistRule('top_n', 3, 1, 'include'),
            $this->field([90, 80, 70, 70, 70, 60])
        );

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->names($r));
        $this->assertSame(5, $r['in'], 'a rule of 3 must be allowed to produce 5 rather than pick arbitrarily');
        $this->assertSame(3, $r['ties'], 'the size of the tie group is reported');
    }

    public function test_ties_can_be_excluded_and_then_the_list_falls_short(): void
    {
        $r = ShortlistService::apply(
            new ShortlistRule('top_n', 3, 1, 'exclude'),
            $this->field([90, 80, 70, 70, 70, 60])
        );

        $this->assertSame(['A', 'B'], $this->names($r), 'exclude drops the whole tie group');
        $this->assertNotSame([], $r['warnings'], 'dropping a tie group must not happen quietly');
    }

    /** Excluding ties where the entire field is level shortlists nobody, and says so. */
    public function test_an_all_level_field_with_ties_excluded_is_reported_not_silently_empty(): void
    {
        $r = ShortlistService::apply(
            new ShortlistRule('top_n', 3, 1, 'exclude'),
            $this->field([50, 50, 50, 50])
        );

        $this->assertSame(0, $r['in']);
        $this->assertContains('This rule shortlists nobody in this category.', $r['warnings']);
    }

    // ══ the floor ════════════════════════════════════════════════════════════

    /**
     * "Top 10" in a category of six where three have no votes must not publish a document
     * announcing three people nobody chose.
     */
    public function test_the_floor_catches_a_thin_category(): void
    {
        $r = ShortlistService::apply(
            new ShortlistRule('top_n', 10, 1),
            $this->field([12, 4, 1, 0, 0, 0])
        );

        $this->assertSame(['A', 'B', 'C'], $this->names($r));
        foreach ($r['rows'] as $row) {
            if (!$row['in']) {
                $this->assertSame('under the minimum', $row['reason'],
                    'the reason must distinguish the floor from the cut');
            }
        }
    }

    /**
     * The warning fires on the PREVIEW, not on the rule.
     *
     * It used to live in `ShortlistRule::warnings()` and fired whenever the floor was 0 —
     * which is now the default, so it appeared on every screen before anybody had done
     * anything. A warning that is always on is one people learn to scroll past. It now
     * describes what is actually about to happen.
     */
    public function test_a_zero_vote_nominee_being_shortlisted_is_warned_about_when_it_happens(): void
    {
        $rule = new ShortlistRule('top_n', 10, 0);
        $r    = ShortlistService::apply($rule, $this->field([12, 0, 0]));

        $this->assertSame(3, $r['in'], 'the rule as written does admit them');
        $this->assertNotSame([], array_filter($r['warnings'],
            fn ($w) => str_contains($w, 'no votes at all')),
            'an organiser about to publish two people nobody voted for should be told');

        // And it stays quiet when it is not happening.
        $quiet = ShortlistService::apply($rule, $this->field([12, 9, 4]));
        $this->assertSame([], array_filter($quiet['warnings'],
            fn ($w) => str_contains($w, 'no votes at all')));
    }

    /**
     * The default floor is ZERO, and it has to be.
     *
     * With a floor of 1, a category whose voting has not opened has every nominee on 0 —
     * so nobody qualified, the preview said nought, and the publish button never rendered.
     * The feature was unusable in the state an organiser first meets it in.
     */
    public function test_the_default_rule_can_shortlist_before_any_votes_exist(): void
    {
        $r = ShortlistService::apply(new ShortlistRule(), $this->field([0, 0, 0]));

        $this->assertSame(3, $r['in'],
            'a floor of 1 on a pre-voting category excludes everybody and hides the button');
    }

    /** When nothing qualifies, the reason names the term that excluded them. */
    public function test_an_empty_selection_says_which_rule_term_emptied_it(): void
    {
        $r = ShortlistService::apply(new ShortlistRule('top_n', 10, 5), $this->field([1, 0, 0]));

        $this->assertSame(0, $r['in']);
        $this->assertNotSame([], array_filter($r['warnings'],
            fn ($w) => str_contains($w, 'under the minimum')),
            '"nobody matches" is not actionable; "everybody is under the minimum of 5" is');
    }

    // ══ percentages ══════════════════════════════════════════════════════════

    /** 20% of 11 is 2.2. Truncating makes the rule stricter than its own wording. */
    public function test_a_percentage_rounds_up_rather_than_silently_tightening(): void
    {
        $rule = new ShortlistRule('top_pct', 20, 1);

        $this->assertSame(3, $rule->take(11), 'ceil, not floor: 2.2 of 11 is three nominees');
        $this->assertSame(1, $rule->take(1),  'a category of one still shortlists one');

        $r = ShortlistService::apply($rule, $this->field([100, 90, 80, 70, 60, 50, 40, 30, 20, 10, 5]));
        $this->assertSame(['A', 'B', 'C'], $this->names($r));
    }

    public function test_a_percentage_above_a_hundred_is_clamped_not_obeyed(): void
    {
        $this->assertSame(100, ShortlistRule::from(['mode' => 'top_pct', 'threshold' => 250])->threshold);
    }

    // ══ min_votes mode ═══════════════════════════════════════════════════════

    public function test_min_votes_mode_draws_no_positional_line_at_all(): void
    {
        $rule = new ShortlistRule('min_votes', 50);

        $this->assertNull($rule->take(999), 'there is no Nth place in this mode');

        $r = ShortlistService::apply($rule, $this->field([90, 60, 50, 49, 10]));

        $this->assertSame(['A', 'B', 'C'], $this->names($r), 'the bar is inclusive');
        $this->assertNull($r['cut'], 'no cut count, because nothing was cut by position');
        $this->assertSame(0, $r['ties']);
    }

    // ══ organic-only ═════════════════════════════════════════════════════════

    /**
     * A nominee with 400 purchased votes and 3 organic ones must not arrive at the top of
     * an organic-only cut. This is the failure mode of changing the compared column without
     * changing the sort.
     */
    public function test_organic_only_re_sorts_and_does_not_merely_compare_a_different_column(): void
    {
        $rows = $this->field(
            [400, 120, 110, 100],       // total votes — the order candidates() returns
            [3, 118, 109,  99]          // organic only — a completely different order
        );

        $r = ShortlistService::apply(new ShortlistRule('top_n', 2, 1, 'include', true), $rows);

        $this->assertSame(['B', 'C'], $this->names($r),
            'the paid-vote leader must not survive an organic-only cut');
        $this->assertSame('organic_vote_count', (new ShortlistRule('top_n', 2, 1, 'include', true))->column());
    }

    // ══ the property the template depends on ═════════════════════════════════

    /**
     * Every included nominee sorts above every excluded one.
     *
     * The admin preview draws its cut line by INDEX — `loop.index0 == preview.in` — because
     * a `{% set %}` flag inside a Twig loop does not survive the next iteration. That is
     * only correct while the selection is monotone in the counted column, which it is: both
     * the positional test and the floor are thresholds on the same number. If a rule ever
     * gained a non-monotone term, the line would be drawn in the wrong place and this fails.
     */
    public function test_the_selection_is_monotone_so_the_cut_line_can_be_drawn_by_index(): void
    {
        $rules = [
            new ShortlistRule('top_n', 3, 1),
            new ShortlistRule('top_n', 3, 60, 'exclude'),
            new ShortlistRule('top_pct', 40, 20),
            new ShortlistRule('min_votes', 55),
            new ShortlistRule('top_n', 4, 1, 'include', true),
        ];

        foreach ($rules as $rule) {
            $r    = ShortlistService::apply($rule, $this->field([90, 80, 70, 70, 55, 20, 0], [88, 10, 70, 69, 55, 19, 0]));
            $seen = false;

            foreach ($r['rows'] as $i => $row) {
                if (!$row['in']) $seen = true;
                if ($seen) {
                    $this->assertFalse($row['in'],
                        "row {$i} is included after an excluded one under '{$rule->mode}' — "
                        . 'the preview would draw its cut line in the wrong place');
                }
            }

            $this->assertSame($r['in'], count(array_filter($r['rows'], fn ($x) => $x['in'])),
                'the reported count and the flagged rows must agree');
        }
    }

    public function test_an_empty_field_is_not_an_error(): void
    {
        $r = ShortlistService::apply(new ShortlistRule(), []);

        $this->assertSame(0, $r['considered']);
        $this->assertSame(0, $r['in']);
        $this->assertNull($r['cut']);
        $this->assertSame([], $r['warnings'], 'an empty category is not a misconfigured rule');
    }

    // ══ the sentence on the document ═════════════════════════════════════════

    public function test_the_rule_describes_itself_including_the_parts_that_change_the_answer(): void
    {
        $s = (new ShortlistRule('top_n', 10, 5, 'include', true))->describe();

        $this->assertStringContainsString('Top 10', $s);
        $this->assertStringContainsString('minimum 5 vote', $s, 'the floor changes who is on the list');
        $this->assertStringContainsString('ties included', $s, 'so does the tie policy');
        $this->assertStringContainsString('Organic votes only', $s);
    }

    /** The tie policy is meaningless in min_votes mode and must not be claimed. */
    public function test_the_description_omits_a_tie_policy_that_does_not_apply(): void
    {
        $s = (new ShortlistRule('min_votes', 25, 1, 'exclude'))->describe();

        $this->assertStringNotContainsString('ties', $s);
        $this->assertStringContainsString('at least 25 votes', $s);
    }

    public function test_a_rule_row_written_by_an_older_version_is_coerced_not_fatal(): void
    {
        $r = ShortlistRule::from(['mode' => 'nonsense', 'threshold' => -4, 'tie_mode' => 'maybe']);

        $this->assertSame('top_n', $r->mode);
        $this->assertSame(1, $r->threshold, 'a top_n of 0 would shortlist nobody while looking configured');
        $this->assertSame('include', $r->tieMode);
    }
}
