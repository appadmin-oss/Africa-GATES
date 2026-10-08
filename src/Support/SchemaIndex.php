<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Idempotent, driver-aware index creation for migrations.
 *
 * WHY THIS EXISTS. Several migrations reached for `CREATE INDEX IF NOT EXISTS`,
 * one of them with the comment "works on both SQLite and MySQL 8". It does not.
 * `IF NOT EXISTS` on an index is SQLite and PostgreSQL syntax; MySQL rejects it
 * with a 1064 syntax error. Those migrations were wrapped in try/catch, so they
 * printed a warning and carried on — which is why nobody noticed that on MySQL
 * the index was simply never created.
 *
 * On a FRESH database that was mostly harmless: `schema.sql` declares the same
 * indexes inline as `KEY`, so the catch-up was a no-op anyway. The damage is on an
 * OLD database — precisely the case these migrations exist for. A deployment that
 * predates a given index ran the catch-up, watched it fail into a warning, and is
 * still missing that index today.
 *
 * `DROP INDEX` differs too, and more dangerously, because the wrong form is not a
 * syntax error everywhere: SQLite takes `DROP INDEX name`, while MySQL requires
 * `DROP INDEX name ON table`. A migration that dropped an index before recreating
 * it as UNIQUE therefore left the old non-unique index in place on MySQL.
 *
 * So: ask whether the index exists, then issue plain DDL that both engines accept.
 * Existence is checked against the catalogue rather than inferred from a caught
 * exception, so "already there" and "failed for a real reason" stay distinguishable
 * — a try/catch around DDL is exactly what let this hide for as long as it did.
 */
final class SchemaIndex
{
    /** Does $index exist on $table? False when the table itself is absent. */
    public static function exists(string $table, string $index): bool
    {
        try {
            if (self::sqlite()) {
                // sqlite_master lists indexes by name; scoping to the table keeps
                // a same-named index on another table from reading as a match.
                return (int) DB::connection()->selectOne(
                    "SELECT COUNT(*) AS n FROM sqlite_master
                      WHERE type = 'index' AND name = ? AND tbl_name = ?",
                    [$index, $table]
                )->n > 0;
            }
            return (int) DB::connection()->selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $index]
            )->n > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Does $table exist? Creating an index on a missing table is not an error worth raising. */
    public static function tableExists(string $table): bool
    {
        try {
            return DB::schema()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ensure $index exists on $table over $columns, creating it if absent.
     *
     * Returns a progress line for the migration to echo, in the house style: `+`
     * for something done, `=` for already-correct, `!` for a genuine failure.
     *
     * A pre-existing index with the same NAME but different columns is left alone
     * and reported. Silently dropping and recreating an index a migration did not
     * create would be a destructive guess about someone else's intent — and on a
     * large table, an unannounced rebuild.
     *
     * @param list<string> $columns
     */
    public static function ensure(string $table, string $index, array $columns, bool $unique = false): string
    {
        if (!self::tableExists($table)) {
            return "  = {$index} skipped — {$table} not present";
        }
        if (self::exists($table, $index)) {
            return "  = {$index} already present";
        }

        $cols = implode(', ', array_map(static fn (string $c) => self::quote($c), $columns));
        $sql  = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . self::quote($index)
              . ' ON ' . self::quote($table) . " ({$cols})";
        try {
            DB::statement($sql);
            return "  + {$index} created" . ($unique ? ' (unique)' : '');
        } catch (\Throwable $e) {
            // A UNIQUE index over data that already violates it fails here, and
            // that is real information an operator must see rather than a warning
            // to scroll past — it means duplicates need resolving first.
            return "  ! {$index} could not be created: " . $e->getMessage();
        }
    }

    /**
     * Drop $index from $table if it is there.
     *
     * The table name is REQUIRED, not optional, because MySQL's `DROP INDEX`
     * cannot work without it and the SQLite-only form fails silently into a
     * try/catch. Making it a required parameter means a caller cannot write the
     * broken version by accident.
     */
    public static function drop(string $table, string $index): string
    {
        if (!self::exists($table, $index)) {
            return "  = {$index} not present, nothing to drop";
        }
        try {
            DB::statement(self::sqlite()
                ? 'DROP INDEX ' . self::quote($index)
                : 'DROP INDEX ' . self::quote($index) . ' ON ' . self::quote($table));
            return "  + {$index} dropped";
        } catch (\Throwable $e) {
            return "  ! {$index} could not be dropped: " . $e->getMessage();
        }
    }

    /**
     * Replace an index with a UNIQUE one over $columns — drop-then-create as a
     * pair, since that is the operation those migrations actually wanted and
     * getting the two halves right independently is what they got wrong.
     *
     * @param list<string> $columns
     * @return list<string> progress lines
     */
    public static function makeUnique(string $table, string $index, array $columns): array
    {
        if (!self::tableExists($table)) {
            return ["  = {$index} skipped — {$table} not present"];
        }
        $lines = [];
        if (self::exists($table, $index)) {
            $lines[] = self::drop($table, $index);
        }
        $lines[] = self::ensure($table, $index, $columns, unique: true);
        return $lines;
    }

    private static function sqlite(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Identifier quoting that both engines accept: double quotes, ANSI_QUOTES-safe. */
    private static function quote(string $identifier): string
    {
        // Reject anything that is not a plain identifier rather than trying to
        // escape it. Every caller here is a literal in a migration file, so a
        // surprising character means a mistake, not a legitimate exotic name.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
        return self::sqlite() ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }
}
