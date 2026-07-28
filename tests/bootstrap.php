<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Force the testing profile. By default the harness uses an in-memory SQLite
// database (see Tests\TestCase) so no external DB or .env is required.
$_ENV['APP_ENV']    = 'testing';
$_SERVER['APP_ENV'] = 'testing';

// TEST_DB_DRIVER=mysql opts into the parity run against the CANONICAL schema.
// DB_DRIVER must follow it, not be pinned to sqlite: anything that builds its own
// connection from config/database.php — MigrationRunner and every standalone
// migration do — would otherwise open a SQLite file and quietly replace the
// global Capsule the harness just configured, so the whole run would test SQLite
// while reporting that it tested MySQL.
$_ENV['DB_DRIVER'] = strtolower((string) (getenv('TEST_DB_DRIVER') ?: 'sqlite')) === 'mysql'
    ? 'mysql'
    : 'sqlite';

// Pin the test clock's timezone the same way every production entrypoint
// does, so a phase computed in a test means what it means in production.
\AfricaGates\Support\Clock::boot();
