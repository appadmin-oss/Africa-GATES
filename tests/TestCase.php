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
        // What we learned about the SCHEMA is the sharpest of these, because the schema is
        // rebuilt per test and the memo was not: one test that touched a table before a
        // migration added a column taught OptionalColumn that the column does not exist, and
        // every later test in the process then silently DROPPED that column from its writes.
        // The failures land far from the cause and look like the feature is broken.
        \AfricaGates\Support\OptionalColumn::forget();
        // A transport injected by one test must not send another test's mail — and
        // passing null also clears the "already booted" flag, so the next test either
        // injects its own fake or gets a freshly-built one.
        \AfricaGates\Services\CheckoutMailer::using(null);
        // A provider tripped unreachable by one test must not be skipped in the next.
        // Found the hard way: AiFailureReportingTest scripts an `HTTP 0` from Groq and
        // Gemini, which legitimately opens their breakers, and AiModelDelegationTest
        // then saw a route order it had never set up. The breaker keeps an in-process
        // memo as well as a cache row, so clearing the table alone is not enough.
        \AfricaGates\Support\ProviderBreaker::clearAll();
    }

    /**
     * Publish a shortlist, because the judging panel now scores only shortlisted nominees.
     *
     * ── WHY THIS IS A SHARED HELPER AND NOT FIVE COPIES ─────────────────────
     *
     * Five test files build a ballot, and every one of them needs this now. The rule the
     * judging portal enforces — the panel judges the SHORTLIST, not the whole field — is one
     * fact, and a fixture repeated five times is five chances for one of them to drift into
     * testing a shape the portal no longer produces.
     *
     * Deliberately minimal: `status = 'published'` and a row per nominee, which is exactly
     * what {@see \AfricaGates\Services\ShortlistService::shortlistedIn()} reads. A test that
     * needs the frozen tallies or the rule text should use ShortlistService::publish().
     *
     * @param list<int> $nomineeIds in the order they were shortlisted
     */
    protected function publishShortlist(int $cycleId, int $categoryId, array $nomineeIds): int
    {
        $id = (int) Capsule::table('gates_shortlists')->insertGetId([
            'cycle_id'     => $cycleId,
            'category_id'  => $categoryId,
            'status'       => 'published',
            'entry_count'  => count($nomineeIds),
            'considered'   => count($nomineeIds),
            'published_at' => date('Y-m-d H:i:s'),
        ]);

        foreach (array_values($nomineeIds) as $i => $nid) {
            Capsule::table('gates_shortlist_entries')->insert([
                'shortlist_id' => $id,
                'nominee_id'   => (int) $nid,
                'rank_no'      => $i + 1,
            ]);
        }

        return $id;
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

        // ── AND information_schema MUST NOT ANSWER FROM CACHE ────────────────
        //
        // MySQL 8 caches table statistics — AUTO_INCREMENT among them — for
        // `information_schema_stats_expiry` seconds, which defaults to a DAY. The rewind
        // below reads that column to decide whether the counter is near its ceiling, so
        // it was reading a value from the start of the run: it saw single digits, decided
        // there was nothing to relieve, and skipped every time while the real counter
        // climbed to 255 and stuck there.
        //
        // The symptom was a "Duplicate entry '255' for key PRIMARY" in whichever test
        // happened to insert a programme next — a table it had no interest in, from a
        // counter it never touched.
        try { Capsule::connection()->statement('SET SESSION information_schema_stats_expiry = 0'); }
        catch (\Throwable) { /* MariaDB has no such variable and does not cache this */ }

        // Must run BEFORE beginTransaction: it is DDL, which implicitly commits.
        $this->relieveAutoIncrementPressure();

        Capsule::connection()->beginTransaction();

        // Planted INSIDE the transaction, so a clean rollback takes it with it and a
        // commit — however it happened — leaves it behind. That is the whole mechanism.
        $this->sentinel = bin2hex(random_bytes(8));
        try {
            Capsule::table('gates_test_sentinel')->insert(['token' => $this->sentinel]);
        } catch (\Throwable) {
            // No sentinel table yet on a database built by an older harness. rollback()
            // falls back to purging unconditionally rather than trusting a missing
            // signal — slow, and correct, which is the right way round.
            $this->sentinel = '';
        }
    }

    /**
     * Tables whose AUTO_INCREMENT can be EXHAUSTED by a long suite run.
     *
     * `gates_award_programmes.id` is `TINYINT UNSIGNED` — 255 values — and almost every
     * test in the suite seeds a programme. A ROLLBACK removes the rows but never rewinds
     * the counter, so somewhere past the 255th seeded programme every remaining test
     * dies with "Out of range value for column 'id'", for a reason that has nothing to do
     * with what it was asserting. On the first full MySQL run that was 14 errors spread
     * across four unrelated files.
     *
     * The column is NOT the bug: 255 programmes is far beyond anything this platform will
     * hold, `gates_award_cycles.programme_id` is the same width with a foreign key between
     * them, and widening it would be a two-table migration plus an FK rebuild to buy
     * nothing. The SUITE is what behaves unlike production here — a real database does not
     * create a quarter of a thousand programmes in twenty seconds.
     *
     * @var list<string>
     */
    private const NARROW_AUTO_INCREMENT = ['gates_award_programmes'];

    /**
     * Rewind narrow counters AT THE CEILING, not on a schedule.
     *
     * ── WHY THIS IS MEASURED RATHER THAN COUNTED ─────────────────────────────
     *
     * This used to rewind every 64th boot, sized against "a handful of programmes per
     * test". That is a guess about a number nobody controls: it is whatever the suite
     * happens to seed, and it moves every time a test is added. Five new tests in
     * PulseReactionsTest — two programmes each — were enough to burn all 255 values
     * inside one 64-boot window, and the failure surfaced in SupportGroundingTest, which
     * seeds no more programmes than it ever did. A schedule cannot be right about a
     * budget it does not measure.
     *
     * So the trigger is now the thing that actually matters: what the counter reads.
     * `information_schema` answers in one cheap SELECT with no implicit commit, and the
     * DDL runs only when the number really is approaching the cap. Adding a hundred
     * programme-seeding tests tomorrow cannot silently re-break this.
     *
     * Half the ceiling rather than, say, 240: InnoDB's cached AUTO_INCREMENT can lag
     * reality, and there is nothing to buy by cutting it fine.
     *
     * MySQL clamps the new value up to max(id)+1 when rows exist, so this is a no-op
     * rather than a hazard if a dropping test left something behind.
     */
    private const AUTO_INCREMENT_REWIND_AT = 200;

    private function relieveAutoIncrementPressure(): void
    {
        foreach (self::NARROW_AUTO_INCREMENT as $table) {
            try {
                $next = (int) (Capsule::connection()->selectOne(
                    'SELECT AUTO_INCREMENT n FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])?->n ?? 0);

                if ($next < self::AUTO_INCREMENT_REWIND_AT) continue;

                // ── EMPTY IT FIRST, OR THE RESET IS A NO-OP ──────────────────
                //
                // MySQL clamps `AUTO_INCREMENT = 1` UP to max(id)+1 whenever rows exist.
                // The note above this method said so and then reset anyway — so once a
                // leaked row was sitting at the ceiling the counter stuck at 255, the
                // rewind quietly did nothing every time it ran, and every later insert
                // died with "Duplicate entry '255' for key PRIMARY" in a test that had
                // never heard of programmes.
                //
                // Deleting is not a liberty taken here: this runs in setUp, before the
                // test has done anything, and an empty programmes table is exactly the
                // state a test is entitled to start from. FOREIGN_KEY_CHECKS is already
                // off by the time this is called.
                Capsule::connection()->statement('DELETE FROM `' . $table . '`');
                Capsule::connection()->statement('ALTER TABLE `' . $table . '` AUTO_INCREMENT = 1');
            } catch (\Throwable) { /* a dropping test removed it; setUp rebuilds */ }
        }
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

        // ── DID THE TRANSACTION SURVIVE? ─────────────────────────────────────
        //
        // This used to COUNT SIX NAMED TABLES and purge if any of them had rows —
        // gates_award_programmes, then votes, then nominations, donations, settings,
        // nominees. Every one of those names was added after a full MySQL run traced a
        // failure in one file back to a leak in another, and the list only ever grew.
        //
        // That is the shape of the bug rather than a fix for it. There are seventy-odd
        // tables; the canary watched six. A test that wrote to any of the other seventy
        // and then issued DDL leaked silently, and the damage surfaced as a duplicate-key
        // error in an unrelated file — which is how a single leak became a hundred and
        // fifty-seven errors on the last full parity run, none of them about what the
        // failing test was asserting.
        //
        // So this no longer asks WHICH table leaked. It asks whether a commit happened at
        // all, which is the actual question and has an exact answer: a marker planted
        // inside the transaction is gone if the rollback worked and present if anything
        // committed — DDL, an explicit COMMIT, a TRUNCATE in a loop. One SELECT, no list
        // to keep up to date, and nothing it can fail to notice.
        try {
            $broke = $this->sentinel === '' || (int) Capsule::connection()->selectOne(
                'SELECT COUNT(*) AS n FROM gates_test_sentinel WHERE token = ?',
                [$this->sentinel]
            )->n > 0;

            if ($broke) $this->purgeAll();
        } catch (\Throwable) {
            // The sentinel table itself is gone — a dropping test. setUp's schemaIntact()
            // rebuilds before the next test runs, which also clears anything that leaked.
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

            // Back to what a MIGRATED database holds, not to empty. See the note where
            // this snapshot is taken: migrations seed, and deleting their rows leaves a
            // database no deployment has ever looked like.
            foreach (self::$mysqlSeed ?? [] as $table => $rows) {
                if ($table === 'gates_migrations') continue;
                foreach (array_chunk($rows, 200) as $batch) {
                    Capsule::table($table)->insert($batch);
                }
            }
        } catch (\Throwable $e) {
            error_log('[test-harness] purge/restore failed: ' . $e->getMessage());
        }
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
        // Then the dated migrations, so the test schema matches a migrated production
        // database rather than the base files alone. Driven to completion and output
        // captured, exactly as the SQLite path does it — MigrationRunner batches and stops
        // at a wall-clock deadline, so one call is not a guarantee that it finished, and
        // migrations echo their progress into a suite that fails on risky output.
        ob_start();
        try {
            $this->runPendingMigrations();
        } finally {
            ob_end_clean();
        }
        // ── THE ISOLATION SENTINEL ───────────────────────────────────────────
        //
        // Not part of the application schema and deliberately so: it exists to answer one
        // question this harness cannot otherwise answer — did a transaction survive to
        // the rollback, or did something commit it out from under us? See rollback().
        $pdo->exec('CREATE TABLE IF NOT EXISTS gates_test_sentinel (
            token VARCHAR(32) NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        self::$expectedTables = (int) Capsule::connection()->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->n;

        // ── WHAT A FRESHLY-MIGRATED DATABASE CONTAINS ────────────────────────
        //
        // Not empty. Migrations SEED: the shipped judging rubric, the stand catalogue, the
        // migration ledger itself. purgeAll() used to delete everything but the ledger, so
        // the first isolation purge destroyed the rubric and nothing ever put it back —
        // the migration is recorded as applied, so it never runs again — and thirteen
        // judging tests then failed with "the shipped rubric should be installed" in a
        // file that had never touched a rubric.
        //
        // It only became visible when the sentinel started catching leaks properly and
        // purges went from rare to routine. The SQLite harness already keeps rows in its
        // template for exactly this reason; this is the same idea on the other driver.
        self::$mysqlSeed = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $rows = $pdo->query('SELECT * FROM `' . $t . '`')->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows !== []) self::$mysqlSeed[$t] = $rows;
        }
    }

    /**
     * The rows a migrated database starts with, table => rows. Restored after a purge.
     *
     * @var array<string, list<array<string,mixed>>>|null
     */
    private static ?array $mysqlSeed = null;

    /** The marker written inside this test's transaction. '' when not on MySQL. */
    private string $sentinel = '';


    /**
     * A fully-migrated SQLite schema as replayable statements, built once per process.
     *
     * @var list<string>|null
     */
    private static ?array $sqliteTemplate = null;

    /**
     * Build the test schema — base files AND every dated migration.
     *
     * ── THE DEFECT THIS CLOSES ───────────────────────────────────────────────
     *
     * This method used to load the three `sqlite-*.sql` files and stop, while
     * {@see loadMysqlSchema()} loaded its files and then ran `MigrationRunner`. So the
     * two harnesses described different databases, and the DEFAULT one — the one
     * everybody runs, the one CI runs — described a database that no longer existed:
     * it was missing every table and column added by a dated migration.
     *
     * The consequence was not a subtle skew. Three consecutive commits shipped tests
     * that could only pass under the opt-in MySQL run, and the default suite went red
     * with 31 errors reading `no such table: gates_ticket_links` /
     * `gates_nominee_claims` — failures that say nothing about the code under test and
     * train everyone to read a red suite as normal. The MySQL note above this class
     * argues that SQLite is not production and must not be the only thing tested; the
     * inverse was quietly true as well, and cost more.
     *
     * ── WHY THE RESULT IS CACHED AND REPLAYED ────────────────────────────────
     *
     * The SQLite harness builds a fresh `:memory:` database for EVERY test, which is
     * what makes it fast and perfectly isolated. Running ~70 migration files inside all
     * ~1,850 of those boots would mean well over a hundred thousand file includes.
     *
     * So the expensive build happens once, and what it produced is snapshotted from
     * `sqlite_master` (plus any rows a migration left behind) into plain statements
     * that later boots replay. Identical schema, one build.
     */
    private function loadSchema(): void
    {
        if (self::$sqliteTemplate !== null) {
            $this->replaySqliteTemplate(self::$sqliteTemplate);
            return;
        }

        $this->applySchemaFiles();

        // Then the dated migrations, so the test schema matches a MIGRATED production
        // database rather than the base files alone — the same reason loadMysqlSchema()
        // does it. Output is captured because migrations echo their progress and
        // phpunit.xml sets failOnRisky: a test that prints is a risky test.
        ob_start();
        try {
            $this->runPendingMigrations();
        } finally {
            ob_end_clean();
        }

        self::$sqliteTemplate = $this->snapshotSqlite();
        Capsule::connection()->getPdo()->exec('PRAGMA foreign_keys = OFF');
    }

    /** The three base .sql files, into the current connection. */
    private function applySchemaFiles(): void
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

    /**
     * Apply every pending migration, and be LOUD if any is left over.
     *
     * MigrationRunner batches deliberately — it caps steps per call and stops at an
     * internal wall-clock deadline, because on a shared host each call is one web
     * request that must not time out. Calling it once and assuming it finished is
     * exactly how a harness ends up with a half-built schema and a suite full of
     * errors about missing tables, which is the failure this whole method exists to
     * end. So it is driven to completion and an exception is thrown if it stalls: a
     * schema that is quietly incomplete is worse than a harness that refuses to start.
     */
    private function runPendingMigrations(): void
    {
        $lastPending = PHP_INT_MAX;

        for ($pass = 0; $pass < 40; $pass++) {
            $result  = \AfricaGates\Services\MigrationRunner::run(1000);
            if (!($result['ok'] ?? false)) {
                throw new \RuntimeException('Test schema migration failed: ' . ($result['error'] ?? 'unknown'));
            }

            $pending = count(\AfricaGates\Services\MigrationRunner::status()['pending']);
            if ($pending === 0) return;
            if ($pending >= $lastPending) {
                throw new \RuntimeException("Test schema migration stalled with {$pending} step(s) pending.");
            }
            $lastPending = $pending;
        }

        throw new \RuntimeException('Test schema migration did not converge.');
    }

    /**
     * Everything needed to rebuild this database, as statements.
     *
     * DDL comes from `sqlite_master` in creation order, which is already a valid replay
     * order — a table always precedes its own indexes and triggers there.
     *
     * Rows are included as well, and not for tidiness. A migration that backfills or
     * seeds is part of the schema a migrated production database has; capturing only
     * DDL would reintroduce, in a subtler form, the exact divergence this change
     * removes. In practice that is the `gates_migrations` ledger and a handful of
     * defaults, so the cost is negligible.
     *
     * @return list<string>
     */
    private function snapshotSqlite(): array
    {
        $pdo = Capsule::connection()->getPdo();

        $ddl = [];
        $tables = [];
        $rows = $pdo->query("SELECT type, name, sql FROM sqlite_master
                              WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'")
                    ->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $ddl[] = (string) $r['sql'];
            if ($r['type'] === 'table') $tables[] = (string) $r['name'];
        }

        $inserts = [];
        foreach ($tables as $table) {
            $data = $pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($data as $row) {
                $cols = array_map(static fn(string $c): string => '"' . $c . '"', array_keys($row));
                $vals = array_map(
                    static fn($v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    array_values($row),
                );
                $inserts[] = 'INSERT INTO "' . $table . '" (' . implode(',', $cols) . ') VALUES ('
                           . implode(',', $vals) . ')';
            }
        }

        return array_merge($ddl, $inserts);
    }

    /** @param list<string> $template */
    private function replaySqliteTemplate(array $template): void
    {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($template as $stmt) {
            $pdo->exec($stmt);
        }
    }
}
