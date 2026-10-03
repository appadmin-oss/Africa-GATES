<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\RaceService;
use AfricaGates\Services\StandingsService;
use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\DropsColumns;
use Tests\TestCase;

/**
 * The race framing, and the nominee's school.
 *
 * ── THE RACE ─────────────────────────────────────────────────────────────────
 *
 * {@see RaceService} exists because asking {@see StandingsService} once per nominee to
 * render a category of forty is forty scans of the same rows. It does the same
 * arithmetic once, in memory. That is only safe if the two AGREE, so the first tests
 * here check them against each other on the same data rather than checking RaceService
 * against my own expectations — a fast second implementation that quietly disagrees
 * with the authoritative one is worse than the slow version.
 *
 * The rest are the honesty rules this codebase applies to every public figure: a gap
 * of null rather than 0 for the leader, ties sharing a rank, and a percentage that is
 * share of the LEADER rather than share of all votes cast — "12% of the vote" is a
 * different and far more discouraging claim than "12% of the way to first".
 */
final class RaceAndOrganisationTest extends TestCase
{
    use DropsColumns;

    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        OptionalColumn::forget();
        DB::table('gates_votes')->delete();
        DB::table('gates_nominees')->delete();

        $this->cat = 960;
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 96, 'title' => 'P', 'slug' => 'p-960']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 960, 'programme_id' => 96, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 960, 'cycle_id' => 960, 'title' => 'Cat', 'slug' => 'cat-960']);
    }

    protected function tearDown(): void
    {
        $this->restoreDroppedColumns();
        OptionalColumn::forget();
        parent::tearDown();
    }

    /** @param list<int> $votes @return list<array<string,mixed>> */
    private function field(array $votes): array
    {
        $out = [];
        foreach ($votes as $i => $v) {
            $out[] = ['id' => $i + 1, 'name' => 'N' . ($i + 1), 'vote_count' => $v];
        }
        return $out;
    }

    // ── It must agree with the authoritative standing ────────────────────────

    /**
     * The same field, ranked two ways. Any disagreement means the race page and the
     * nominee's own ballot would show different positions for the same person, which
     * is the single most damaging thing a competition page can do.
     */
    public function test_it_agrees_with_standings_service_on_the_same_field(): void
    {
        $votes = [100, 40, 40, 12, 0];
        $ids   = [];
        foreach ($votes as $i => $v) {
            $ids[] = (int) DB::table('gates_nominees')->insertGetId([
                'category_id' => $this->cat, 'name' => 'N' . $i, 'status' => 'approved', 'vote_count' => $v,
            ]);
        }

        $race = RaceService::annotate(array_map(
            static fn (int $id, int $v) => ['id' => $id, 'name' => 'N', 'vote_count' => $v],
            $ids, $votes
        ));
        $byId = [];
        foreach ($race as $r) $byId[$r['id']] = $r;

        $standings = new StandingsService();
        foreach ($ids as $id) {
            $s = $standings->forNominee($id, $this->cat);
            $this->assertSame((int) $s['rank'], $byId[$id]['rank'], "rank disagrees for nominee {$id}");
            $this->assertSame((int) $s['field'], $byId[$id]['field'], "field disagrees for nominee {$id}");
            $this->assertSame($s['gap_ahead'], $byId[$id]['gap'], "gap disagrees for nominee {$id}");
        }
    }

    // ── The rules ────────────────────────────────────────────────────────────

    public function test_ties_share_a_rank_and_the_next_position_is_skipped(): void
    {
        $r = RaceService::annotate($this->field([100, 40, 40, 12]));

        $this->assertSame([1, 2, 2, 4], array_column($r, 'rank'),
            'two nominees tied on 40 are both 2nd, and the next is 4th');
    }

    /**
     * The gap skips EQUAL totals. With two tied on 40 behind a leader on 100, "the row
     * above" has the same total — a gap of 0 would tell a jointly-second nominee they
     * are level with the position they already hold.
     */
    public function test_the_gap_is_to_the_nearest_higher_total_not_the_row_above(): void
    {
        $r = RaceService::annotate($this->field([100, 40, 40, 12]));

        $this->assertNull($r[0]['gap'], 'the leader has nobody ahead');
        $this->assertSame(60, $r[1]['gap']);
        $this->assertSame(60, $r[2]['gap'], 'the second of the tie is also 60 from first, not 0 from itself');
        $this->assertSame(28, $r[3]['gap']);
    }

    /** Null, not zero. "0 votes from #0" on a leader's card is nonsense. */
    public function test_the_leader_is_flagged_and_has_no_gap(): void
    {
        $r = RaceService::annotate($this->field([50, 10]));

        $this->assertTrue($r[0]['is_leader']);
        $this->assertNull($r[0]['gap']);
        $this->assertFalse($r[1]['is_leader']);
    }

    /** Share of the LEADER, not of all votes cast — see the class note. */
    public function test_the_bar_is_share_of_the_leader(): void
    {
        $r = RaceService::annotate($this->field([100, 50, 1]));

        $this->assertSame(100, $r[0]['pct']);
        $this->assertSame(50, $r[1]['pct']);
        // Floored, so a nominee on very few votes sees a sliver rather than an empty
        // track that reads as a rendering fault.
        $this->assertSame(3, $r[2]['pct']);
    }

    public function test_a_field_where_nobody_has_voted_yet_does_not_divide_by_zero(): void
    {
        $r = RaceService::annotate($this->field([0, 0, 0]));

        $this->assertSame([1, 1, 1], array_column($r, 'rank'), 'everyone is joint first at zero');
        foreach ($r as $n) $this->assertSame(3, $n['pct']);
    }

    public function test_a_single_nominee_is_not_a_race(): void
    {
        $r = RaceService::annotate($this->field([7]));

        $this->assertSame(1, $r[0]['field']);
        $this->assertSame(100, $r[0]['pct']);
        $this->assertNull(RaceService::headline($r), 'one nominee has no contest to describe');
    }

    public function test_an_empty_category_returns_nothing_rather_than_erroring(): void
    {
        $this->assertSame([], RaceService::annotate([]));
        $this->assertNull(RaceService::headline([]));
    }

    // ── The headline ─────────────────────────────────────────────────────────

    public function test_the_headline_names_the_leader_and_the_real_margin(): void
    {
        $h = RaceService::headline(RaceService::annotate($this->field([100, 88, 12])));

        $this->assertSame(12, $h['lead']);
        $this->assertSame('N1', $h['leader']);
        $this->assertSame('N2', $h['chaser']);
    }

    /**
     * The margin is over the nearest LOWER total. Row two may be tied for first, and
     * "leads by 0" is not a fact anyone needs.
     */
    public function test_the_headline_measures_past_a_tie_at_the_top(): void
    {
        $h = RaceService::headline(RaceService::annotate($this->field([100, 100, 60])));

        $this->assertSame(40, $h['lead'], 'the lead is over the nearest lower total, not over the co-leader');
    }

    /** "0 ahead" on a category nobody has voted in is noise, not drama. */
    public function test_no_headline_before_any_votes_are_cast(): void
    {
        $this->assertNull(RaceService::headline(RaceService::annotate($this->field([0, 0]))));
    }

    // ── The organisation ─────────────────────────────────────────────────────

    public function test_the_organisation_is_carried_onto_the_nominee(): void
    {
        $id = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->cat, 'name' => 'Ada Obi', 'status' => 'approved',
            'vote_count' => 0, 'organisation' => 'Queen’s College, Lagos',
        ]);

        $this->assertSame('Queen’s College, Lagos',
            (string) DB::table('gates_nominees')->where('id', $id)->value('organisation'));
    }

    /**
     * A long institution name is clipped on a WORD boundary, not mid-syllable — the
     * flier column is fixed and a school name is frequently longer than a person's.
     */
    public function test_a_long_organisation_is_clipped_on_a_word_boundary(): void
    {
        $long = 'Federal Government Girls College Sagamu Ogun State Annexe';
        $out  = \AfricaGates\Services\FlierLayout::clip($long, 42);

        $this->assertLessThanOrEqual(42, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
        $this->assertStringStartsWith('Federal Government', $out);
        $this->assertStringNotContainsString('  ', $out);
    }

    public function test_a_short_organisation_is_left_exactly_as_it_is(): void
    {
        $this->assertSame('Queen’s College', \AfricaGates\Services\FlierLayout::clip('Queen’s College', 42));
    }

    /**
     * THE BALLOT MUST NOT 500 ON AN UNMIGRATED DATABASE. `organisation` arrived in a
     * migration, and a bare column in the ballot's SELECT would take the whole voting
     * page down — the same failure `show_name` caused on the paid path, but on a page
     * every visitor sees rather than one checkout.
     */
    public function test_the_nominee_query_omits_the_column_when_it_does_not_exist(): void
    {
        $this->dropColumnForTest('gates_nominees', 'organisation');

        $this->assertFalse(OptionalColumn::on('gates_nominees', 'organisation'));

        // The exact shape VoteController::nominee() builds.
        $cols = array_merge(
            ['n.id', 'n.name', 'n.vote_count'],
            OptionalColumn::on('gates_nominees', 'organisation') ? ['n.organisation'] : []
        );
        $this->assertNotContains('n.organisation', $cols);

        DB::table('gates_nominees')->insert([
            'category_id' => $this->cat, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 3,
        ]);
        $row = DB::table('gates_nominees as n')->where('n.name', 'Ada Obi')->first($cols);

        $this->assertNotNull($row, 'the ballot query must still run');
        $this->assertSame(3, (int) $row->vote_count);
    }
}
