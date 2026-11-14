<?php
declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Drop a column to simulate an unmigrated database — and PUT IT BACK afterwards.
 *
 * ── WHY THE RESTORE IS NOT OPTIONAL ──────────────────────────────────────────
 *
 * The tests that reproduce the paid-voting outage work by removing a column the code
 * expects. On the default harness that is harmless: every test gets a fresh in-memory
 * SQLite database, so the damage dies with the connection.
 *
 * Against a real MySQL (`TEST_DB_DRIVER=mysql`) the database PERSISTS across tests,
 * and {@see \Tests\TestCase::schemaIntact()} decides whether to rebuild it by counting
 * TABLES in information_schema. Dropping a column does not change that count, so the
 * harness sees an intact schema, skips the rebuild, and the missing column leaks into
 * every subsequent test in the run — which is exactly what happened: three tests
 * asserting behaviour "on a migrated database" failed because an earlier test in the
 * same class had quietly unmigrated it.
 *
 * That is a defect in the tests, not in the harness, and it is only visible on a
 * driver that keeps state. Hence this trait: the column's real definition is read out
 * of the live schema before the drop and replayed afterwards, so a destructive test
 * leaves the database exactly as it found it on every driver.
 */
trait DropsColumns
{
    /** @var list<array{table:string, col:string, ddl:string}> */
    private array $droppedColumns = [];

    /**
     * Remove $col from $table, remembering enough to rebuild it.
     *
     * Skips rather than passing vacuously when the driver cannot drop a column —
     * a green tick that proved nothing is how the original bug shipped.
     */
    protected function dropColumnForTest(string $table, string $col): void
    {
        $ddl = $this->columnDdl($table, $col);

        try {
            DB::connection()->getPdo()->exec("ALTER TABLE {$table} DROP COLUMN {$col}");
        } catch (\Throwable $e) {
            $this->markTestSkipped("cannot drop a column on this driver: {$e->getMessage()}");
        }

        if ($ddl !== null) {
            $this->droppedColumns[] = ['table' => $table, 'col' => $col, 'ddl' => $ddl];
        }
        \AfricaGates\Support\OptionalColumn::forget();

        $this->assertFalse(DB::schema()->hasColumn($table, $col),
            'the fixture must actually remove the column, or the test proves nothing');
    }

    /** Replay every drop. Call from tearDown BEFORE the parent. */
    protected function restoreDroppedColumns(): void
    {
        // Reversed, so a table that lost several columns regains them in order.
        foreach (array_reverse($this->droppedColumns) as $d) {
            try {
                DB::connection()->getPdo()->exec(
                    "ALTER TABLE {$d['table']} ADD COLUMN {$d['col']} {$d['ddl']}"
                );
            } catch (\Throwable $e) {
                // LOUD. A silent restore failure is indistinguishable from no restore at
                // all, and the symptom lands on a LATER test as an inexplicable
                // assertion failure — which is precisely how the MariaDB default quirk
                // below cost a debugging round. Not rethrown: an exception in teardown
                // masks the real result of the test that just ran.
                fwrite(STDERR, "\n[DropsColumns] could not restore {$d['table']}.{$d['col']} "
                    . "({$d['ddl']}): {$e->getMessage()}\n");
            }
        }
        $this->droppedColumns = [];
        \AfricaGates\Support\OptionalColumn::forget();
    }

    /**
     * The type/nullability/default of a live column, as an ALTER-able fragment.
     *
     * Read from the SCHEMA rather than hard-coded, so the restored column matches
     * whatever the migration actually created — a hand-written guess would silently
     * put back a different column than the one removed.
     */
    private function columnDdl(string $table, string $col): ?string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $r = DB::selectOne(
                'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                   FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $col]
            );
            if ($r === null) return null;
            $ddl = (string) $r->COLUMN_TYPE;
            $ddl .= ((string) $r->IS_NULLABLE === 'NO') ? ' NOT NULL' : ' NULL';
            $d = $r->COLUMN_DEFAULT;
            // MARIADB RETURNS THE FOUR-CHARACTER STRING 'NULL', NOT SQL NULL, for a
            // nullable column that has no default — MySQL 8 returns a real NULL. Taking
            // it at face value produced `ADD COLUMN refunded_at timestamp NULL DEFAULT
            // 'NULL'`, which MariaDB then rejects as an invalid default, so the restore
            // failed and the next test saw an unmigrated table.
            if ($d !== null && strtoupper((string) $d) !== 'NULL') {
                $d = (string) $d;
                // CURRENT_TIMESTAMP and friends are expressions, not literals.
                $ddl .= ' DEFAULT ' . (preg_match('/^[A-Z_]+\(\)?$/', $d) ? $d : DB::connection()->getPdo()->quote($d));
            }
            return $ddl;
        }

        // SQLite: PRAGMA table_info gives name/type/notnull/dflt_value.
        foreach (DB::select("PRAGMA table_info({$table})") as $c) {
            if ((string) $c->name !== $col) continue;
            $ddl = (string) $c->type;
            // A NOT NULL column can only be re-added with a default; every column this
            // is used on has one, and without it SQLite rejects the ALTER outright.
            if ((int) $c->notnull === 1 && $c->dflt_value !== null) $ddl .= ' NOT NULL';
            if ($c->dflt_value !== null) $ddl .= ' DEFAULT ' . $c->dflt_value;
            return $ddl;
        }
        return null;
    }
}
