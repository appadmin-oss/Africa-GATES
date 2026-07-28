<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\PhaseAuditService;

/**
 * `database/audits/phase-audit.sql` must agree with {@see PhaseAuditService}.
 *
 * The SQL file exists so an operator can get the numbers from a read-only replica
 * without deploying the branch that carries `cycles:audit`. That convenience comes
 * with an obvious hazard: it is a SECOND implementation of the same questions, and
 * the whole restructure this branch performs was about removing exactly that kind
 * of duplication — a phase computed in several places that quietly disagreed.
 *
 * So the file is only defensible if a test proves the two agree. Without this,
 * someone would eventually change a window comparison in one and not the other,
 * and the refund bill an operator acts on would depend on which tool they happened
 * to run.
 *
 * The SQL deliberately does NOT reimplement the phase policy — that stays in
 * CyclePolicy alone — so the parity checked here covers the sections that are pure
 * data questions. The remedy tagging and the drift table are console-only, and
 * the SQL says so in its own comments.
 */
class PhaseAuditSqlParityTest extends TestCase
{
    private const SQL_FILE = __DIR__ . '/../../database/audits/phase-audit.sql';

    /**
     * Run the shipped SQL file and return its rows grouped by the `section`
     * label each SELECT tags itself with.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    private function runSqlFile(): array
    {
        $sql = file_get_contents(self::SQL_FILE);
        $this->assertIsString($sql, 'the audit SQL file must exist to be trusted');

        // Comments stripped before splitting so a ';' inside prose cannot break a
        // statement — the file is heavily commented on purpose.
        $sql   = preg_replace('/^\s*--.*$/m', '', $sql);
        $pdo   = DB::connection()->getPdo();
        $out   = [];
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $stmt) {
            $rows = $pdo->query($stmt)->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $out[(string) $row['section']][] = $row;
            }
        }
        return $out;
    }

    /** The fixture both implementations are asked about. */
    private function seed(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'gates', 'title' => 'GATES Awards']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => 2024, 'status' => 'nominations',
            'nominations_open' => '2024-01-01 00:00:00', 'nominations_close' => '2024-03-01 00:00:00',
            'voting_open' => '2024-04-01 00:00:00', 'voting_close' => '2024-06-01 00:00:00',
            'results_date' => '2024-06-10 00:00:00',
        ]);
        // Separate insert: a batch with differing key sets is rejected outright.
        // Undated on purpose — reported as uncheckable by both tools.
        DB::table('gates_award_cycles')->insert([
            'id' => 2, 'programme_id' => 1, 'year' => 2025, 'status' => 'nominations',
            'nominations_open' => '2025-01-01 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert([
            ['id' => 10, 'cycle_id' => 1, 'slug' => 'music', 'title' => 'Music'],
            ['id' => 11, 'cycle_id' => 1, 'slug' => 'film', 'title' => 'Film'],
        ]);
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 10, 'name' => 'Ada Obi', 'status' => 'approved'],
            ['id' => 2, 'category_id' => 10, 'name' => 'Bola Ade', 'status' => 'approved'],
            ['id' => 3, 'category_id' => 11, 'name' => 'Chidi Eze', 'status' => 'approved'],
        ]);
        DB::table('gates_votes')->insert([
            // In window — must appear in neither report.
            ['nominee_id' => 1, 'category_id' => 10, 'voter_email_hash' => 'h1', 'vote_type' => 'standard', 'weight' => 1, 'voted_at' => '2024-05-01 10:00:00'],
            // Late.
            ['nominee_id' => 1, 'category_id' => 10, 'voter_email_hash' => 'h2', 'vote_type' => 'standard', 'weight' => 1, 'voted_at' => '2024-06-20 10:00:00'],
            ['nominee_id' => 2, 'category_id' => 10, 'voter_email_hash' => 'h3', 'vote_type' => 'standard', 'weight' => 1, 'voted_at' => '2024-07-02 11:00:00'],
            // Early.
            ['nominee_id' => 3, 'category_id' => 11, 'voter_email_hash' => 'h4', 'vote_type' => 'standard', 'weight' => 1, 'voted_at' => '2024-03-02 11:00:00'],
        ]);
        // Separate: carries donation_id, so a different key set to the batch above.
        DB::table('gates_votes')->insert([
            'nominee_id' => 2, 'category_id' => 10, 'voter_email_hash' => 'paidvote:501:a',
            'vote_type' => 'paid', 'weight' => 90, 'donation_id' => 501, 'voted_at' => '2024-06-03 12:00:00',
        ]);
        DB::table('gates_donations')->insert([
            // Confirmed, never minted → refund owed.
            ['id' => 500, 'donor_name' => 'Buyer One', 'donor_email' => 'b1@x.io', 'amount_naira' => 7500,
             'tier' => 'paid-vote', 'bonus_votes' => 75, 'votes_used' => 0, 'intent_nominee_id' => 1,
             'payment_ref' => 'ref-500', 'status' => 'confirmed', 'created_at' => '2024-05-28 09:00:00'],
            // Confirmed and minted — but minted late (the vote row above).
            ['id' => 501, 'donor_name' => 'Buyer Two', 'donor_email' => 'b2@x.io', 'amount_naira' => 9000,
             'tier' => 'paid-vote', 'bonus_votes' => 90, 'votes_used' => 90, 'intent_nominee_id' => 2,
             'payment_ref' => 'ref-501', 'status' => 'confirmed', 'created_at' => '2024-05-29 09:00:00'],
        ]);
        DB::table('gates_nominations')->insert([
            ['cycle_id' => 1, 'nominee_name' => 'Late One', 'nominator_name' => 'N', 'nominator_email' => 'n@x.io', 'status' => 'pending',  'created_at' => '2024-03-15 08:00:00'],
            ['cycle_id' => 1, 'nominee_name' => 'Late Two', 'nominator_name' => 'N', 'nominator_email' => 'n@x.io', 'status' => 'approved', 'created_at' => '2024-04-02 08:00:00'],
            // Inside the window — must appear in neither report.
            ['cycle_id' => 1, 'nominee_name' => 'On Time',  'nominator_name' => 'N', 'nominator_email' => 'n@x.io', 'status' => 'approved', 'created_at' => '2024-02-01 08:00:00'],
        ]);
    }

    public function test_the_sql_file_runs_without_error(): void
    {
        // The first thing to know about a file an operator will paste into a
        // production client. Also catches the portability traps: `||` is string
        // concatenation in SQLite but boolean OR in MySQL, so a concatenated
        // column would return 0 on production rather than failing loudly.
        $this->seed();

        $sections = $this->runSqlFile();

        $this->assertArrayHasKey('clock', $sections);

        // Comment-stripped, like the verb scan below: the file's own comment
        // explaining this trap contains `||`, and asserting against the raw text
        // would fail on the documentation rather than on the SQL.
        $executable = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(self::SQL_FILE));
        $this->assertStringNotContainsString('||', $executable,
            'no `||` in executable SQL — string concat in SQLite, boolean OR in MySQL');
        $this->assertStringNotContainsString('`', $executable, 'no backticks — MySQL-only quoting');
    }

    public function test_the_sql_file_writes_nothing(): void
    {
        // It will be run against production, possibly by someone who did not read
        // the header. Asserted, not asserted-in-a-comment.
        $this->seed();

        $before = [];
        foreach (['gates_award_cycles', 'gates_votes', 'gates_donations', 'gates_nominees',
                  'gates_nominations'] as $t) {
            $before[$t] = DB::table($t)->count();
        }

        $this->runSqlFile();

        foreach ($before as $t => $n) {
            $this->assertSame($n, DB::table($t)->count(), "{$t} changed — the SQL is not read-only");
        }
        // Scanned with COMMENTS STRIPPED — the file explains itself at length, and
        // prose like "recreate that defect" contains "create " while being
        // entirely inert. Checking the executable content is both stricter about
        // what matters and free of that false positive.
        $body = strtoupper((string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(self::SQL_FILE)));
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'TRUNCATE ', 'CREATE ', 'REPLACE ', 'GRANT '] as $verb) {
            $this->assertStringNotContainsString($verb, $body, "the file contains a {$verb}statement");
        }
        $this->assertStringNotContainsString('SELECT', str_replace('SELECT', '', $body),
            'sanity: every statement should be a SELECT and nothing else remains');
    }

    public function test_late_and_early_ballot_counts_match_the_service(): void
    {
        $this->seed();

        $php = PhaseAuditService::run();
        $sql = $this->runSqlFile();

        $this->assertSame(
            array_sum(array_column($php['votes_after_close'], 'votes')),
            array_sum(array_map('intval', array_column($sql['votes_after_close'], 'votes'))),
            'late vote counts disagree'
        );
        $this->assertSame(
            array_sum(array_column($php['votes_after_close'], 'weight')),
            array_sum(array_map('intval', array_column($sql['votes_after_close'], 'weight'))),
            'late vote WEIGHT disagrees — the number that says how far the standings moved'
        );
        $this->assertSame(
            array_sum(array_column($php['votes_before_open'], 'votes')),
            array_sum(array_map('intval', array_column($sql['votes_before_open'], 'votes'))),
            'early vote counts disagree'
        );
        $this->assertSame(3, $php['totals']['late_votes'], 'and the fixture really does contain late votes');
    }

    public function test_late_nomination_counts_match_the_service_status_by_status(): void
    {
        $this->seed();

        $php = [];
        foreach (PhaseAuditService::run()['nominations_outside_window'] as $r) {
            $php[$r['status']] = $r['nominations'];
        }
        $sql = [];
        foreach ($this->runSqlFile()['nominations_late'] as $r) {
            $sql[(string) $r['status']] = (int) $r['nominations'];
        }

        ksort($php);
        ksort($sql);
        $this->assertSame($php, $sql);
        $this->assertSame(['approved' => 1, 'pending' => 1], $sql, 'the on-time nomination is in neither');
    }

    public function test_the_refund_bill_matches_the_service(): void
    {
        // The single most consequential number in either tool: someone acts on it
        // with money. If the two ever disagree, the amount refunded depends on
        // which tool the operator happened to run.
        $this->seed();

        $php   = PhaseAuditService::run()['paid_unminted'];
        $total = $this->runSqlFile()['paid_unminted_total'][0];

        $this->assertSame($php['orders'], (int) $total['orders']);
        $this->assertSame($php['naira'],  (int) $total['naira'], 'the refund bill disagrees');
        $this->assertSame($php['votes'],  (int) $total['votes_owed']);
        $this->assertSame(7500, $php['naira'], 'and the fixture really does owe someone money');
    }

    public function test_paid_votes_minted_late_match_the_service(): void
    {
        $this->seed();

        $php = PhaseAuditService::run()['paid_minted_late'];
        $sql = $this->runSqlFile()['paid_minted_late'];

        $this->assertSame($php['orders'], count($sql));
        $this->assertSame($php['weight'], array_sum(array_map('intval', array_column($sql, 'weight'))));
        $this->assertSame(
            array_column($php['rows'], 'vote_id'),
            array_map('intval', array_column($sql, 'vote_id')),
            'the same vote rows, not merely the same count'
        );
    }

    public function test_the_uncrowned_categories_match_the_service(): void
    {
        $this->seed();

        $php = array_column(PhaseAuditService::run()['results_backlog'], 'category_id');
        $sql = array_map('intval', array_column($this->runSqlFile()['uncrowned'], 'category_id'));

        sort($php);
        sort($sql);
        $this->assertSame($php, $sql);
        $this->assertSame([10, 11], $sql, 'both categories finished with eligible nominees and no winner');
    }

    public function test_the_undated_cycle_is_reported_by_both(): void
    {
        $this->seed();

        $php = array_column(PhaseAuditService::run()['undated'], 'cycle_id');
        $sql = array_map('intval', array_column($this->runSqlFile()['undated'], 'cycle_id'));

        $this->assertSame($php, $sql);
        $this->assertSame([2], $sql, 'the cycle with no closing date is uncheckable, not clean');
    }

    public function test_a_clean_database_produces_no_findings_in_either(): void
    {
        // The parity that matters most for confidence: both must agree on NOTHING
        // as readily as they agree on something. A pair of tools that only match
        // when there is damage would be worthless as an all-clear.
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'gates', 'title' => 'GATES']);
        // results_date must be set and past: with it NULL the computed phase stays
        // 'judging' while the column says 'results', which is a drift finding — a
        // real one, but it would make this test about something else.
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => 2024, 'status' => 'results',
            'nominations_open' => '2024-01-01 00:00:00', 'nominations_close' => '2024-03-01 00:00:00',
            'voting_open' => '2024-04-01 00:00:00', 'voting_close' => '2024-06-01 00:00:00',
            'results_date' => '2024-06-10 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 10, 'cycle_id' => 1, 'slug' => 'm', 'title' => 'Music']);
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'winner'],
            ['id' => 2, 'category_id' => 10, 'name' => 'Bola', 'status' => 'runner_up'],
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 10, 'voter_email_hash' => 'h1',
            'vote_type' => 'standard', 'weight' => 1, 'voted_at' => '2024-05-01 10:00:00',
        ]);

        $php = PhaseAuditService::run();
        $sql = $this->runSqlFile();

        $this->assertTrue(PhaseAuditService::isClean($php));
        foreach (['undated', 'votes_after_close', 'votes_before_open', 'nominations_late',
                  'paid_unminted', 'paid_minted_late', 'uncrowned'] as $section) {
            $this->assertArrayNotHasKey($section, $sql, "SQL reported {$section} on a clean database");
        }
        $this->assertSame(0, (int) $sql['paid_unminted_total'][0]['orders']);
    }
}
