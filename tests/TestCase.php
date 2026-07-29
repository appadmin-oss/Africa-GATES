<?php
declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase as BaseTestCase;
use AfricaGates\Support\Env;

/**
 * Base test case: boots a fresh in-memory SQLite database for every test and
 * loads the project's SQLite schema files. No external DB, no .env required.
 */
abstract class TestCase extends BaseTestCase
{
    protected Capsule $db;

    /** Schema files (under database/) loaded into every fresh test DB. */
    private const SCHEMA_FILES = [
        'sqlite-schema.sql',
        'sqlite-admin-schema.sql',
        'sqlite-community-schema.sql',
    ];

    /** The canonical MySQL schema, for the opt-in parity run. */
    private const MYSQL_SCHEMA_FILES = [
        'schema.sql',
        'admin-schema.sql',
        'community-schema.sql',
    ];

    /** Set once per process: MySQL schema is expensive to build per test. */
    private static bool $mysqlReady = false;

    /** Table count of a fully-built schema, so a dropping test is detectable. */
    private static ?int $expectedTables = null;

    /**
     * Whether this process is running the MySQL parity suite.
     *
     * Opt-in via TEST_DB_DRIVER=mysql. The default stays in-memory SQLite: it
     * needs no server, rebuilds in milliseconds, and is what makes the suite
     * runnable anywhere. But SQLite is NOT the production database, and testing
     * only against it hid a real defect — cycle boundaries are DATETIME while
     * ballot timestamps are TIMESTAMP, and MySQL shifts the latter by the session
     * timezone, so every deadline comparison was an hour out on a WAT server while
     * the whole suite stayed green. SQLite has no session timezone and stores text,
     * so it could not have caught it.
     *
     * This mode exists so the canonical schema — real ENUMs, strict mode,
     * ONLY_FULL_GROUP_BY, actual column types — gets exercised too.
     */
    protected static function usingMysql(): bool
    {
        return strtolower((string) Env::get('TEST_DB_DRIVER', 'sqlite')) === 'mysql';
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::usingMysql() ? $this->bootMysql() : $this->bootSqlite();

        // Static per-process caches must never leak between tests.
        \AfricaGates\Services\SpamService::resetThresholdCache();
    }

    protected function tearDown(): void
    {
        if (self::usingMysql()) {
            // Roll back rather than truncate. Truncating ~70 tables per test is
            // ~50,000 TRUNCATEs across the suite, and InnoDB rebuilds a tablespace
            // for each one — measured at far beyond any usable runtime. A single
            // ROLLBACK is effectively free.
            //
            // A test that issues DDL (several DROP a table to prove a feature
            // degrades without it) causes an implicit COMMIT in MySQL, so its rows
            // survive this rollback. That is handled where it belongs: setUp's
            // schemaIntact() check sees the missing table and rebuilds the whole
            // schema, which also clears the leaked rows.
            $this->rollback();
        }
        // Disconnect on BOTH drivers. On SQLite this discards the in-memory
        // database so the next test starts clean. On MySQL it is what stops the
        // suite exhausting max_connections (151 by default) — setUp builds a fresh
        // Capsule per test, so without this the run dies at "Too many connections"
        // partway through and every remaining test errors for a reason that has
        // nothing to do with what it was testing.
        try { Capsule::connection()->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    private function bootSqlite(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            // FK enforcement OFF for unit tests: seeds stay minimal (no need to
            // create whole parent chains). UNIQUE indexes (e.g. one-vote-per-
            // category) are still enforced, so integrity tests remain valid.
            'foreign_key_constraints' => false,
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->db = $capsule;

        $this->loadSchema();
    }

    private function bootMysql(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'    => 'mysql',
            // Read through Env, not $_ENV. variables_order is GPCS, so the
            // documented `DB_USER=… DB_PASS=… vendor/bin/phpunit` invocation
            // landed in getenv() and $_SERVER only — $_ENV never saw it, and the
            // harness silently connected as root with no password instead.
            'host'      => Env::get('DB_HOST', '127.0.0.1'),
            'port'      => Env::int('DB_PORT', 3306),
            'database'  => Env::get('DB_NAME', 'africa_gates_test'),
            'username'  => Env::get('DB_USER', 'root'),
            'password'  => Env::get('DB_PASS', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            // The pin under test. Without it every DATETIME-vs-TIMESTAMP
            // comparison in the suite would inherit the server's timezone.
            'timezone'  => \AfricaGates\Support\Clock::databaseTimezone(),
            'options'   => [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            ],
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->db = $capsule;

        // Tests that DROP a table to prove graceful degradation leave the schema
        // short, so verify rather than assume it is intact.
        if (!self::$mysqlReady || !$this->schemaIntact()) {
            $this->loadMysqlSchema();
            self::$mysqlReady = true;
        }

        // FK enforcement off, matching the SQLite harness: seeds stay minimal.
        Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');

        Capsule::connection()->beginTransaction();
    }

    /**
     * Undo the test's writes.
     *
     * A rollback covers the overwhelming majority in one cheap statement. It does
     * NOT cover a test that issued DDL: MySQL implicitly COMMITs on DDL, so
     * everything that test inserted beforehand is already permanent, and Laravel's
     * transactionLevel() counter cannot see that it happened. Left unhandled, the
     * next test hits "Duplicate entry '1' for key gates_award_cycles.PRIMARY" —
     * which is exactly how this suite failed 55 times on its first MySQL run, for
     * reasons unrelated to what any of those tests was asserting.
     *
     * So the rollback is followed by a CANARY: if a table the harness knows should
     * be empty still has rows, isolation broke, and the slow full cleanup runs.
     * That keeps the per-test cost at one extra COUNT while still being correct for
     * the four files that drop tables.
     */
    private function rollback(): void
    {
        try {
            if (Capsule::connection()->transactionLevel() > 0) {
                Capsule::connection()->rollBack();
            }
        } catch (\Throwable) { /* a DDL test already implicitly committed */ }

        try {
            // Several tables, not one. The first version watched only
            // gates_award_programmes, so SchemaIndexTest — which inserts VOTE rows
            // and then issues DDL, implicitly committing them — slipped straight
            // past it and broke nine VoteServiceTest assertions in a different file
            // via a uq_one_vote collision. One round trip either way.
            $leaked = (int) Capsule::connection()->selectOne(
                'SELECT (SELECT COUNT(*) FROM gates_award_programmes)
                      + (SELECT COUNT(*) FROM gates_votes)
                      + (SELECT COUNT(*) FROM gates_nominations)
                      + (SELECT COUNT(*) FROM gates_donations)
                      + (SELECT COUNT(*) FROM gates_nominees) AS n'
            )->n;
            if ($leaked > 0) $this->purgeAll();
        } catch (\Throwable) {
            // A canary table itself is gone — a dropping test. setUp's
            // schemaIntact() check will rebuild before the next test runs.
        }
    }

    /** Empty every table. Only reached when a DDL test broke transactional isolation. */
    private function purgeAll(): void
    {
        try {
            $pdo = Capsule::connection()->getPdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                if ($t === 'gates_migrations') continue; // the ledger must survive
                $pdo->exec('DELETE FROM `' . $t . '`');
            }
        } catch (\Throwable) { /* next setUp rebuilds */ }
    }

    /** Cheap sentinel: the tables a dropping test is most likely to have removed. */
    private function schemaIntact(): bool
    {
        try {
            $n = (int) Capsule::connection()->selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->n;
            return self::$expectedTables !== null && $n >= self::$expectedTables;
        } catch (\Throwable) {
            return false;
        }
    }

    private function loadMysqlSchema(): void
    {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        // Drop everything first: a partially-dropped schema must not be topped up
        // with CREATE TABLE IF NOT EXISTS and left inconsistent.
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
        }
        foreach (self::MYSQL_SCHEMA_FILES as $file) {
            $sql = file_get_contents(__DIR__ . '/../database/' . $file);
            if ($sql === false) throw new \RuntimeException("Missing schema file: {$file}");
            $pdo->exec($sql);
        }
        // Then the dated migrations, so the test schema matches a migrated
        // production database rather than the base files alone.
        \AfricaGates\Services\MigrationRunner::run(1000);
        self::$expectedTables = (int) Capsule::connection()->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->n;
    }


    private function loadSchema(): void
    {
        $pdo = Capsule::connection()->getPdo();
        foreach (self::SCHEMA_FILES as $file) {
            $path = __DIR__ . '/../database/' . $file;
            $sql  = file_get_contents($path);
            if ($sql === false) {
                throw new \RuntimeException("Missing schema file: {$path}");
            }
            try {
                // SQLite's PDO can execute multi-statement scripts in one exec().
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Fallback: run statements one at a time (handles drivers/scripts
                // that reject multi-statement exec).
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '') {
                        $pdo->exec($stmt);
                    }
                }
            }
        }

        // The schema files each issue `PRAGMA foreign_keys = ON`. Force it back
        // OFF *after* loading so unit seeds can stay minimal. Done outside any
        // transaction so it persists for the test body.
        $pdo->exec('PRAGMA foreign_keys = OFF');
    }
}
