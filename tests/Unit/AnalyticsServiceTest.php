<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AnalyticsService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The shapes that make an analytics page honest.
 *
 * Three failure modes are worth more than all the arithmetic here put together,
 * and each has a test named after it:
 *
 *   · a series that is not gap-filled turns three events in three weeks into a
 *     smooth rising line
 *   · organic, points-funded and paid votes summed into one number quietly
 *     destroys the integrity claim the whole platform rests on
 *   · a funnel counting EVENTS rather than sessions can show a later step with
 *     more traffic than the step before it
 *
 * Missing tables are tested too. This schema drifts, and "the page renders
 * without that section" has to be true rather than hoped for.
 */
final class AnalyticsServiceTest extends TestCase
{
    /**
     * $type is the column's real vocabulary: standard | bonus | paid.
     *
     * The category is auto-incremented per call because gates_votes carries a
     * UNIQUE(voter_email_hash, category_id) — one vote per person per category.
     * That is also how a repeat voter really behaves: they come back for another
     * category, not to vote twice in the same one, so fixtures that reused a
     * category would be testing a state the schema forbids.
     */
    private int $cat = 0;

    private function vote(string $emailHash, string $when, string $type = 'standard', array $extra = []): void
    {
        DB::table('gates_votes')->insert(array_merge([
            'nominee_id'       => 1,
            'category_id'      => ++$this->cat,
            'voter_email_hash' => $emailHash,
            'idempotency_key'  => bin2hex(random_bytes(8)),
            'vote_type'        => $type,
            'weight'           => 1,
            'voted_at'         => $when,
        ], $extra));
    }

    private static function day(int $ago): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$ago} days 12:00:00"));
    }

    // ── series shape ─────────────────────────────────────────────────────────

    /**
     * THE BUG THIS PREVENTS. A GROUP BY returns only days that had activity;
     * charted straight, sparse data becomes a smooth climb that never happened.
     */
    public function test_a_series_has_one_row_per_day_including_the_empty_ones(): void
    {
        $this->vote('h1', self::day(6));
        $this->vote('h2', self::day(0));

        $r = AnalyticsService::voting(7);

        $this->assertCount(7, $r['standard_series'], 'seven days must produce seven points');
        $values = array_column($r['standard_series'], 'value');
        $this->assertSame(5, count(array_filter($values, static fn ($v) => $v === 0)),
            'the five quiet days must be present as zeroes');
    }

    public function test_the_series_is_in_chronological_order(): void
    {
        $r = AnalyticsService::voting(10);
        $dates = array_column($r['standard_series'], 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
        $this->assertSame(date('Y-m-d'), end($dates), 'the last point is today');
    }

    // ── the three-way split ──────────────────────────────────────────────────

    /** The separation the integrity model depends on. */
    public function test_the_three_vote_kinds_are_never_merged(): void
    {
        $this->vote('h1', self::day(1));
        $this->vote('h2', self::day(1));
        $this->vote('h3', self::day(1), 'paid');
        $this->vote('h4', self::day(1), 'bonus');

        $r = AnalyticsService::voting(7);

        $this->assertSame(4, $r['total']);
        $this->assertSame(2, $r['standard']);
        $this->assertSame(1, $r['paid']);
        $this->assertSame(1, $r['bonus']);
        $this->assertSame(50, $r['organic_pct']);
        $this->assertSame(25, $r['paid_pct']);
        $this->assertSame(25, $r['bonus_pct']);
        $this->assertSame(1, array_sum(array_column($r['paid_series'], 'value')));
        $this->assertSame(1, array_sum(array_column($r['bonus_series'], 'value')));
        $this->assertSame(2, array_sum(array_column($r['standard_series'], 'value')));
    }

    /**
     * THE MISTAKE THIS FORBIDS. A points redemption is not organic support — the
     * integrity page promises the index excludes it, so the analytics page must
     * not quietly fold it into the organic column.
     */
    public function test_a_points_funded_vote_is_not_counted_as_organic(): void
    {
        $this->vote('h1', self::day(1), 'bonus');

        $r = AnalyticsService::voting(7);

        $this->assertSame(0, $r['standard']);
        $this->assertSame(0, $r['organic_pct']);
        $this->assertSame(1, $r['bonus']);
    }

    /**
     * On a deployment predating `vote_type`, the donation link is the only
     * evidence. `vote_type` is NOT NULL in the current schema, so the fallback
     * can only ever fire when the COLUMN itself is absent — which is what this
     * drops it to reproduce, rather than asserting against a null the schema
     * forbids.
     */
    public function test_without_a_vote_type_column_the_donation_link_decides(): void
    {
        // SQLITE ONLY, and not out of laziness. MySQL commits DDL implicitly, so
        // the DROP below survives the harness's rollback and every later test in
        // the run inserts into a gates_votes with no vote_type — which is exactly
        // what happened the first time this ran green on SQLite and took the
        // MySQL parity run down with it. The SQLite harness rebuilds its schema
        // from a template, so the same drop is contained there.
        if (self::usingMysql()) {
            $this->markTestSkipped('DDL is not transactional on MySQL; this would corrupt the rest of the run.');
        }

        $this->vote('h1', self::day(1), 'standard', ['donation_id' => 42]);
        $this->vote('h2', self::day(1), 'standard', ['donation_id' => null]);

        DB::schema()->table('gates_votes', static function ($t): void {
            $t->dropColumn('vote_type');
        });
        $this->assertFalse(DB::schema()->hasColumn('gates_votes', 'vote_type'));

        $r = AnalyticsService::voting(7);

        $this->assertSame(1, $r['paid'], 'a donation link is evidence of payment');
        $this->assertSame(1, $r['standard'], 'and no link at all reads as organic');
    }

    public function test_votes_per_voter_counts_people_not_rows(): void
    {
        // Three categories, one person — which is exactly what an engaged voter
        // looks like, and what a raw vote count would report as three people.
        $this->vote('same', self::day(1));
        $this->vote('same', self::day(1));
        $this->vote('same', self::day(2));
        $this->vote('other', self::day(1));

        $r = AnalyticsService::voting(7);

        $this->assertSame(4, $r['total']);
        $this->assertSame(2, $r['voters']);
        $this->assertSame(2.0, $r['per_voter']);
    }

    public function test_voting_on_an_empty_window_is_zeroes_not_a_crash(): void
    {
        $r = AnalyticsService::voting(30);

        $this->assertSame(0, $r['total']);
        $this->assertSame(0.0, $r['per_voter']);
        $this->assertCount(30, $r['standard_series']);
        $this->assertCount(24, $r['by_hour']);
    }

    // ── retention ────────────────────────────────────────────────────────────

    /**
     * A voter belongs to the week they FIRST appear, and shows up again in every
     * later week they vote. Getting the cohort assignment wrong is how a
     * retention table reports 100% forever.
     */
    public function test_a_returning_voter_shows_up_in_their_cohort_twice(): void
    {
        $thisMon = strtotime('monday this week');
        $wk0 = date('Y-m-d H:i:s', $thisMon - 3 * 7 * 86400 + 3600);
        $wk2 = date('Y-m-d H:i:s', $thisMon - 1 * 7 * 86400 + 3600);

        $this->vote('loyal', $wk0);
        $this->vote('loyal', $wk2);
        $this->vote('oneoff', $wk0);

        $r = AnalyticsService::retention(4);

        // Cohort index 0 is three weeks before this Monday.
        $c0 = null;
        foreach ($r['rows'] as $row) if ($row['size'] > 0) { $c0 = $row; break; }

        $this->assertNotNull($c0);
        $this->assertSame(2, $c0['size'], 'both voters first appeared in that week');
        $this->assertSame(100, $c0['retained'][0], 'week 0 is always the whole cohort');
        $this->assertSame(50, $c0['retained'][2], 'only one of the two came back in week 2');
    }

    public function test_a_cohort_with_nobody_in_it_reports_null_rather_than_zero_percent(): void
    {
        $r = AnalyticsService::retention(4);

        foreach ($r['rows'] as $row) {
            $this->assertSame(0, $row['size']);
            $this->assertNull($row['retained'][0], 'no voters is not 0% retention');
        }
    }

    public function test_the_retention_table_is_triangular(): void
    {
        $r = AnalyticsService::retention(6);

        $this->assertCount(6, $r['rows']);
        foreach ($r['rows'] as $i => $row) {
            $this->assertCount(6 - $i, $row['retained'],
                'a cohort cannot be followed into weeks that have not happened');
        }
    }

    // ── funnels ──────────────────────────────────────────────────────────────

    public function test_the_nomination_funnel_reports_each_stage_as_a_share_of_the_first(): void
    {
        foreach (range(1, 10) as $i) {
            DB::table('gates_nominations')->insert([
                'cycle_id' => 1, 'category_id' => 1,
                'nominee_name' => "N{$i}", 'nominee_email' => "n{$i}@x.test",
                'nominator_name' => 'Nom', 'nominator_email' => "s{$i}@x.test",
                'reason' => 'because', 'reference' => 'REF' . $i,
                'status' => $i <= 4 ? 'approved' : ($i <= 8 ? 'rejected' : 'pending'),
                'created_at' => self::day(3),
            ]);
        }

        $r = AnalyticsService::nominationFunnel();

        $this->assertSame(10, $r['stages'][0]['n']);
        $this->assertSame(100, $r['stages'][0]['pct']);
        $this->assertSame(4, $r['stages'][1]['n']);
        $this->assertSame(40, $r['stages'][1]['pct']);
        $this->assertSame(4, $r['rejected']);
        $this->assertSame(2, $r['pending']);
    }

    /**
     * THE BUG THIS PREVENTS. Counting events lets a later step out-total an
     * earlier one, which makes the funnel visibly nonsense.
     */
    public function test_the_ballot_funnel_counts_sessions_not_events(): void
    {
        if (!DB::schema()->hasTable('gates_funnel_events')) {
            $this->markTestSkipped('gates_funnel_events is not in this schema build.');
        }

        // One session that reloads the ballot six times, and one that goes deeper.
        for ($i = 0; $i < 6; $i++) {
            DB::table('gates_funnel_events')->insert([
                'session_id' => 'sess-a', 'step' => 'ballot_view', 'created_at' => self::day(1),
            ]);
        }
        DB::table('gates_funnel_events')->insert([
            'session_id' => 'sess-b', 'step' => 'ballot_view', 'created_at' => self::day(1),
        ]);
        DB::table('gates_funnel_events')->insert([
            'session_id' => 'sess-b', 'step' => 'vote_cast', 'created_at' => self::day(1),
        ]);

        $r = AnalyticsService::ballotFunnel(30);

        $this->assertSame(2, $r['sessions']);
        $steps = array_column($r['steps'], 'sessions', 'step');
        $this->assertSame(2, $steps['ballot_view'], 'six reloads by one session is one session');
        $this->assertSame(1, $steps['vote_cast']);
        $this->assertLessThanOrEqual($steps['ballot_view'], $steps['vote_cast'],
            'a later step can never exceed an earlier one');
    }

    // ── support ──────────────────────────────────────────────────────────────

    /** One nine-day outlier must not hide that the typical reply was fast. */
    public function test_first_reply_uses_the_median_so_one_outlier_cannot_hide_the_typical_case(): void
    {
        $mk = function (string $created, ?string $replied): void {
            $id = (int) DB::table('gates_support_tickets')->insertGetId([
                'reference' => 'AGS-' . bin2hex(random_bytes(3)),
                'email' => 'a@x.test', 'subject' => 'help', 'status' => 'open',
                'created_at' => $created,
            ]);
            if ($replied !== null) {
                DB::table('gates_support_messages')->insert([
                    'ticket_id' => $id, 'author_type' => 'admin', 'author_name' => 'Desk',
                    'body' => 'hi', 'created_at' => $replied,
                ]);
            }
        };

        $base = strtotime('-3 days 09:00:00');
        $mk(date('Y-m-d H:i:s', $base),      date('Y-m-d H:i:s', $base + 10 * 60));
        $mk(date('Y-m-d H:i:s', $base + 60), date('Y-m-d H:i:s', $base + 60 + 20 * 60));
        $mk(date('Y-m-d H:i:s', $base + 120), date('Y-m-d H:i:s', $base + 120 + 9 * 86400));

        $r = AnalyticsService::support(30);

        $this->assertSame(3, $r['opened']);
        $this->assertSame(20, $r['median_first_reply_mins'], 'the middle wait was 20 minutes');
    }

    public function test_a_ticket_nobody_answered_is_counted(): void
    {
        DB::table('gates_support_tickets')->insert([
            'reference' => 'AGS-QUIET', 'email' => 'a@x.test', 'subject' => 'hello',
            'status' => 'open', 'created_at' => self::day(2),
        ]);

        $r = AnalyticsService::support(30);

        $this->assertSame(1, $r['opened']);
        $this->assertSame(1, $r['unanswered']);
        $this->assertNull($r['median_first_reply_mins']);
    }

    public function test_backlog_delta_is_positive_when_the_queue_grows(): void
    {
        foreach (range(1, 3) as $i) {
            DB::table('gates_support_tickets')->insert([
                'reference' => 'AGS-G' . $i, 'email' => 'a@x.test', 'subject' => 's',
                'status' => 'open', 'created_at' => self::day(2),
            ]);
        }

        $r = AnalyticsService::support(30);

        $this->assertSame(3, $r['backlog_delta']);
    }

    // ── deliverability ───────────────────────────────────────────────────────

    public function test_mail_failures_are_reported_per_category(): void
    {
        if (!DB::schema()->hasTable('gates_mail_log')) {
            $this->markTestSkipped('gates_mail_log is not in this schema build.');
        }

        $add = static fn (string $cat, string $status) => DB::table('gates_mail_log')->insert([
            'to_masked' => 'a***@x.test', 'subject' => 's', 'category' => $cat,
            'status' => $status, 'created_at' => self::day(1),
        ]);
        $add('vote_code', 'sent');
        $add('vote_code', 'failed');
        $add('receipt', 'sent');

        $r = AnalyticsService::deliverability(30);

        $this->assertSame(2, $r['sent']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(33, $r['failure_pct']);
        $this->assertSame('vote_code', $r['by_category'][0]['category'], 'worst category first');
        $this->assertSame(50, $r['by_category'][0]['failure_pct']);
    }

    // ── resilience ───────────────────────────────────────────────────────────

    /**
     * This schema drifts — the feed, claims and attachments all shipped after the
     * core. Every section must degrade to an empty result, never a fatal.
     */
    public function test_every_section_survives_a_missing_table(): void
    {
        // Same reason as the vote_type test above: DROP TABLE is not transactional
        // on MySQL, so this would delete eight tables for the remainder of the run.
        // The behaviour under test is schema-independent, so proving it on one
        // driver proves it.
        if (self::usingMysql()) {
            $this->markTestSkipped('DDL is not transactional on MySQL; this would corrupt the rest of the run.');
        }

        foreach (['gates_threads', 'gates_comments', 'gates_cheers', 'gates_funnel_events',
                  'gates_mail_log', 'gates_votes', 'gates_users', 'gates_nominations'] as $t) {
            if (DB::schema()->hasTable($t)) DB::schema()->drop($t);
        }

        // None of these may throw.
        $this->assertSame(0, AnalyticsService::audience(30)['total']);
        $this->assertSame(0, AnalyticsService::voting(30)['total']);
        $this->assertSame([], AnalyticsService::retention(4)['rows']);
        $this->assertSame([], AnalyticsService::nominationFunnel()['stages']);
        $this->assertSame(0, AnalyticsService::ballotFunnel(30)['sessions']);
        $this->assertSame(0, AnalyticsService::community(30)['posts']);
        $this->assertSame(0, AnalyticsService::deliverability(30)['sent']);
        $this->assertSame([], AnalyticsService::geography()['vote_countries']);
    }
}
