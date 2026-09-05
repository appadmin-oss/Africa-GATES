<?php
declare(strict_types=1);

use AfricaGates\Support\Env;
$driver = Env::get('DB_DRIVER', 'mysql');

if ($driver === 'sqlite') {
    $dbPath = Env::get('DB_PATH', __DIR__ . '/../var/data/africa_gates.sqlite');
    if (!is_dir(dirname($dbPath))) {
        @mkdir(dirname($dbPath), 0775, true);
    }
    // Laravel 12's SQLite connector resolves a NON-existent database path through
    // base_path() (a full-framework helper that isn't defined in this standalone
    // app), which fatals on first connect. Ensure the file exists first — a 0-byte
    // file is a valid, empty SQLite database. (':memory:' is never touched.)
    if ($dbPath !== ':memory:' && !file_exists($dbPath)) {
        @touch($dbPath);
    }
    return [
        'driver'                  => 'sqlite',
        'database'                => $dbPath,
        'prefix'                  => '',
        'foreign_key_constraints' => true,
        'options'                 => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ],
    ];
}

return [
    'driver'    => 'mysql',
    'host'      => Env::get('DB_HOST', '127.0.0.1'),
    'port'      => Env::int('DB_PORT', 3306),
    'database'  => Env::get('DB_NAME', 'africa_gates'),
    'username'  => Env::get('DB_USER', 'root'),
    'password'  => Env::get('DB_PASS', ''),
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    // Pin the SESSION time_zone so the database agrees with THIS process about
    // what a stored timestamp means. Not cosmetic: cycle boundaries are DATETIME
    // (returned verbatim) while ballot/nomination timestamps are TIMESTAMP
    // (converted into the session timezone on read), so every `voted_at >=
    // voting_close` comparison shifts by the session's UTC offset. Measured on
    // MySQL 8.0 with the session at +01:00 — WAT, the obvious setting for a
    // Nigerian deployment — a vote thirty minutes BEFORE a deadline reads as
    // late; under a negative offset real late votes are hidden instead.
    //
    // Applied by MySqlConnector as `SET time_zone=...` on every connect, so web,
    // console, cron and migrations all land in the same frame without any of them
    // having to remember. See Clock::databaseTimezone() for the fixed-offset-vs-
    // zone-name reasoning and the DST caveat.
    'timezone'  => \AfricaGates\Support\Clock::databaseTimezone(),
    'prefix'    => '',
    'strict'    => true,
    'options'   => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
