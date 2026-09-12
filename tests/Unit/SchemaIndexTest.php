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
        // and invalid MySQL, so it may only appear where the statement itself is
        // reached on SQLite alone. Anywhere else it throws a 1064 on production.
        //
        // ── WHY THIS IS CHECKED PER STATEMENT AND NOT PER FILE ───────────────
        //
        // It used to pass a whole file as guarded if the text `$sqlite` appeared
        // ANYWHERE in it. Nearly every migration declares
        // `$sqlite = …getDriverName() === 'sqlite'` to pick its column types, so
        // that test excused the very files most likely to offend — and three did,
        // all of them shipped green:
        //
        //   · 2026_12_03_name_pronunciations   the UNIQUE key on `name_key`, the
        //                                      one guarantee that migration exists
        //                                      for, on the engine that enforces it
        //   · 2026_12_05_recurring_donations   five indexes including the UNIQUE on
        //                                      `manage_token`, the donor's stop
        //                                      button
        //   · 2026_12_06_snapshot_release_…    the lookup a published result page
        //                                      makes on every view
        //
        // And the failure is not one missing index. The runner aborts on a throw
        // WITHOUT recording the file, so the run stops there and every migration
        // dated after it never applies; on the next deploy the guard above the
        // statement ("table already present", "column already added") is now true,
        // so either the file is skipped entirely with its index never created, or
        // — where nothing above it can become true — it throws again on every
        // deploy for ever.
        //
        // So the question asked here is the narrow one: is THIS statement inside a
        // branch that only SQLite reaches?
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [] as $file) {
            foreach (self::unguardedIndexDdl((string) file_get_contents($file), basename($file)) as $line) {
                $offenders[] = basename($file) . ':' . $line;
            }
        }

        $this->assertSame([], $offenders,
            'these reach MySQL, where the syntax is a 1064 that aborts the whole '
            . 'migration run — use SchemaIndex::ensure()/drop() instead');
    }

    /**
     * Every line of $raw carrying index DDL that MySQL will both REJECT and REACH.
     *
     * Read with PHP's own tokeniser rather than by regex over the text, because the
     * shapes this has to tell apart are invisible to a text scan:
     *
     *   · `{$idx}` inside an interpolated index name is not a block;
     *   · a `;` inside a string literal does not end a statement;
     *   · and the prose in these files DESCRIBES the broken syntax, so a raw scan
     *     flags the very migrations that document the trap — the same false
     *     positive the read-only SQL audit's verb scan hit.
     *
     * A statement is guarded when SQLite is the only driver that can reach it:
     *
     *   · the file is SQLite-only — `sqlite` in its name, or a non-sqlite branch
     *     that RETURNS, after which nothing in the file runs on MySQL at all;
     *   · an enclosing block is the sqlite side of a driver branch, INCLUDING the
     *     `} else {` of `if (!$sqlite)`, which is where most of this directory's
     *     legitimate uses live;
     *   · the statement itself branches — `$sqlite ? 'CREATE INDEX IF NOT …' : …`.
     *
     * Polarity is read, never just the word: `if (!$sqlite) { CREATE INDEX IF NOT
     * EXISTS … }` mentions the driver and is the offence in its purest form, and a
     * scanner that matched on the mention alone would call it guarded.
     *
     * @return list<int> 1-based line numbers of the offending statements
     */
    private static function unguardedIndexDdl(string $raw, string $filename): array
    {
        // The codebase's own marker for a file MySQL never executes.
        if (str_contains(strtolower($filename), 'sqlite')) return [];

        $ddl = '~(CREATE\s+(UNIQUE\s+)?INDEX|DROP\s+INDEX)\s+IF\s+(NOT\s+)?EXISTS~i';

        $string = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
        $skip   = [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG, T_CLOSE_TAG];

        // Code text since the last statement or block boundary, with string contents
        // blanked. A ternary's condition lands here, which is what lets
        // `$sqlite ? '…IF NOT EXISTS…' : '…'` read as guarded.
        $since = '';
        /** @var list<array{sqlite:bool|null, returned:bool}> $stack */
        $stack = [];
        // Polarity of the block that closed most recently, so `} else {` can invert it.
        $closed = null;
        $sqliteOnlyFromHere = false;
        $out = [];

        foreach (token_get_all($raw) as $token) {
            if (is_array($token) && in_array($token[0], $skip, true)) continue;

            $text = is_array($token) ? (string) $token[1] : (string) $token;

            if (is_array($token) && in_array($token[0], $string, true)) {
                if (!$sqliteOnlyFromHere && preg_match($ddl, $text) === 1
                    && !self::reachedOnSqliteOnly($since, $stack)) {
                    $out[] = (int) $token[2];
                }
                // A string literal that IS a driver name is part of a condition —
                // `$driver === 'sqlite'` — so it stays. Anything longer is prose (these
                // files describe the trap at length) and is blanked, or the scanner
                // reads a docblock as a branch.
                $since .= preg_match('~^[\'"](sqlite|mysql|mariadb|pgsql)[\'"]$~i', trim($text)) === 1
                    ? $text : "''";
                continue;
            }

            if (is_array($token) && $token[0] === T_RETURN && $stack !== []) {
                $stack[array_key_last($stack)]['returned'] = true;
            }

            if ($text === '{' || (is_array($token) && $token[0] === T_CURLY_OPEN)) {
                $pol = self::branchPolarity($since);
                // `} else {` carries no condition of its own; it is the other side of
                // the branch that just closed.
                if ($pol === null && $closed !== null && preg_match('~\belse\b~i', $since) === 1) {
                    $pol = !$closed;
                }
                $stack[] = ['sqlite' => $pol, 'returned' => false];
                $since = '';
                continue;
            }

            if ($text === '}') {
                $frame  = array_pop($stack) ?? ['sqlite' => null, 'returned' => false];
                $closed = $frame['sqlite'];
                // A non-sqlite branch that RETURNS ends MySQL's involvement in the
                // file: what follows is the SQLite rebuild path, which is where
                // several of these migrations legitimately keep their DDL.
                if ($frame['returned'] && $frame['sqlite'] === false) $sqliteOnlyFromHere = true;
                $since = '';
                continue;
            }

            if ($text === ';') { $since = ''; $closed = null; continue; }

            $since .= $text;
        }

        return $out;
    }

    /**
     * Is this position reached on SQLite alone?
     *
     * @param list<array{sqlite:bool|null, returned:bool}> $stack
     */
    private static function reachedOnSqliteOnly(string $since, array $stack): bool
    {
        $guarded = false;
        foreach ($stack as $frame) {
            // An inner branch decides: a non-sqlite branch nested inside a sqlite one
            // is not reached on SQLite, and false is the safe direction to be wrong in.
            if ($frame['sqlite'] !== null) $guarded = $frame['sqlite'];
        }
        if ($guarded) return true;

        $pol = self::branchPolarity($since);
        if ($pol === null) return false;

        // Which side of `cond ? a : b` this is. The DDL normally sits on the true
        // side, but the condition is as often written `$sqlite` as `!$sqlite`.
        //
        // `::` is masked first. Every one of these branches calls `DB::statement()`,
        // so a naive search for the ternary's colon finds the scope resolution
        // operator instead and reports the sqlite branch as the mysql one.
        $code = str_replace('::', '__', $since);
        $q = strrpos($code, '?');
        $afterColon = $q !== false && strpos($code, ':', $q) !== false;

        return $afterColon ? !$pol : $pol;
    }

    /**
     * TRUE if $code tests for sqlite, FALSE if it tests for anything but, null if it
     * is not a driver test at all.
     */
    private static function branchPolarity(string $code): ?bool
    {
        if (stripos($code, 'sqlite') === false && stripos($code, 'driver') === false) return null;

        if (preg_match('~!\s*\$sqlite|!==?\s*[\'"]sqlite~i', $code) === 1) return false;
        if (preg_match('~\$sqlite|===?\s*[\'"]sqlite~i', $code) === 1) return true;

        return null;
    }

    /**
     * The scanner must actually catch the shape that shipped three times, or it is
     * the same test as before with more code in it.
     *
     * Both fixtures declare `$sqlite` the way a real migration does and then issue
     * the statement OUTSIDE any branch on it — which is precisely what the old
     * whole-file `str_contains($body, '$sqlite')` guard waved through.
     */
    public function test_the_scanner_catches_a_statement_that_only_mentions_the_driver(): void
    {
        $offender = <<<'PHP'
            <?php
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            $type = $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL';
            DB::statement("ALTER TABLE t ADD COLUMN c {$type}");
            DB::statement('CREATE INDEX IF NOT EXISTS idx_c ON t (c)');
            PHP;

        $this->assertSame([5], self::unguardedIndexDdl($offender, '2026_12_06_thing.php'),
            'the guard is back to excusing any file that merely mentions the driver');

        $guarded = <<<'PHP'
            <?php
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            DB::statement($sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_c ON t (c)'
                : 'ALTER TABLE t ADD INDEX idx_c (c)');
            if ($sqlite) {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_d ON t (d)');
            }
            PHP;

        $this->assertSame([], self::unguardedIndexDdl($guarded, '2026_12_06_thing.php'),
            'a statement MySQL never reaches is not an offence, and flagging it would '
            . 'push the next author to silence the scanner rather than use it');
    }
}
