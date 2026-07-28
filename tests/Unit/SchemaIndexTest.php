<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

/**
 * Idempotent, driver-aware index DDL for migrations.
 *
 * This exists because four migrations used `CREATE INDEX IF NOT EXISTS` — one of
 * them commented "works on both SQLite and MySQL 8" — which MySQL rejects with a
 * 1064. Each was wrapped in try/catch, so the failure printed a warning and the
 * migration reported success. On a fresh database that was almost harmless, since
 * schema.sql declares the same indexes inline. On an OLD database, which is the
 * only reason a catch-up migration exists at all, the index was never created and
 * is still missing.
 *
 * `DROP INDEX` was worse: SQLite takes `DROP INDEX name`, MySQL requires
 * `DROP INDEX name ON table`. One migration dropped an index and recreated it as
 * UNIQUE inside a single try/catch, so on MySQL the drop failed, the create was
 * skipped, and the index quietly stayed non-unique.
 *
 * The lesson these tests encode: existence is CHECKED against the catalogue, not
 * inferred from a caught exception, so "already there" stays distinguishable from
 * "failed for a real reason".
 */
class SchemaIndexTest extends TestCase
{
    /**
     * Every index this class creates, dropped after each test.
     *
     * REQUIRED, not tidiness. DDL is not transactional in MySQL, so the harness's
     * rollback cannot undo an index — and its leak canary watches ROWS, not schema.
     * Leaving `idx_test_uniq` behind (UNIQUE on idempotency_key alone) made
     * VoteServiceTest::test_two_voters_may_share_an_idempotency_key fail nine tests
     * later, in a file that had nothing to do with this one. A test that issues DDL
     * owns cleaning it up.
     */
    private const CREATED = [
        'idx_test_weight', 'idx_test_pair', 'idx_test_uniq', 'idx_test_bad',
        'idx_test_fresh', 'idx_same_name',
    ];

    protected function tearDown(): void
    {
        foreach (self::CREATED as $index) {
            try { SchemaIndex::drop('gates_votes', $index); } catch (\Throwable) {}
            try { SchemaIndex::drop('gates_nominations', $index); } catch (\Throwable) {}
        }
        parent::tearDown();
    }

    public function test_an_index_is_created_when_absent(): void
    {
        $line = SchemaIndex::ensure('gates_votes', 'idx_test_weight', ['weight']);

        $this->assertStringContainsString('+', $line, 'a creation must report as done');
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_test_weight'));
    }

    public function test_creating_twice_is_a_no_op_not_an_error(): void
    {
        // Migrations are re-run on every deploy. "Already correct" must be a
        // success, and must be DISTINGUISHABLE from a real failure in the output —
        // the old try/catch reported both as a warning.
        SchemaIndex::ensure('gates_votes', 'idx_test_weight', ['weight']);
        $second = SchemaIndex::ensure('gates_votes', 'idx_test_weight', ['weight']);

        $this->assertStringContainsString('=', $second);
        $this->assertStringNotContainsString('!', $second);
    }

    public function test_a_composite_index_is_created_over_all_its_columns(): void
    {
        $line = SchemaIndex::ensure('gates_votes', 'idx_test_pair', ['category_id', 'voted_at']);

        $this->assertStringContainsString('+', $line);
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_test_pair'));
    }

    public function test_a_unique_index_is_created_as_unique(): void
    {
        SchemaIndex::ensure('gates_votes', 'idx_test_uniq', ['idempotency_key'], unique: true);

        // Proven by behaviour rather than by reading the catalogue, so the
        // assertion holds identically on both drivers.
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'a',
            'idempotency_key' => 'dup', 'voted_at' => '2024-01-01 00:00:00',
        ]);
        $this->expectException(\Throwable::class);
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 2, 'voter_email_hash' => 'b',
            'idempotency_key' => 'dup', 'voted_at' => '2024-01-01 00:00:00',
        ]);
    }

    public function test_a_unique_index_over_violating_data_reports_a_real_failure(): void
    {
        // The one case that needs a human: duplicates must be resolved before the
        // constraint can exist. It must read as `!`, not be swallowed — swallowing
        // it is how a platform ends up believing it has a uniqueness guarantee it
        // does not have.
        DB::table('gates_votes')->insert([
            ['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'a', 'voter_name' => 'same', 'voted_at' => '2024-01-01 00:00:00'],
            ['nominee_id' => 1, 'category_id' => 2, 'voter_email_hash' => 'b', 'voter_name' => 'same', 'voted_at' => '2024-01-01 00:00:00'],
        ]);

        $line = SchemaIndex::ensure('gates_votes', 'idx_test_bad', ['voter_name'], unique: true);

        $this->assertStringContainsString('!', $line);
        $this->assertFalse(SchemaIndex::exists('gates_votes', 'idx_test_bad'));
    }

    public function test_dropping_requires_the_table_and_actually_drops(): void
    {
        // MySQL cannot drop an index without naming its table, and the SQLite-only
        // form failed silently into a try/catch. Making $table a required parameter
        // means the broken call cannot be written by accident.
        SchemaIndex::ensure('gates_votes', 'idx_test_weight', ['weight']);

        $line = SchemaIndex::drop('gates_votes', 'idx_test_weight');

        $this->assertStringContainsString('+', $line);
        $this->assertFalse(SchemaIndex::exists('gates_votes', 'idx_test_weight'));
    }

    public function test_dropping_something_absent_is_reported_not_thrown(): void
    {
        $line = SchemaIndex::drop('gates_votes', 'idx_never_existed');

        $this->assertStringContainsString('nothing to drop', $line);
        $this->assertStringNotContainsString('!', $line);
    }

    public function test_make_unique_replaces_a_non_unique_index(): void
    {
        // The operation the broken migration actually wanted, as one call — getting
        // the two halves right independently is precisely what it got wrong.
        SchemaIndex::ensure('gates_votes', 'idx_test_uniq', ['idempotency_key']);
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_test_uniq'));

        $lines = SchemaIndex::makeUnique('gates_votes', 'idx_test_uniq', ['idempotency_key']);

        $this->assertCount(2, $lines, 'the drop and the create are both reported');
        $this->assertStringContainsString('dropped', $lines[0]);
        $this->assertStringContainsString('unique', $lines[1]);
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_test_uniq'));
    }

    public function test_make_unique_works_when_there_is_nothing_to_replace(): void
    {
        $lines = SchemaIndex::makeUnique('gates_votes', 'idx_test_fresh', ['idempotency_key']);

        $this->assertCount(1, $lines, 'no pointless drop line when nothing was there');
        $this->assertStringContainsString('unique', $lines[1] ?? $lines[0]);
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_test_fresh'));
    }

    public function test_a_missing_table_is_reported_rather_than_raised(): void
    {
        // A migration that runs before the table exists, or on an install where the
        // feature was never enabled, must not fail the whole run.
        $line = SchemaIndex::ensure('gates_no_such_table', 'idx_x', ['a']);

        $this->assertStringContainsString('not present', $line);
        $this->assertStringNotContainsString('!', $line);
    }

    public function test_existence_is_scoped_to_the_table_asked_about(): void
    {
        // Index names are per-table in MySQL, so the same name can legitimately
        // exist on two tables. An unscoped check would report the wrong answer and
        // skip a creation that was needed.
        SchemaIndex::ensure('gates_votes', 'idx_same_name', ['weight']);

        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_same_name'));
        $this->assertFalse(SchemaIndex::exists('gates_nominations', 'idx_same_name'),
            'a same-named index on another table must not read as a match');
    }

    public function test_an_unsafe_identifier_is_rejected_rather_than_escaped(): void
    {
        // Every caller is a literal in a migration file, so a surprising character
        // means a mistake. Rejecting beats attempting to escape it.
        $this->expectException(\InvalidArgumentException::class);
        SchemaIndex::ensure('gates_votes', 'idx; DROP TABLE gates_votes', ['weight']);
    }

    public function test_no_migration_still_uses_the_mysql_invalid_syntax(): void
    {
        // The regression that matters. `IF NOT EXISTS` on an index is valid SQLite
        // and invalid MySQL, so it may only appear inside an explicit sqlite-only
        // branch. Anywhere else it silently does nothing on production.
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [] as $file) {
            $raw = (string) file_get_contents($file);

            // Comments stripped before scanning. The fixed migrations EXPLAIN the
            // trap in prose, so scanning raw text flags the very files that
            // document it — the same false positive the read-only SQL audit's verb
            // scan hit. Only executable code can be an offence.
            $body = (string) preg_replace(
                ['~/\*.*?\*/~s', '~//[^\n]*~', '~^\s*#[^\n]*~m'],
                '',
                $raw
            );

            // A file that branches on the driver is using the syntax legitimately:
            // it only reaches the statement on SQLite, where it is valid, and MySQL
            // gets the index from schema.sql's inline KEY.
            $guarded = str_contains($body, "getDriverName() === 'sqlite'")
                || str_contains($body, '$sqlite')
                || str_contains($body, "\$driver === 'sqlite'")
                || str_contains(basename($file), 'sqlite');

            if (!$guarded && preg_match('/(CREATE (UNIQUE )?INDEX|DROP INDEX) IF (NOT )?EXISTS/i', $body)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'these run on MySQL and silently do nothing — use SchemaIndex::ensure()/drop() instead');
    }
}
