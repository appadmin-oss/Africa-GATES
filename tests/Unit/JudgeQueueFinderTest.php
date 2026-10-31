<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Finding one nominee among forty, on the screen a judge actually works in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The queue was a flat scroll: no search, no filter, no keyboard path. A panel of forty is
 * a judge scrolling to find the person whose evidence they were reading yesterday — and a
 * judge coming back to a panel has exactly one question, "what have I not done?", that the
 * list could not answer at all.
 *
 * This is a DAILY-USE EXPERT TOOL, not a consumer flow. Density, keyboard control and a
 * default view that answers the resuming question are the right calls here; progressive
 * disclosure and hand-holding are not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THAT WOULD BE A DISASTER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `active` is an index into `queue`, and filtering produces a VIEW of indices rather than a
 * new array. Filtering the array itself would renumber every index under a judge mid-score
 * and save a note against the wrong person — which is the worst bug this screen could have,
 * would be silent, and would look like a judge's own mistake.
 */
final class JudgeQueueFinderTest extends TestCase
{
    private function ballot(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/judge/ballot.twig');
    }

    // ══ the disaster case ════════════════════════════════════════════════════

    /** The filter never rewrites the array the scores are indexed against. */
    public function test_filtering_produces_a_view_and_never_renumbers_the_queue(): void
    {
        $s = $this->ballot();
        $from = (int) strpos($s, 'get shown()');
        $body = substr($s, $from, (int) strpos($s, 'countOf(k)') - $from);

        $this->assertStringContainsString('out.push(i)', $body,
            'shown must yield INDICES into queue, not nominee objects');
        foreach (['this.queue =', 'queue.splice', 'queue.sort', 'queue.filter('] as $mutation) {
            $this->assertStringNotContainsString($mutation, $body,
                'shown() mutates the queue — every index under the judge moves, and a note '
                . 'saves against the wrong nominee');
        }
    }

    /** And the row still picks by the real index. */
    public function test_a_row_picks_its_real_position(): void
    {
        $this->assertStringContainsString('x-for="i in shown"', $this->ballot());
        $this->assertStringContainsString('@click="pick(i)"', $this->ballot());
    }

    // ══ the keyboard ═════════════════════════════════════════════════════════

    /**
     * A judge on this screen types notes and conflict declarations. A bare `j` that fired
     * inside a textarea would move the panel mid-sentence and take the sentence with it.
     */
    public function test_shortcuts_never_fire_while_somebody_is_typing(): void
    {
        $s = $this->ballot();
        $from = (int) strpos($s, 'key(e){');
        $body = substr($s, $from, 900);

        $this->assertStringContainsString("tag === 'textarea'", $body);
        $this->assertStringContainsString("tag === 'input'", $body);
        $this->assertStringContainsString('isContentEditable', $body);
        $this->assertStringContainsString('metaKey', $body,
            'a modifier chord belongs to the browser, not to this page');
    }

    /** Bound at the window, or the shortcuts are unreachable from the dossier. */
    public function test_the_keys_are_reachable_from_anywhere_on_the_page(): void
    {
        $this->assertStringContainsString('@keydown.window="key($event)"', $this->ballot());
    }

    // ══ the accessibility floor ══════════════════════════════════════════════

    /** A placeholder is not a label — it disappears the moment somebody types. */
    public function test_the_search_has_a_real_label(): void
    {
        $s = $this->ballot();

        $this->assertMatchesRegularExpression('~<label[^>]*for="jcFind"~', $s,
            'the search box is labelled by its placeholder alone');
        $this->assertStringContainsString('id="jcFind"', $s);
    }

    /** A list that silently empties under a search is a dead end for anybody not watching. */
    public function test_the_result_count_is_announced(): void
    {
        $s = $this->ballot();
        $this->assertMatchesRegularExpression('~role="status"\s+aria-live="polite"\s+x-text="shownLabel"~', $s);
    }

    /** Three near-identical buttons: the selected one cannot be signalled by colour alone. */
    public function test_the_segment_state_is_not_colour_alone(): void
    {
        $s = $this->ballot();

        $this->assertStringContainsString(":aria-pressed=\"only===o.k ? 'true' : 'false'\"", $s,
            'the chosen filter is not exposed to assistive tech');
        $this->assertMatchesRegularExpression('~\.jc-seg__b\.is-on\{[^}]*font-weight:700~', $s,
            'the chosen filter differs only by colour');
        $this->assertMatchesRegularExpression('~\.jc-seg__b\.is-on\{[^}]*border-color~', $s);
    }

    /** iOS zooms the whole page when a font under 16px takes focus. */
    public function test_the_search_input_does_not_zoom_ios(): void
    {
        $this->assertMatchesRegularExpression('~\.jc-find__in\{[^}]*font-size:16px~', $this->ballot());
    }

    /** Every control here is reachable one-handed on a tablet. */
    public function test_the_controls_clear_the_touch_target_floor(): void
    {
        $s = $this->ballot();
        $this->assertMatchesRegularExpression('~\.jc-find__in\{[^}]*min-height:44px~', $s);
        $this->assertMatchesRegularExpression('~\.jc-seg__b\{[^}]*min-height:44px~', $s);
    }

    /** Focus must be visible on all three, and it is easy to style one and forget another. */
    public function test_focus_is_visible_on_the_new_controls(): void
    {
        $s = $this->ballot();
        $this->assertStringContainsString('.jc-find__in:focus-visible', $s);
        $this->assertStringContainsString('.jc-seg__b:focus-visible', $s);
    }

    // ══ the states ═══════════════════════════════════════════════════════════

    /**
     * "Nothing matches your search" and "there is nothing here" are different problems and
     * must not look alike: one means try another word, the other means stop looking.
     */
    public function test_an_empty_result_is_not_the_same_as_an_empty_panel(): void
    {
        $s = $this->ballot();

        $this->assertStringContainsString('Nothing matches', $s);
        $this->assertStringContainsString('Every nominee on this panel has been scored', $s);
        $this->assertStringContainsString("No nominees yet for this programme's current cycle", $s,
            'the pre-existing empty state must survive');
    }

    /** The resuming question is the default view. */
    public function test_what_is_left_is_what_a_judge_sees_first(): void
    {
        $this->assertStringContainsString("only: 'todo'", $this->ballot(),
            'a judge coming back is asking what they have not done');
    }

    /** A half-remembered surname and "the entry from Kenya" must both land. */
    public function test_search_covers_name_category_and_country(): void
    {
        $s = $this->ballot();
        $from = (int) strpos($s, 'get shown()');
        $body = substr($s, $from, 1400);

        foreach (['n.name', 'n.category', 'n.country_code'] as $field) {
            $this->assertStringContainsString($field, $body, $field . ' is not searchable');
        }
        $this->assertStringContainsString('toLowerCase()', $body, 'search is case sensitive');
    }

    /** A hint telling a phone user which key to press is a lie on a phone. */
    public function test_the_keyboard_hint_is_hidden_where_there_is_no_keyboard(): void
    {
        $s = $this->ballot();
        $from = (int) strpos($s, '@media (max-width:860px)');

        $this->assertStringContainsString('.jc-find__kb{ display:none }', substr($s, $from, 500));
    }
}
