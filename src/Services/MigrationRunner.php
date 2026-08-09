<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Applies ALL DB schema files (public + admin + community) and the dated PHP
 * migrations, idempotently, against the configured connection (MySQL/MariaDB
 * or SQLite). Tracked in the `gates_migrations` ledger so each dated migration
 * runs exactly once.
 *
 * Shared by TWO callers so the logic never drifts:
 *   • the `db:migrate` console command (operators with shell access), and
 *   • the token-gated web trigger `GET /__setup/migrate` (hosts WITHOUT SSH —
 *     e.g. shared cPanel — where the only way to migrate after a deploy is a URL).
 *
 * Re-running is safe: every CREATE TABLE uses IF NOT EXISTS, every dated
 * migration self-guards with hasColumn/hasTable, and the MySQL applier treats
 * "already exists / duplicate column" as benign so a partially-applied schema
 * converges instead of aborting.
 */
final class MigrationRunner
{
    /** MySQL/MariaDB driver error codes that mean "already done" — safe to skip. */
    private const BENIGN_MYSQL = [
        1050, // table already exists
        1060, // duplicate column name
        1061, // duplicate key name
        1091, // can't DROP / doesn't exist
        1826, // duplicate foreign key constraint name
        1022, // duplicate key
    ];

    /** How many setup steps to apply per web request (keeps each request small). */
    private const STEPS_PER_RUN = 4;

    /**
     * Ordered setup steps: the 3 schema files FIRST, then every dated migration in
     * chronological (filename) order. Each gets a unique ledger key so it runs once.
     * Schema keys are prefixed "schema:" so they never collide with migration names.
     *
     * @return list<array{type:string,key:string,file:string}>
     */
    private static function steps(string $root, string $driver): array
    {
        $schema = $driver === 'sqlite'
            ? ['sqlite-schema.sql', 'sqlite-admin-schema.sql', 'sqlite-community-schema.sql']
            : ['schema.sql', 'admin-schema.sql', 'community-schema.sql'];
        $steps = [];
        foreach ($schema as $name) {
            $steps[] = ['type' => 'schema', 'key' => 'schema:' . $name, 'file' => $root . '/database/' . $name];
        }
        $migs = glob($root . '/database/migrations/*.php') ?: [];
        sort($migs); // date-prefixed filenames sort chronologically
        foreach ($migs as $f) {
            $steps[] = ['type' => 'migration', 'key' => basename($f), 'file' => $f];
        }
        return $steps;
    }

    /**
     * Apply the next batch of pending steps. Designed to be called repeatedly from
     * the web trigger (it auto-reloads while pending > 0). Doing only a few steps
     * per request means no single request can hit max_execution_time / memory_limit
     * even on a slow shared host where set_time_limit() is disabled.
     *
     * @return array{ok:bool, lines:string[], ran:int, pending:int, error:?string}
     */
    public static function run(int $maxSteps = self::STEPS_PER_RUN): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        $deadline = microtime(true) + 12.0;
        $root   = dirname(__DIR__, 2);
        $driver = DB::connection()->getDriverName();
        $logFile = $root . '/var/logs/setup-migrate.log';
        if (!is_dir(dirname($logFile))) { @mkdir(dirname($logFile), 0775, true); }
        $jot = static function (string $s) use ($logFile) {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] {$s}\n", FILE_APPEND);
        };

        $lines = []; $done = 0; $warnings = 0; $remaining = 0;
        try {
            self::ensureLedger($driver);
            $applied = [];
            foreach (DB::table('gates_migrations')->pluck('migration') as $m) { $applied[(string) $m] = true; }

            $pending = array_values(array_filter(
                self::steps($root, $driver),
                static fn($s) => !isset($applied[$s['key']])
            ));
            $jot('=== run start (driver=' . $driver . ', pending=' . count($pending) . ') ===');

            foreach ($pending as $step) {
                if ($done >= $maxSteps || microtime(true) > $deadline) break;
                $jot('-> ' . $step['key']);
                if ($step['type'] === 'schema') {
                    if (is_file($step['file'])) {
                        $warnings += self::applySchemaFile($step['file'], $driver, $lines);
                        $lines[] = 'applied ' . basename($step['file']);
                    } else {
                        $lines[] = 'skip (missing) ' . $step['key'];
                    }
                } else {
                    // Each migration self-bootstraps its own Capsule and is idempotent.
                    (static function (string $__migrationFile) { include $__migrationFile; })($step['file']);
                    $lines[] = 'migrated ' . $step['key'];
                }
                DB::table('gates_migrations')->insert(['migration' => $step['key'], 'applied_at' => Carbon::now()->toDateTimeString()]);
                $jot('   done ' . $step['key']);
                $done++;
            }
            $remaining = max(0, count($pending) - $done);
        } catch (\Throwable $e) {
            $jot('FAILED: ' . $e->getMessage());
            return ['ok' => false, 'lines' => $lines, 'ran' => $done, 'pending' => -1, 'error' => $e->getMessage()];
        }

        if ($remaining > 0) {
            $lines[] = "Applied {$done} step(s); {$remaining} remaining — this page auto-continues.";
            $jot("batch done: {$done}, remaining {$remaining}");
        } else {
            $lines[] = $done > 0 ? "OK — applied {$done} step(s); setup complete." : 'OK — everything already applied.';
            $jot('complete: applied ' . $done . ' this batch');
        }
        return ['ok' => true, 'lines' => $lines, 'ran' => $done, 'pending' => $remaining, 'error' => null];
    }

    /**
     * Apply one schema .sql file, per-statement and tolerant of what is already done.
     * Returns the warning count.
     *
     * ── WHY SQLITE IS NO LONGER ONE BIG exec() ───────────────────────────────
     *
     * It used to be `$pdo->exec($whole_file)`, which is all-or-nothing: ONE statement
     * that cannot apply takes the entire migrate run down, and — because the schema
     * files are step 1 of 3 — takes it down BEFORE any dated migration gets to fix
     * the thing that was wrong.
     *
     * That is not hypothetical. The base schema files declare standalone
     * `CREATE INDEX ... ON table(col)` statements. On a database that already has the
     * table, `CREATE TABLE IF NOT EXISTS` is a no-op, so a column introduced later by
     * an ALTER TABLE migration does not exist yet when the index statement runs —
     * SQLite raises "no such column", and the upgrade dies with the fix sitting
     * unapplied two steps later. Fifteen such index/column pairs are in the schema
     * files today; each is fine on a fresh database and fatal on one with data, which
     * is the worst possible distribution of a bug.
     *
     * MySQL has been per-statement and tolerant since the equivalent failure there
     * ("duplicate index" on a re-deploy) stopped new columns being added and made
     * admin writes 500. This gives SQLite the same treatment. A statement that cannot
     * apply is now a WARN and the run continues — which is right for schema files
     * specifically, because they are DECLARATIVE and re-applied on every deploy: their
     * job is to converge the database, and a line that is already satisfied (or not
     * yet satisfiable) is not a reason to stop.
     *
     * Dated migrations are NOT treated this way — they still fail loudly, because each
     * one is a specific change that either happened or did not.
     */
    private static function applySchemaFile(string $f, string $driver, array &$lines): int
    {
        $pdo = DB::connection()->getPdo();
        $sql = (string) file_get_contents($f);
        $warnings = 0;
        foreach (self::splitSql($sql) as $stmt) {
            if (trim($stmt) === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                if (self::benign($e, $driver)) continue;
                $warnings++;
                $code = (int) ($e->errorInfo[1] ?? 0);
                $lines[] = '  WARN [' . basename($f) . "] SQL {$code}: " . substr(trim($e->getMessage()), 0, 150);
            }
        }
        return $warnings;
    }

    /**
     * "Already done", per driver.
     *
     * SQLite reports everything as SQLSTATE HY000 / code 1, so unlike MySQL there is no
     * numeric vocabulary to match on — the message is all there is. Matching on message
     * text is unpleasant and it is what the driver leaves available; the alternative is
     * treating every SQLite error as fatal, which is the behaviour being fixed.
     *
     * Deliberately NARROW: only the genuine "this is already true" cases. "no such
     * column" is left out even though it is the commonest one during an upgrade (a
     * schema-file index naming a column a later migration adds), because it is also
     * what a MISSPELLED column name looks like — and an index that silently never
     * exists is a slow query nobody can explain. It is no longer fatal; it prints as a
     * WARN and the run continues, which is the honest treatment of "I could not do
     * this and something may be wrong."
     */
    private static function benign(\PDOException $e, string $driver): bool
    {
        if ($driver !== 'sqlite') {
            return in_array((int) ($e->errorInfo[1] ?? 0), self::BENIGN_MYSQL, true);
        }
        return (bool) preg_match('/already exists|duplicate column name|duplicate/i', $e->getMessage());
    }

    /**
     * Read-only status for the diagnostics endpoint: applied count + pending step keys
     * (schema files + dated migrations).
     *
     * @return array{driver:string, applied:int, pending:string[]}
     */
    public static function status(): array
    {
        $root   = dirname(__DIR__, 2);
        $driver = DB::connection()->getDriverName();
        $applied = [];
        try {
            self::ensureLedger($driver);
            foreach (DB::table('gates_migrations')->pluck('migration') as $m) { $applied[(string) $m] = true; }
        } catch (\Throwable) {}
        $pending = [];
        foreach (self::steps($root, $driver) as $s) {
            if (!isset($applied[$s['key']])) $pending[] = $s['key'];
        }
        return ['driver' => $driver, 'applied' => count($applied), 'pending' => $pending];
    }

    /** Create the migration-tracking ledger if it doesn't exist (driver-aware). */
    private static function ensureLedger(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::statement('CREATE TABLE IF NOT EXISTS gates_migrations ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'migration TEXT NOT NULL UNIQUE, '
                . 'applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        } else {
            DB::statement('CREATE TABLE IF NOT EXISTS gates_migrations ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . 'migration VARCHAR(191) NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY(id), UNIQUE KEY uq_migration(migration)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }

    /**
     * Split a .sql file into individual statements. Strips line/block comments,
     * respects single/double-quoted strings, splits on terminating ';'.
     */
    private static function splitSql(string $sql): array
    {
        $out = []; $buf = ''; $len = strlen($sql);
        $inSingle = false; $inDouble = false; $inLine = false; $inBlock = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i]; $n = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($inLine)  { if ($c === "\n") $inLine = false; continue; }
            if ($inBlock) { if ($c === '*' && $n === '/') { $inBlock = false; $i++; } continue; }
            if (!$inSingle && !$inDouble) {
                if ($c === '-' && $n === '-') { $inLine = true; $i++; continue; }
                if ($c === '#') { $inLine = true; continue; }
                if ($c === '/' && $n === '*') { $inBlock = true; $i++; continue; }
                if ($c === ';') { $out[] = $buf; $buf = ''; continue; }
            }
            if ($c === "'" && !$inDouble) $inSingle = !$inSingle;
            elseif ($c === '"' && !$inSingle) $inDouble = !$inDouble;
            $buf .= $c;
        }
        if (trim($buf) !== '') $out[] = $buf;
        return $out;
    }
}
