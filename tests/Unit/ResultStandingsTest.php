<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CyclePhase;
use AfricaGates\Services\PublicResults;
use AfricaGates\Services\ResultStatus;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `/results` ANSWERS "WHERE DOES THIS AWARD STAND", AND PUBLISHES NO ORDER WHILE IT RUNS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE THIS EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * No leader is published for an award while its voting is open — at any level, in any
 * column, in any sentence. Publishing a running order turns an open window into a
 * bandwagon, and on a platform that sells vote packs it turns a results page into a sales
 * page: a supporter could read off exactly how many votes buy the lead, and we would be
 * the ones telling them.
 *
 * Vote COUNTS are published, because participation is a fact about the process and a
 * nominee is entitled to see their own support. The distinction is the whole point, so it
 * is asserted here in both directions rather than left to three templates to remember.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE FAULT THAT CAME OUT OF THE FIRST RENDER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A cycle materialised to `results`, with six published awards and a named winner, can
 * carry a `results_date` still in the future and no voting windows at all — an operator
 * releasing early, or an import. {@see \AfricaGates\Services\CyclePolicy::phaseFor()}
 * correctly answers `Upcoming` for it, and the first cut of this page printed "Not open
 * yet" over an edition whose results a reader could already open.
 *
 * The phase is authoritative about what may HAPPEN to a cycle and is deliberately derived
 * from the calendar rather than from a column a dead scheduler writes. It is not
 * authoritative about what has already happened: a drawn award is proof the materialiser
 * crowned and announced it, in a transaction. So a decided count settles the status before
 * the phase is consulted, and that is pinned below on the exact shape that broke it.
 */
final class ResultStandingsTest extends TestCase
{
    private int $programmeId;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slug = 'st-' . bin2hex(random_bytes(3));
        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $this->slug, 'title' => 'Standing Awards', 'is_active' => 1,
        ]);
    }

    /** @param array<string,mixed> $windows */
    private function cycle(int $year, string $status, array $windows = []): int
    {
        return (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => $year, 'status' => $status,
            'edition_label' => $year . ' edition',
        ] + $windows);
    }

    private function category(int $cycleId, string $title, int $sort = 1): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycleId, 'slug' => 'c-' . bin2hex(random_bytes(4)),
            'title' => $title, 'sort_order' => $sort,
        ]);
    }

    private function nominee(int $categoryId, string $name, int $votes): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId, 'name' => $name, 'status' => 'approved',
            'vote_count' => $votes, 'organic_vote_count' => $votes,
        ]);
    }

    /**
     * A complete panel for one nominee.
     *
     * Per-CRITERION rows through the programme's real rubric, which is how this platform
     * stores a mark, and WHOLE numbers only — `score` is a TINYINT, so a fractional mark
     * is a tenth apart on SQLite and identical on the production database.
     */
    private function panel(int $categoryId, int $nominee, int $mark): void
    {
        static $n = 0;
        for ($k = 0; $k < 2; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Judge ' . (++$n), 'is_active' => 1,
                'email' => 'st-j' . $n . '-' . bin2hex(random_bytes(3)) . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (\AfricaGates\Services\JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $categoryId,
                    'criterion_id' => (int) $c->id, 'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    /** A decided award, panel and all. */
    private function award(int $cycleId, string $title, string $winner, int $votes, int $sort = 1): int
    {
        $c = $this->category($cycleId, $title, $sort);
        $a = $this->nominee($c, $winner, $votes);
        $b = $this->nominee($c, $winner . ' (second)', (int) ($votes * 0.6));
        $this->panel($c, $a, 9);
        $this->panel($c, $b, 7);
        return $c;
    }

    /** A cycle whose voting window is open right now. */
    private function counting(int $year = 2027): int
    {
        return $this->cycle($year, 'voting', [
            'nominations_open'  => Carbon::now()->subDays(60)->toDateTimeString(),
            'nominations_close' => Carbon::now()->subDays(20)->toDateTimeString(),
            'voting_open'       => Carbon::now()->subDays(6)->toDateTimeString(),
            'voting_close'      => Carbon::now()->addDays(5)->toDateTimeString(),
            'results_date'      => Carbon::now()->addDays(30)->toDateTimeString(),
        ]);
    }

    /** @param list<array<string,mixed>> $editions */
    private function find(array $editions, int $cycleId): ?array
    {
        foreach ($editions as $e) if ((int) $e['cycle_id'] === $cycleId) return $e;

        return null;
    }

    // ══ the rule ═════════════════════════════════════════════════════════════

    public function test_an_award_that_is_counting_publishes_its_votes_and_no_order(): void
    {
        $cy  = $this->counting();
        $cat = $this->category($cy, 'Teachers’ Choice');
        $this->nominee($cat, 'Way Ahead', 4000);
        $this->nominee($cat, 'Far Behind', 12);

        $row = $this->find(PublicResults::standings()['editions'], $cy);
        $this->assertNotNull($row, 'an open award is missing from the page named after its results');

        $this->assertSame(ResultStatus::COUNTING, $row['status']['key']);
        $this->assertFalse($row['status']['publishes_standing'],
            'the page is willing to publish a standing while voting is open');

        // Participation IS published — a nominee is entitled to see their own support.
        $this->assertSame(4012, $row['votes']);

        // And the order is not, by there being nothing to leak rather than by a filter.
        $this->assertSame([], $row['awards']);
        $this->assertNull($row['overall']);
        $this->assertNull($row['top']);
    }

    public function test_no_nominee_in_an_open_edition_reaches_the_page_at_all(): void
    {
        // The stronger form of the same rule, asserted on the whole payload rather than on
        // the three keys somebody remembered to empty. A leader that arrives through a key
        // nobody thought about is the way this rule actually breaks.
        $cy  = $this->counting();
        $cat = $this->category($cy, 'Teachers’ Choice');
        $this->nominee($cat, 'Zaynab Unmistakable Name', 4000);

        $row  = $this->find(PublicResults::standings()['editions'], $cy);
        $open = PublicResults::openEdition(PublicResults::editionSlug($this->slug, 2027));

        $this->assertStringNotContainsString('Zaynab Unmistakable Name', json_encode($row),
            'a nominee reached the results index while their award was still open');
        $this->assertNotNull($open);
        $this->assertStringNotContainsString('Zaynab Unmistakable Name', json_encode($open),
            'a nominee reached the edition page while their award was still open');
    }

    // ══ the status vocabulary ════════════════════════════════════════════════

    public function test_a_drawn_award_is_decided_whatever_the_calendar_says(): void
    {
        // THE SHAPE THAT BROKE IT. Released and published, with a results date still in
        // the future and no voting windows — so the computed phase is `Upcoming`, which is
        // correct about what may happen and says nothing about what already has.
        $cy = $this->cycle(2026, 'results', [
            'results_date' => Carbon::now()->addDays(60)->toDateTimeString(),
        ]);
        $this->award($cy, 'Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500);

        $this->assertSame(CyclePhase::Upcoming,
            \AfricaGates\Services\CyclePolicy::phaseFor(
                DB::table('gates_award_cycles')->where('id', $cy)->first()),
            'the fixture no longer reproduces the phase that caused the fault');

        $row = $this->find(PublicResults::standings()['editions'], $cy);
        $this->assertNotNull($row);
        $this->assertSame(ResultStatus::DECIDED, $row['status']['key'],
            'a published edition is being described by its calendar rather than by its awards');
        $this->assertTrue($row['status']['publishes_standing']);

        // And every one of its category rows agrees, for the same reason.
        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertNotNull($e);
        $this->assertSame([ResultStatus::DECIDED],
            array_values(array_unique(array_map(
                static fn (array $c): string => $c['status']['key'], $e['categories']))));
    }

    public function test_withheld_outranks_the_phase_and_decided_never_hides_a_held_award(): void
    {
        // A held award sits in an announced cycle, so its phase says `results`. Reading the
        // phase first prints "Decided" over an award with no result on it.
        $held = ResultStatus::forAward(CyclePhase::Results, PublicResults::HELD_NOBODY);
        $this->assertSame(ResultStatus::WITHHELD, $held['key']);
        $this->assertFalse($held['publishes_standing']);
        $this->assertSame('withheld', $held['meaning'],
            'a withheld award must carry the caution meaning, never live or honour');

        // An edition is decided when it has ONE decided award, not when every award is.
        // Otherwise a single verification holds an entire announced edition under a word
        // meaning its eleven published results cannot be opened.
        $this->assertSame(ResultStatus::DECIDED,
            ResultStatus::forEdition(CyclePhase::Results, 11, PublicResults::HELD_DARK)['key']);
        $this->assertSame(ResultStatus::WITHHELD,
            ResultStatus::forEdition(CyclePhase::Results, 0, PublicResults::HELD_DARK)['key']);
    }

    public function test_only_the_status_that_is_true_right_now_is_allowed_a_colour(): void
    {
        // The page's one colour event. Judging and decided have NO meaning at all, and
        // that is the budget rather than an omission: a panel's progress is a total, not a
        // state, and a decided award on a page that is mostly good news is plain ink.
        $this->assertSame('counting', ResultStatus::forAward(CyclePhase::Voting)['meaning']);
        $this->assertNull(ResultStatus::forAward(CyclePhase::Judging)['meaning']);
        $this->assertNull(ResultStatus::decided()['meaning']);

        // Every meaning named here must be one Accent knows — Accent::for() throws on one
        // nobody defined, so a typo would paint "withheld" in the colour of "counting".
        foreach ([CyclePhase::Voting, CyclePhase::Judging, CyclePhase::Results] as $p) {
            $m = ResultStatus::forAward($p)['meaning'];
            if ($m !== null) $this->assertNotSame([], \AfricaGates\Support\Accent::for($m));
        }
        $this->assertNotSame([], \AfricaGates\Support\Accent::for(
            ResultStatus::forAward(CyclePhase::Results, PublicResults::HELD_DARK)['meaning']));
    }

    // ══ the ordering ═════════════════════════════════════════════════════════

    public function test_what_is_open_now_is_read_first(): void
    {
        $done = $this->cycle(2025, 'results',
            ['results_date' => Carbon::now()->subDays(30)->toDateTimeString()]);
        $this->award($done, 'Teachers’ Choice', 'Long Decided', 900);

        $open = $this->counting();
        $this->nominee($this->category($open, 'Still Counting'), 'Somebody', 40);

        $keys = array_map(static fn (array $e): string => $e['status']['key'],
                          PublicResults::standings()['editions']);

        $this->assertSame(ResultStatus::COUNTING, $keys[0],
            'the archive is being read before the award that is open right now');

        // And the alternative orders are available, because an order is a way through a
        // list and never a claim about what is in it.
        $byDate = PublicResults::standings(24, ['order' => 'date'])['editions'];
        $this->assertGreaterThan((int) $byDate[1]['year'], (int) $byDate[0]['year']);
    }

    public function test_an_unrecognised_order_is_the_default_rather_than_an_error(): void
    {
        // Every value arrives from a URL, so every value is untrusted. A stale or mistyped
        // link is somebody trying to read a results page; answering them with an error
        // over a sort key is absurd.
        $this->assertSame('status',
            PublicResults::standings(24, ['order' => '../../etc/passwd'])['view']['order']);
        $this->assertSame('date', PublicResults::standings(24, ['order' => 'DATE'])['view']['order']);
    }

    public function test_a_programme_filter_never_deletes_the_way_back_out_of_itself(): void
    {
        $a = $this->cycle(2025, 'results',
            ['results_date' => Carbon::now()->subDays(30)->toDateTimeString()]);
        $this->award($a, 'Teachers’ Choice', 'One', 900);

        $s = PublicResults::standings(24, ['programme' => 'Standing Awards']);
        $this->assertSame(1, $s['shown']);

        // The chips come off the WHOLE page, so the programme just filtered to is still
        // offered — a filter control that removes its own undo is the interaction people
        // report as the site being broken.
        $this->assertNotSame([], $s['programmes']);
        $this->assertContains('Standing Awards',
            array_column($s['programmes'], 'name'));
    }

    // ══ the page an open edition gets ════════════════════════════════════════

    public function test_an_open_edition_has_a_page_and_an_announced_one_is_not_served_by_it(): void
    {
        $cy = $this->counting();
        $this->category($cy, 'Teachers’ Choice');

        // Without this every counting row on /results links to a 404, which reads as the
        // award having been taken down.
        $open = PublicResults::openEdition(PublicResults::editionSlug($this->slug, 2027));
        $this->assertNotNull($open);
        $this->assertSame(ResultStatus::COUNTING, $open['status']['key']);
        $this->assertCount(1, $open['categories']);

        // And the released gate is NOT relaxed: `edition()` still refuses it, so the two
        // pages cannot be confused for one another by a caller.
        $this->assertNull(PublicResults::edition(PublicResults::editionSlug($this->slug, 2027)));

        // Nor does this one answer for an announced edition — that is `edition()`'s job,
        // and two methods answering for one URL is how a standing gets served by the one
        // that was built not to have it.
        $done = $this->cycle(2024, 'results',
            ['results_date' => Carbon::now()->subDays(30)->toDateTimeString()]);
        $this->award($done, 'Teachers’ Choice', 'Announced', 900);
        $this->assertNull(PublicResults::openEdition(
            PublicResults::editionSlug($this->slug, 2024)));
    }

    public function test_a_panels_progress_is_counted_and_never_reaches_a_hundred_early(): void
    {
        $cy = $this->cycle(2028, 'judging', [
            'voting_open'  => Carbon::now()->subDays(40)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDays(4)->toDateTimeString(),
            'results_date' => Carbon::now()->addDays(21)->toDateTimeString(),
        ]);
        $cat = $this->category($cy, 'Best New Voice');
        $a   = $this->nominee($cat, 'Marked', 100);
        $this->nominee($cat, 'Unmarked', 90);
        $this->nominee($cat, 'Also unmarked', 80);
        $this->panel($cat, $a, 8);          // two complete cards, for one of three nominees

        $open = PublicResults::openEdition(PublicResults::editionSlug($this->slug, 2028));
        $this->assertNotNull($open);
        $this->assertSame(ResultStatus::JUDGING, $open['status']['key']);

        $p = $open['categories'][0]['progress'];
        $this->assertNotNull($p, 'a panel that is marking shows no progress at all');
        $this->assertSame(2, $p['done']);
        $this->assertSame(6, $p['needed'],
            'the denominator is not nominees × quorum — the two nobody has marked are '
          . 'missing from the total, so the bar would read complete while they wait');

        // ── THE ROUNDING DIRECTION IS THE GUARD, SO IT IS PINNED ─────────────
        //
        // 2 of 6 is 33.33, and the rule is that a partial panel never reports complete —
        // which only bites at 99.x, where a fixture would need a hundred scorecards to
        // reach. Floor and ceil differ HERE, so pinning 33 is what actually holds the rule
        // at the figure nobody can afford to test directly. Asserting only `< 100` passes
        // on a `ceil` implementation, which was verified by writing one.
        $this->assertSame(33, $p['pct'],
            'the bar is not floored — rounded up, a panel one scorecard short of a '
          . 'hundred reads as finished, and the page contradicts its own status');
        $this->assertLessThan(100, $p['pct']);
    }
}
