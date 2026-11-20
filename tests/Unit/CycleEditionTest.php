<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CycleEdition;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * OPENING NEXT YEAR'S EDITION OF AN AWARD.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THERE WAS BEFORE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * No way to do it. The cycle screen edits exactly one cycle — whichever the public site is
 * running — and past editions rendered as chips that could not be clicked, so last year's
 * dates, label and categories were unreachable from a console on a host with no shell.
 *
 * Worse than merely missing: the form posts the CURRENT cycle's id, so an operator who
 * reasonably tried "change the year to 2027 and save" did not open next year's edition —
 * they RENAMED this year's, taking its nominees, votes and scores with it. Opening one is a
 * different intention and now a different action.
 */
final class CycleEditionTest extends TestCase
{
    private int $programmeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'ed-' . bin2hex(random_bytes(3)),
            'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
    }

    private function edition(int $year, string $status = 'archived'): int
    {
        return (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => $year, 'status' => $status,
        ]);
    }

    private function category(int $cycleId, string $slug, string $title, int $order = 0): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycleId, 'slug' => $slug, 'title' => $title,
            'sort_order' => $order,
        ]);
    }

    // ══ what carries ═════════════════════════════════════════════════════════

    /**
     * THE SHAPE OF THE AWARD CARRIES, AND NOTHING ELSE DOES.
     *
     * Retyping five categories every year is how a slug drifts and last year's links break.
     */
    public function test_the_categories_carry_over(): void
    {
        $last = $this->edition(2026, 'results');
        $this->category($last, 'primary',   'Primary School Principal', 1);
        $this->category($last, 'secondary', 'Secondary School Principal', 2);

        $r = CycleEdition::open($this->programmeId, 2027);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(2, $r['categories']);

        $rows = DB::table('gates_award_categories')->where('cycle_id', $r['cycle_id'])
            ->orderBy('sort_order')->get(['slug', 'title', 'sort_order'])->all();

        $this->assertSame(['primary', 'secondary'], array_column(
            array_map(static fn ($o): array => (array) $o, $rows), 'slug'));
        $this->assertSame('Primary School Principal', (string) $rows[0]->title);
    }

    /**
     * AND NOT ONE NOMINEE, VOTE OR SCORE.
     *
     * A new edition is a fresh contest. A carried-over nominee would arrive already holding
     * last year's tally, which is the one thing that must never happen to an award.
     */
    public function test_nothing_but_the_categories_comes_with_it(): void
    {
        $last = $this->edition(2026, 'results');
        $cat  = $this->category($last, 'primary', 'Primary');
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => 'Dr. Adegboyega Aborode',
            'status' => 'winner', 'vote_count' => 1955, 'organic_vote_count' => 1955,
        ]);

        $r = CycleEdition::open($this->programmeId, 2027);
        $newCats = DB::table('gates_award_categories')->where('cycle_id', $r['cycle_id'])
            ->pluck('id')->all();

        $this->assertSame(0, (int) DB::table('gates_nominees')
            ->whereIn('category_id', $newCats)->count(),
            'a nominee came across and arrived holding last year’s tally');
    }

    /**
     * NO DATES, AND THAT IS THE POINT.
     *
     * A results date is a promise. One inherited from last year is a promise nobody made,
     * arriving already passed — `PublicResults::delayed()` would publish a "this award is
     * late" notice on an edition that has not opened.
     */
    public function test_the_new_edition_has_no_dates_and_is_upcoming(): void
    {
        $last = $this->edition(2026, 'results');
        DB::table('gates_award_cycles')->where('id', $last)->update([
            'nominations_open' => '2026-07-01 12:00:00',
            'results_date'     => '2026-09-01 12:00:00',
        ]);

        $r = CycleEdition::open($this->programmeId, 2027);
        $new = DB::table('gates_award_cycles')->where('id', $r['cycle_id'])->first();

        $this->assertSame('upcoming', (string) $new->status);
        foreach (['nominations_open', 'nominations_close', 'voting_open',
                  'voting_close', 'results_date'] as $f) {
            $this->assertNull($new->$f, "{$f} was inherited — a date nobody set is a promise nobody made");
        }
    }

    // ══ what it refuses ══════════════════════════════════════════════════════

    /** One edition per year. A second press is a refusal, not a second empty 2027. */
    public function test_it_refuses_a_year_the_programme_already_has(): void
    {
        $this->edition(2027, 'upcoming');

        $r = CycleEdition::open($this->programmeId, 2027);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already has a 2027', $r['message']);
        $this->assertSame(1, (int) DB::table('gates_award_cycles')
            ->where('programme_id', $this->programmeId)->where('year', 2027)->count());
    }

    /**
     * AND A YEAR OUTSIDE A SANE RANGE.
     *
     * `year` is a MySQL YEAR column on production, which coerces out-of-range values rather
     * than refusing them — a typo'd 20267 stored as something else is an edition nobody can
     * find by its number.
     */
    public function test_it_refuses_an_implausible_year(): void
    {
        foreach ([1900, 20267, 0, (int) date('Y') + 40] as $bad) {
            $this->assertFalse(CycleEdition::open($this->programmeId, $bad)['ok'],
                $bad . ' was accepted as a year');
        }
    }

    /** Nothing is half-created when it refuses. */
    public function test_a_refusal_leaves_no_cycle_behind(): void
    {
        $before = (int) DB::table('gates_award_cycles')
            ->where('programme_id', $this->programmeId)->count();

        CycleEdition::open($this->programmeId, 20267);
        CycleEdition::open(0, 2027);

        $this->assertSame($before, (int) DB::table('gates_award_cycles')
            ->where('programme_id', $this->programmeId)->count());
    }

    // ══ and the details that make it usable ══════════════════════════════════

    /**
     * THE SOURCE IS THE MOST RECENT EDITION THAT HAS CATEGORIES.
     *
     * Not simply the most recent. A programme whose last edition was opened and abandoned
     * would otherwise carry nothing forward while the operator was told it had worked.
     */
    public function test_it_carries_from_the_last_edition_that_actually_had_any(): void
    {
        $old = $this->edition(2025, 'archived');
        $this->category($old, 'primary', 'Primary');
        $this->edition(2026, 'upcoming');          // opened and abandoned — no categories

        $r = CycleEdition::open($this->programmeId, 2027);

        $this->assertSame(1, $r['categories'],
            'it carried from the empty edition and reported success over nothing');
    }

    /** An operator can decline the carry-over. */
    public function test_the_carry_over_can_be_declined(): void
    {
        $last = $this->edition(2026, 'results');
        $this->category($last, 'primary', 'Primary');

        $r = CycleEdition::open($this->programmeId, 2027, '', false);

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['categories']);
    }

    /**
     * THE YEAR OFFERED IS NEXT YEAR, OR THIS ONE — WHICHEVER IS LATER.
     *
     * A programme whose last edition was 2019 should not be offered 2020: somebody opening
     * it now means to run it now.
     */
    public function test_the_offered_year_is_never_in_the_past(): void
    {
        $this->assertSame((int) date('Y'), CycleEdition::nextYearFor($this->programmeId));

        $this->edition(2019, 'archived');
        $this->assertSame((int) date('Y'), CycleEdition::nextYearFor($this->programmeId));

        $this->edition((int) date('Y'), 'judging');
        $this->assertSame((int) date('Y') + 1, CycleEdition::nextYearFor($this->programmeId));
    }

    /**
     * THE LIST CARRIES WHAT IS IN EACH EDITION.
     *
     * "2025" orients nobody. "2025 · 5 categories · 41 nominees · archived" tells an
     * operator which one they want before they click it.
     */
    public function test_the_edition_list_says_what_is_in_each_one(): void
    {
        $a = $this->edition(2026, 'judging');
        $cat = $this->category($a, 'primary', 'Primary');
        DB::table('gates_nominees')->insert([
            ['category_id' => $cat, 'name' => 'A', 'status' => 'approved'],
            ['category_id' => $cat, 'name' => 'B', 'status' => 'approved'],
            // Not counted: a withdrawn entry is not in the edition.
            ['category_id' => $cat, 'name' => 'C', 'status' => 'pending'],
        ]);
        $this->edition(2025, 'archived');

        $list = CycleEdition::listFor($this->programmeId);

        $this->assertCount(2, $list);
        $this->assertSame(2026, (int) $list[0]['row']['year'], 'newest first');
        $this->assertSame(1, $list[0]['categories']);
        $this->assertSame(2, $list[0]['nominees']);
        $this->assertSame(0, $list[1]['categories']);
    }

    /**
     * AND THE NEW-EDITION FORM IS NOT INSIDE THE CYCLE FORM.
     *
     * An HTML parser DISCARDS a `<form>` start tag while one is already open — not nested,
     * not errored: dropped, with its children adopted by the outer form. The button would
     * render, style, enable, and post to `/cycle`, which SAVES THE CURRENT EDITION. That
     * has happened three times in this codebase.
     */
    public function test_the_open_form_posts_where_it_says_it_does(): void
    {
        $src = (string) preg_replace('~\{#.*?#\}~s', ' ',
            (string) file_get_contents(
                dirname(__DIR__, 2) . '/templates/admin/programmes/cycle.twig'));

        $cycleFormEnd = strpos($src, '</form>');
        $openForm     = strpos($src, '/editions"');

        $this->assertNotFalse($openForm, 'the open-an-edition form is not on the page');
        $this->assertGreaterThan($cycleFormEnd, $openForm,
            'the open-an-edition form is inside the cycle form, so the parser drops it and '
            . 'its button posts to /cycle — which saves the current edition instead');
    }
}
