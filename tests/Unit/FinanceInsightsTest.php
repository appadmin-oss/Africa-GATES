<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\FinanceInsights;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The definitions, not the plumbing.
 *
 * Every figure on the finance page is a claim somebody will repeat in a meeting,
 * and most of the ways these go wrong are definitional rather than arithmetic:
 * a mean quoted as if it were typical, a funnel whose stages overlap so it can
 * exceed 100%, "new supporters" that counts anybody who did not pay last month,
 * a percentage computed against a zero. Those are what these tests pin down.
 */
final class FinanceInsightsTest extends TestCase
{
    private function pay(string $email, int $naira, string $when, array $extra = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId(array_merge([
            'donor_name'   => ucfirst(explode('@', $email)[0]),
            'donor_email'  => $email,
            'amount_naira' => $naira,
            'tier'         => 'paid-vote',
            'bonus_votes'  => 1,
            'votes_used'   => 0,
            'payment_ref'  => 'AFG-' . bin2hex(random_bytes(4)),
            'status'       => 'confirmed',
            'created_at'   => $when,
        ], $extra));
    }

    private static function day(int $ago): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$ago} days 12:00:00"));
    }

    // ── comparison ───────────────────────────────────────────────────────────

    public function test_the_previous_window_is_the_same_length_and_immediately_before(): void
    {
        $r = FinanceInsights::comparison('2026-03-10', '2026-03-19');

        $this->assertSame(10, $r['window']['days']);
        $this->assertSame('2026-03-09', $r['window']['prev_to'], 'must end the day before this window opens');
        $this->assertSame('2026-02-28', $r['window']['prev_from'], 'must be exactly as long as this window');
    }

    public function test_it_compares_the_two_windows(): void
    {
        $this->pay('a@x.test', 1000, self::day(2));
        $this->pay('b@x.test', 3000, self::day(3));
        $this->pay('c@x.test', 1000, self::day(9));   // previous window

        $from = date('Y-m-d', strtotime('-6 days'));
        $to   = date('Y-m-d');
        $r    = FinanceInsights::comparison($from, $to);

        $this->assertSame(4000, $r['current']['gross']);
        $this->assertSame(2, $r['current']['count']);
        $this->assertSame(2, $r['current']['people']);
        $this->assertSame(1000, $r['previous']['gross']);
        $this->assertSame(300.0, $r['delta']['gross'], '1,000 → 4,000 is +300%');
    }

    /** Growth from nothing is undefined, not +100%. */
    public function test_a_delta_against_zero_is_null_rather_than_an_invented_percentage(): void
    {
        $this->pay('a@x.test', 5000, self::day(1));

        $r = FinanceInsights::comparison(date('Y-m-d', strtotime('-3 days')), date('Y-m-d'));

        $this->assertSame(0, $r['previous']['gross']);
        $this->assertNull($r['delta']['gross']);
    }

    public function test_a_refunded_payment_is_not_revenue(): void
    {
        $this->pay('a@x.test', 9000, self::day(1), ['refunded_at' => self::day(0)]);

        $r = FinanceInsights::comparison(date('Y-m-d', strtotime('-3 days')), date('Y-m-d'));

        $this->assertSame(0, $r['current']['gross']);
    }

    // ── supporters ───────────────────────────────────────────────────────────

    /** THE POINT OF REPORTING BOTH: one sponsor must not become "the average supporter". */
    public function test_the_median_survives_a_whale_that_wrecks_the_mean(): void
    {
        foreach (range(1, 9) as $i) $this->pay("small{$i}@x.test", 500, self::day(1));
        $this->pay('whale@x.test', 500000, self::day(1));

        $r = FinanceInsights::supporters(date('Y-m-d', strtotime('-3 days')), date('Y-m-d'));

        $this->assertSame(10, $r['people']);
        $this->assertSame(500, $r['median'], 'the middle person gave ₦500');
        $this->assertSame(50450, $r['average'], 'and the mean describes nobody in the set');
        $this->assertSame(500000, $r['largest']);
    }

    public function test_top_decile_share_measures_concentration(): void
    {
        foreach (range(1, 9) as $i) $this->pay("small{$i}@x.test", 1000, self::day(1));
        $this->pay('whale@x.test', 91000, self::day(1));   // 91% of ₦100,000

        $r = FinanceInsights::supporters(date('Y-m-d', strtotime('-3 days')), date('Y-m-d'));

        $this->assertSame(91, $r['top_decile_pct']);
    }

    /** With nine people the top decile must still be one person, not zero. */
    public function test_the_top_decile_is_never_empty(): void
    {
        foreach (range(1, 3) as $i) $this->pay("p{$i}@x.test", 1000, self::day(1));

        $r = FinanceInsights::supporters(date('Y-m-d', strtotime('-3 days')), date('Y-m-d'));

        $this->assertGreaterThan(0, $r['top_decile_pct']);
    }

    /**
     * "New" is measured against ALL history. Somebody who gave last year and
     * again today is returning; calling them new overstates acquisition forever.
     */
    public function test_new_is_measured_against_all_history_not_the_previous_window(): void
    {
        $this->pay('old@x.test', 1000, date('Y-m-d H:i:s', strtotime('-400 days')));
        $this->pay('old@x.test', 2000, self::day(1));
        $this->pay('fresh@x.test', 2000, self::day(1));

        $r = FinanceInsights::supporters(date('Y-m-d', strtotime('-3 days')), date('Y-m-d'));

        $this->assertSame(2, $r['people']);
        $this->assertSame(1, $r['new'], 'only fresh@ is new');
        $this->assertSame(1, $r['returning']);
    }

    public function test_a_person_is_an_email_regardless_of_case_or_spacing(): void
    {
        $this->pay('  Ada@X.test ', 1000, self::day(1));
        $this->pay('ada@x.test', 1000, self::day(1));

        $r = FinanceInsights::supporters(date('Y-m-d', strtotime('-3 days')), date('Y-m-d'));

        $this->assertSame(1, $r['people']);
        $this->assertSame(2000, $r['largest']);
        $this->assertSame(100, $r['repeat_rate']);
    }

    public function test_supporters_of_an_empty_window_is_zeroes_not_a_crash(): void
    {
        $r = FinanceInsights::supporters('2019-01-01', '2019-01-31');

        $this->assertSame(0, $r['people']);
        $this->assertSame(0, $r['median']);
        $this->assertSame([], $r['top']);
    }

    // ── leakage ──────────────────────────────────────────────────────────────

    /** The buckets must partition `started`, or the funnel can report >100%. */
    public function test_every_started_checkout_lands_in_exactly_one_bucket(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->pay('ok@x.test', 1000, $old);
        $this->pay('ref@x.test', 1000, $old, ['refunded_at' => $old]);
        $this->pay('bad@x.test', 1000, $old, ['status' => 'failed']);
        $this->pay('gone@x.test', 1000, $old, ['status' => 'pending']);            // abandoned
        $this->pay('live@x.test', 1000, date('Y-m-d H:i:s'), ['status' => 'pending']); // still open

        $r = FinanceInsights::leakage(date('Y-m-d', strtotime('-5 days')), date('Y-m-d'));

        $this->assertSame(5, $r['started']);
        $this->assertSame(
            $r['started'],
            $r['confirmed'] + $r['refunded'] + $r['failed'] + $r['expired'] + $r['abandoned'] + $r['still_open'],
            'the buckets must sum to started'
        );
        $this->assertSame(1, $r['confirmed']);
        $this->assertSame(1, $r['refunded']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(1, $r['abandoned']);
        $this->assertSame(1, $r['still_open']);
    }

    /** A checkout opened ninety seconds ago is not a conversion failure. */
    public function test_conversion_excludes_the_checkouts_still_in_flight(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->pay('ok@x.test', 1000, $old);
        $this->pay('gone@x.test', 1000, $old, ['status' => 'pending']);
        // Ten live checkouts must not drag the rate from 50% to 8%.
        for ($i = 0; $i < 10; $i++) {
            $this->pay("live{$i}@x.test", 1000, date('Y-m-d H:i:s'), ['status' => 'pending']);
        }

        $r = FinanceInsights::leakage(date('Y-m-d', strtotime('-5 days')), date('Y-m-d'));

        $this->assertSame(50, $r['conversion']);
    }

    /** Only abandonment can still be chased; a refusal and a closed window cannot. */
    public function test_only_abandoned_money_is_counted_as_recoverable(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->pay('bad@x.test', 4000, $old, ['status' => 'failed']);
        $this->pay('gone@x.test', 7000, $old, ['status' => 'pending']);

        $r = FinanceInsights::leakage(date('Y-m-d', strtotime('-5 days')), date('Y-m-d'));

        $this->assertSame(11000, $r['lost_naira']);
        $this->assertSame(7000, $r['recoverable_naira']);
    }

    public function test_a_window_with_no_checkouts_has_no_conversion_rate(): void
    {
        $r = FinanceInsights::leakage('2019-01-01', '2019-01-31');

        $this->assertSame(0, $r['started']);
        $this->assertNull($r['conversion'], 'nothing happened — that is not 0% conversion');
    }

    // ── byProgramme ──────────────────────────────────────────────────────────

    /**
     * THE BUG THIS PREVENTS, and it is a nasty shape.
     *
     * `byProgramme` joins four tables and wraps the lot in `catch (\Throwable)
     * { return empty; }` — the house style, so a mid-migration schema does not
     * 500 the finance page. The cost is that a genuinely WRONG query is
     * indistinguishable from an empty window: the first version selected
     * `p.name`, gates_award_programmes has `title`, and the panel reported "no
     * paid votes in this window" over a window with a hundred of them.
     *
     * So this asserts the happy path actually attributes money. Nothing else in
     * the suite can see past that catch.
     */
    public function test_paid_votes_are_attributed_to_their_programme_and_category(): void
    {
        foreach (['gates_award_programmes', 'gates_award_cycles', 'gates_award_categories', 'gates_nominees'] as $t) {
            if (!DB::schema()->hasTable($t)) $this->markTestSkipped("{$t} is not in this schema build.");
        }

        $progId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'cpi-' . bin2hex(random_bytes(3)), 'title' => 'Cultural Power Index',
        ]);
        $cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $progId, 'year' => 2026, 'status' => 'voting',
        ]);
        $catId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycleId, 'slug' => 'music', 'title' => 'Music',
        ]);
        $nomId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $catId, 'name' => 'Ada', 'status' => 'approved',
        ]);

        $this->pay('fan@x.test', 5000, self::day(1), ['intent_nominee_id' => $nomId, 'bonus_votes' => 10]);
        // And one that points at nothing, which must be reported rather than lost.
        $this->pay('ghost@x.test', 2000, self::day(1), ['intent_nominee_id' => null]);

        $r = FinanceInsights::byProgramme(date('Y-m-d', strtotime('-5 days')), date('Y-m-d'));

        $this->assertCount(1, $r['programmes'], 'the programme must resolve, not fall into the catch');
        $this->assertSame('Cultural Power Index', $r['programmes'][0]['label']);
        $this->assertSame(5000, $r['programmes'][0]['naira']);
        $this->assertSame(10, $r['programmes'][0]['votes']);

        $this->assertSame('Music', $r['categories'][0]['title']);
        $this->assertSame(5000, $r['categories'][0]['naira']);

        $this->assertSame(2000, $r['unattributed'], 'an order pointing nowhere is reported, not dropped');
        // The parts add up to the paid-vote total, which is the property that makes
        // the breakdown usable at all.
        $this->assertSame(7000, $r['programmes'][0]['naira'] + $r['unattributed']);
    }

    /** A donation is not a paid vote and must not appear in the programme split. */
    public function test_a_plain_donation_is_not_attributed_to_a_programme(): void
    {
        $this->pay('giver@x.test', 9000, self::day(1), ['tier' => 'donation', 'intent_nominee_id' => null]);

        $r = FinanceInsights::byProgramme(date('Y-m-d', strtotime('-5 days')), date('Y-m-d'));

        $this->assertSame([], $r['programmes']);
        $this->assertSame(0, $r['unattributed']);
    }

    // ── rhythm ───────────────────────────────────────────────────────────────

    public function test_revenue_lands_in_its_own_day_and_hour(): void
    {
        // A Sunday, 21:00 — pick a date whose weekday is fixed so the assertion
        // does not depend on when the suite runs.
        $when = date('Y-m-d H:i:s', strtotime('last sunday 21:15'));
        $this->pay('a@x.test', 6000, $when);

        $r = FinanceInsights::rhythm(30);

        $d = (int) date('w', strtotime($when));
        $this->assertSame(6000, $r['grid'][$d][21]);
        $this->assertSame(6000, $r['by_hour'][21]);
        $this->assertSame(6000, $r['by_day'][$d]);
        $this->assertSame(6000, $r['peak']);
    }

    public function test_the_rhythm_grid_is_always_a_full_seven_by_twenty_four(): void
    {
        $r = FinanceInsights::rhythm(30);

        $this->assertCount(7, $r['grid']);
        foreach ($r['grid'] as $row) $this->assertCount(24, $row, 'a sparse grid would render as a broken heatmap');
    }

    // ── cumulative ───────────────────────────────────────────────────────────

    public function test_the_running_total_only_ever_climbs(): void
    {
        $this->pay('a@x.test', 1000, self::day(3));
        $this->pay('b@x.test', 2000, self::day(1));

        $rows = FinanceInsights::cumulative(7);

        $this->assertCount(7, $rows);
        $prev = -1;
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual($prev, $row['total']);
            $prev = $row['total'];
        }
        $this->assertSame(3000, $rows[count($rows) - 1]['total']);
    }
}
