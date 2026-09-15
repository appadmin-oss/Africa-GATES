<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShortlistRule;
use AfricaGates\Services\ShortlistService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Publishing a shortlist, and the one property that makes it a document rather than a query.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A shortlist computed from votes moves every time a ballot lands. So "top 10" evaluated on
 * read is not something anybody can be told: a nominee reads it, forwards it, and by the
 * time somebody else opens the same page it says something else.
 *
 * Publishing freezes it. Every test in here is a way that freeze could leak — the entries
 * joining live counts instead of carrying their own, a rename rewriting a published name, a
 * republish leaving two live lists, a pending or merged nominee slipping in.
 */
final class ShortlistPublishTest extends TestCase
{
    private const CYCLE = 1;
    private const CAT   = 1;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insert([
            'id' => 1, 'slug' => 'gates', 'title' => 'Africa GATES', 'is_active' => 1, 'sort_order' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => 1, 'year' => 2026, 'status' => 'voting',
            'edition_label' => 'Third Edition',
        ]);
        DB::table('gates_award_categories')->insert([
            'id' => self::CAT, 'cycle_id' => self::CYCLE, 'slug' => 'health', 'title' => 'Community Health',
            'sort_order' => 1,
        ]);
    }

    /**
     * @param array<string,mixed> $over
     *
     * `$over + [defaults]` and NOT the reverse: `+` keeps the left-hand value for a
     * duplicate key, so `[defaults] + $over` silently discards every override and this
     * helper would insert an approved nominee no matter what a test asked for.
     */
    private function nominee(int $id, string $name, int $votes, array $over = []): void
    {
        DB::table('gates_nominees')->insert($over + [
            'id' => $id, 'category_id' => self::CAT, 'name' => $name,
            'vote_count' => $votes, 'organic_vote_count' => $votes,
            'status' => 'approved', 'country_code' => 'NG',
        ]);
    }

    private function seedField(): void
    {
        foreach ([[1, 'Amina', 90], [2, 'Bello', 80], [3, 'Chidi', 70], [4, 'Dami', 60], [5, 'Emeka', 50]] as [$i, $n, $v]) {
            $this->nominee($i, $n, $v);
        }
    }

    // ══ the freeze ═══════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. Ten thousand votes arrive after publication and the published
     * list does not move — not the names, not the order, not the numbers printed beside them.
     */
    public function test_votes_arriving_after_publication_change_nothing_about_the_published_list(): void
    {
        $this->seedField();
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 3, 1), 7);

        $r = ShortlistService::publish(self::CYCLE, self::CAT, 7);
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['count']);

        // Emeka, who was fifth on 50, overtakes everybody.
        DB::table('gates_nominees')->where('id', 5)->update(['vote_count' => 10_000, 'organic_vote_count' => 10_000]);

        $live    = ShortlistService::published(self::CAT);
        $entries = ShortlistService::entries((int) $live->id);

        $this->assertSame(['Amina', 'Bello', 'Chidi'], array_column($entries, 'nominee_name'),
            'the published list must not be recomputed');
        $this->assertSame([90, 80, 70], array_map('intval', array_column($entries, 'vote_count')),
            'the printed counts must be the ones that were true at publication');

        // And the live preview HAS moved — which is the whole reason the two are separate.
        $this->assertSame(['Emeka', 'Amina', 'Bello'],
            array_column(array_slice(ShortlistService::preview(self::CYCLE, self::CAT)['rows'], 0, 3), 'name'));
    }

    /** A nominee renamed afterwards must not rewrite a document already sent out. */
    public function test_renaming_a_nominee_does_not_rewrite_a_published_shortlist(): void
    {
        $this->seedField();
        ShortlistService::publish(self::CYCLE, self::CAT, 7);

        DB::table('gates_nominees')->where('id', 1)->update(['name' => 'Somebody Else Entirely']);

        $live = ShortlistService::published(self::CAT);
        $this->assertSame('Amina', ShortlistService::entries((int) $live->id)[0]['nominee_name']);
    }

    /** The rule text travels with the snapshot, so a later edit cannot re-describe it. */
    public function test_the_published_row_carries_its_own_rule_and_not_a_pointer_to_an_editable_one(): void
    {
        $this->seedField();
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 7);
        ShortlistService::publish(self::CYCLE, self::CAT, 7);

        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('min_votes', 999), 7);

        $live = ShortlistService::published(self::CAT);
        $this->assertStringContainsString('Top 2', (string) $live->rule_text,
            'the document must keep describing how it was actually drawn');
        $this->assertSame(2, (int) $live->entry_count);
    }

    // ══ republishing ═════════════════════════════════════════════════════════

    public function test_republishing_supersedes_and_leaves_exactly_one_live_list(): void
    {
        $this->seedField();
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 7);
        $first = ShortlistService::publish(self::CYCLE, self::CAT, 7);

        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 4, 1), 7);
        $second = ShortlistService::publish(self::CYCLE, self::CAT, 7);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(1, DB::table('gates_shortlists')
            ->where('category_id', self::CAT)->where('status', 'published')->count(),
            'two live shortlists for one category would make "the shortlist" ambiguous');

        // The superseded one is KEPT — a nominee who was on a list and then was not is
        // exactly the situation somebody needs the history for.
        $this->assertSame('superseded', (string) DB::table('gates_shortlists')
            ->where('id', $first['id'])->value('status'));
        $this->assertSame(2, DB::table('gates_shortlist_entries')
            ->where('shortlist_id', $first['id'])->count(), 'the old entries survive as evidence');
    }

    public function test_withdrawing_keeps_the_record_but_clears_the_live_list(): void
    {
        $this->seedField();
        $p = ShortlistService::publish(self::CYCLE, self::CAT, 7);

        $this->assertTrue(ShortlistService::withdraw($p['id'], 7));
        $this->assertNull(ShortlistService::published(self::CAT));
        $this->assertSame('withdrawn', (string) DB::table('gates_shortlists')->where('id', $p['id'])->value('status'));
        $this->assertGreaterThan(0, DB::table('gates_shortlist_entries')->where('shortlist_id', $p['id'])->count());
    }

    // ══ refusals ═════════════════════════════════════════════════════════════

    /**
     * Publishing zero names announces that nobody qualified. That is a real statement and an
     * organiser must not arrive at it by leaving a threshold too high.
     */
    public function test_publishing_an_empty_selection_is_refused_with_a_reason(): void
    {
        $this->seedField();
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('min_votes', 100_000), 7);

        $r = ShortlistService::publish(self::CYCLE, self::CAT, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('selects nobody', $r['message']);
        $this->assertSame(0, DB::table('gates_shortlists')->count(), 'nothing may be written');
    }

    // ══ who is eligible ══════════════════════════════════════════════════════

    public function test_a_pending_nomination_is_not_in_the_running(): void
    {
        $this->nominee(1, 'Approved', 10);
        $this->nominee(2, 'Pending', 999, ['status' => 'pending']);

        $p = ShortlistService::preview(self::CYCLE, self::CAT);

        $this->assertSame(['Approved'], array_column($p['rows'], 'name'));
        $this->assertSame(1, $p['considered']);
    }

    /** A merged duplicate would put one person on the list twice under two spellings. */
    public function test_a_merged_duplicate_does_not_appear_beside_the_record_it_was_merged_into(): void
    {
        $this->nominee(1, 'Amina Bello', 90);
        $this->nominee(2, 'A. Bello', 40, ['merged_into' => 1]);

        $p = ShortlistService::preview(self::CYCLE, self::CAT);

        $this->assertSame(['Amina Bello'], array_column($p['rows'], 'name'));
    }

    // ══ the rule fallback chain ══════════════════════════════════════════════

    public function test_a_category_rule_beats_the_cycle_rule_which_beats_the_built_in_default(): void
    {
        $this->assertSame('default', ShortlistService::ruleFor(self::CYCLE, self::CAT)['scope']);

        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_pct', 25), 7);
        $cycle = ShortlistService::ruleFor(self::CYCLE, self::CAT);
        $this->assertSame('cycle', $cycle['scope']);
        $this->assertSame('top_pct', $cycle['rule']->mode);

        ShortlistService::saveRule(self::CYCLE, self::CAT, new ShortlistRule('min_votes', 5), 7);
        $own = ShortlistService::ruleFor(self::CYCLE, self::CAT);
        $this->assertSame('category', $own['scope']);
        $this->assertSame('min_votes', $own['rule']->mode);

        ShortlistService::clearRule(self::CYCLE, self::CAT);
        $this->assertSame('cycle', ShortlistService::ruleFor(self::CYCLE, self::CAT)['scope'],
            'clearing an override falls back rather than to the built-in default');
    }

    /**
     * Saving twice is an update, not a second row. A double-submitted form used to be able
     * to leave two rules for one scope, and the preview would silently take whichever the
     * engine returned first.
     */
    public function test_saving_a_rule_twice_updates_it_rather_than_creating_a_rival(): void
    {
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 5), 7);
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 8), 7);

        $this->assertSame(1, DB::table('gates_shortlist_rules')
            ->where('cycle_id', self::CYCLE)->whereNull('category_id')->count());
        $this->assertSame(8, ShortlistService::ruleFor(self::CYCLE, null)['rule']->threshold);
    }

    // ══ the read helpers the rest of the site uses ═══════════════════════════

    public function test_a_nominee_knows_whether_they_are_on_a_published_list(): void
    {
        $this->seedField();
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 7);
        $p = ShortlistService::publish(self::CYCLE, self::CAT, 7);

        $this->assertTrue(ShortlistService::isShortlisted(1));
        $this->assertFalse(ShortlistService::isShortlisted(5));
        $this->assertSame([1 => true, 2 => true], ShortlistService::shortlistedIn(self::CYCLE));

        // Withdrawn is not shortlisted — the badge must disappear with the list.
        ShortlistService::withdraw($p['id'], 7);
        $this->assertFalse(ShortlistService::isShortlisted(1));
        $this->assertSame([], ShortlistService::shortlistedIn(self::CYCLE));
    }

    public function test_the_overview_reports_drift_between_a_published_list_and_the_live_rule(): void
    {
        $this->seedField();
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 7);
        ShortlistService::publish(self::CYCLE, self::CAT, 7);

        $row = ShortlistService::overview(self::CYCLE)[0];
        $this->assertFalse($row['drifted'], 'nothing has changed yet');

        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 4, 1), 7);
        $row = ShortlistService::overview(self::CYCLE)[0];

        $this->assertTrue($row['drifted'], 'the rule now says four and the published list says two');
        $this->assertSame(2, (int) $row['published']->entry_count, 'and the published list is untouched');
    }

    /** The tie group is recorded on the row, so eleven names under a rule of ten explain themselves. */
    public function test_an_entry_level_with_the_cut_is_flagged_in_the_snapshot(): void
    {
        $this->nominee(1, 'A', 90);
        $this->nominee(2, 'B', 70);
        $this->nominee(3, 'C', 70);
        $this->nominee(4, 'D', 10);
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1, 'include'), 7);

        $p = ShortlistService::publish(self::CYCLE, self::CAT, 7);
        $this->assertSame(3, $p['count'], 'a rule of two took three, because two were level');

        $flags = array_map('intval', array_column(ShortlistService::entries($p['id']), 'tied_at_cut'));
        $this->assertSame([0, 1, 1], $flags);
    }
}
