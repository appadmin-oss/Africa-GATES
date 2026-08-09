<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MigrationRunner;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Applying a base schema file must CONVERGE a database, not stop at the first
 * statement it cannot run.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAILURE THIS ENCODES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `db:migrate` (and its no-SSH twin, /__setup/migrate) applies the three schema files
 * FIRST and the dated migrations afterwards. The schema files are declarative and
 * re-applied on every deploy; the migrations are the catch-up path for databases that
 * already have data.
 *
 * On SQLite the applier was a single `$pdo->exec($whole_file)`. That is all-or-nothing:
 * one statement that cannot apply aborts the run — and since the schema files are step
 * 1 of 3, it aborts BEFORE the migration that would have made the statement applicable.
 * The database is left half-upgraded with no way forward except deleting it.
 *
 * It is invisible in development. A FRESH database gets every column from the
 * CREATE TABLE, so every index statement succeeds. It only bites installs with data —
 * the ones that matter — and the trigger is completely ordinary: a schema file gains an
 * index on a column that an ALTER TABLE migration adds. Fifteen such pairs exist in
 * these files today.
 *
 * MySQL had already been given per-statement application, after the mirror-image
 * failure: a re-deploy re-applied schema.sql, a benign "duplicate index" aborted the
 * command, new columns never got added and admin writes 500'd. These tests hold both
 * drivers to the same contract.
 */
final class SchemaApplierTest extends TestCase
{
    /** Reach the private applier — it is the unit under test, not an implementation detail. */
    private function apply(string $sql, array &$lines): int
    {
        $file = tempnam(sys_get_temp_dir(), 'agschema') . '.sql';
        file_put_contents($file, $sql);
        try {
            $m = new \ReflectionMethod(MigrationRunner::class, 'applySchemaFile');
            $m->setAccessible(true);
            return (int) $m->invokeArgs(null, [$file, DB::connection()->getDriverName(), &$lines]);
        } finally {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        foreach (['ag_applier_a', 'ag_applier_b'] as $t) {
            try { DB::statement('DROP TABLE IF EXISTS ' . $t); } catch (\Throwable) {}
        }
        parent::tearDown();
    }

    /**
     * THE REGRESSION. A statement naming a column that does not exist yet must not
     * prevent the statements after it from running.
     *
     * This is the exact shape of the real thing: the table already exists (so
     * CREATE TABLE IF NOT EXISTS is a no-op and the newer column is absent), the file
     * declares an index over that column, and more schema follows.
     */
    public function test_one_unrunnable_statement_does_not_abandon_the_rest_of_the_file(): void
    {
        DB::statement('CREATE TABLE ag_applier_a (id INTEGER PRIMARY KEY, name TEXT)');

        $lines = [];
        $warnings = $this->apply("
            CREATE TABLE IF NOT EXISTS ag_applier_a (id INTEGER PRIMARY KEY, name TEXT, added_later TEXT);
            CREATE INDEX IF NOT EXISTS idx_applier_later ON ag_applier_a(added_later);
            CREATE TABLE IF NOT EXISTS ag_applier_b (id INTEGER PRIMARY KEY, note TEXT);
        ", $lines);

        $this->assertTrue(DB::schema()->hasTable('ag_applier_b'),
            'everything after the failing statement was abandoned — this is the bug that '
            . 'left an upgrade half-applied with the fix two steps away');
        $this->assertSame(1, $warnings, 'the statement that could not run must be reported, not hidden');
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('WARN', $lines[0]);
    }

    /**
     * Re-applying an unchanged file is the normal case — it happens on every deploy —
     * and must be silent. A run that cries wolf on "already correct" trains an operator
     * to ignore the output, which is where the real warnings go to die.
     */
    public function test_reapplying_the_same_schema_produces_no_warnings(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS ag_applier_a (id INTEGER PRIMARY KEY, name TEXT);
            CREATE INDEX IF NOT EXISTS idx_applier_name ON ag_applier_a(name);
        ";

        $lines = [];
        $this->assertSame(0, $this->apply($sql, $lines));
        $this->assertSame(0, $this->apply($sql, $lines), 'a second, identical apply must be a no-op');
        $this->assertSame([], $lines);
    }

    /**
     * And the statements themselves still have to be applied — a tolerant applier that
     * quietly did nothing would pass the test above and break every install.
     */
    public function test_the_statements_actually_run(): void
    {
        $lines = [];
        $this->apply('CREATE TABLE IF NOT EXISTS ag_applier_a (id INTEGER PRIMARY KEY, note TEXT);', $lines);

        $this->assertTrue(DB::schema()->hasTable('ag_applier_a'));
        DB::table('ag_applier_a')->insert(['note' => 'written']);
        $this->assertSame('written', DB::table('ag_applier_a')->value('note'));
    }

    /**
     * The one thing that must NOT be tolerated quietly: a genuinely malformed
     * statement. It is reported and the run continues, so an operator sees it — the
     * schema files are checked into the repository, and a syntax error in one is a
     * developer's mistake, not a state to converge from.
     */
    public function test_a_malformed_statement_is_reported(): void
    {
        $lines = [];
        $warnings = $this->apply("
            CREATE TABLE IF NOT EXISTS ag_applier_a (id INTEGER PRIMARY KEY);
            THIS IS NOT SQL AT ALL;
            CREATE TABLE IF NOT EXISTS ag_applier_b (id INTEGER PRIMARY KEY);
        ", $lines);

        $this->assertSame(1, $warnings);
        $this->assertTrue(DB::schema()->hasTable('ag_applier_b'), 'the file stopped at the bad line');
    }

    /**
     * Comments and semicolons inside string literals must not split a statement —
     * otherwise making the applier per-statement would have broken the schema files it
     * was meant to protect.
     */
    public function test_comments_and_quoted_semicolons_do_not_split_statements(): void
    {
        $lines = [];
        $warnings = $this->apply("
            -- a leading comment; with a semicolon in it
            CREATE TABLE IF NOT EXISTS ag_applier_a (
              id INTEGER PRIMARY KEY,
              label TEXT NOT NULL DEFAULT 'a;b'   -- trailing comment; here too
            );
            /* a block comment; with one as well */
            CREATE TABLE IF NOT EXISTS ag_applier_b (id INTEGER PRIMARY KEY);
        ", $lines);

        $this->assertSame(0, $warnings, implode("\n", $lines));
        $this->assertTrue(DB::schema()->hasTable('ag_applier_a'));
        $this->assertTrue(DB::schema()->hasTable('ag_applier_b'));
        DB::table('ag_applier_a')->insert(['id' => 1]);
        $this->assertSame('a;b', DB::table('ag_applier_a')->value('label'),
            'the default was cut at the semicolon inside the literal');
    }

    /**
     * The real files, re-applied against a database the harness has already built from
     * them. Zero warnings is the claim: every statement in the shipped schema is either
     * applicable or already satisfied.
     *
     * NOTE what this does and does not cover. The harness database is FRESH, so every
     * column a schema file names exists — which is exactly why the ordering hazard in
     * the class docblock is invisible here, and why it needed the per-statement applier
     * rather than a test to be made safe. What this does catch is a malformed statement,
     * a misspelled column, or an index over a table the file never creates: mistakes
     * that are in the repository rather than in somebody's database.
     */
    public function test_the_shipped_schema_files_re_apply_cleanly(): void
    {
        if (self::usingMysql()) {
            $this->markTestSkipped('the MySQL parity run rebuilds the schema per process');
        }

        $lines = [];
        $warnings = 0;
        foreach (['sqlite-schema.sql', 'sqlite-admin-schema.sql', 'sqlite-community-schema.sql'] as $name) {
            $warnings += $this->apply(
                (string) file_get_contents(dirname(__DIR__, 2) . '/database/' . $name), $lines
            );
        }

        $this->assertSame(0, $warnings,
            "re-applying the shipped schema is not clean:\n" . implode("\n", $lines));
    }
}
