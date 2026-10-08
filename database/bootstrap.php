<?php
/**
 * Give a migration a database connection — reusing one if it already has it.
 *
 * ── THE BUG THIS EXISTS TO FIX ───────────────────────────────────────────────
 *
 * Every migration used to open its own connection:
 *
 *     $c = new DB();
 *     $c->addConnection(require .../config/database.php);
 *     $c->setAsGlobal();
 *     $c->bootEloquent();
 *
 * Correct when a file is run on its own — `php database/migrations/x.php` — and
 * ruinous when sixty-six of them are `require`d into ONE process, which is what
 * MigrationRunner does for `db:migrate`, for the admin console's migrate button
 * and for the webcron. Each `new DB()` builds a fresh Capsule with a fresh PDO;
 * `setAsGlobal()` replaces the pointer but does not close the connection behind
 * it, so the process finishes holding sixty-six open sessions.
 *
 * On a shared host that is over the connection limit long before the last
 * migration, and the failure is the worst shape a failure can take: the run dies
 * partway with "Too many connections", the migrations that already ran are
 * recorded as done, and the schema is left half-applied — which then presents as
 * unrelated 500s on whichever page touches a column that never arrived.
 *
 * It hid because SQLite has no connection limit, so the whole suite was green.
 * The MySQL parity run is what surfaced it, which is exactly what that mode is
 * for.
 *
 * ── WHAT THIS DOES INSTEAD ───────────────────────────────────────────────────
 *
 * Idempotent. If a Capsule has already been booted — by the runner, by the app,
 * or by an earlier migration in the same process — this returns it untouched and
 * opens nothing. Only the standalone case builds one, so a migration file is
 * still runnable on its own exactly as before.
 *
 * NOT in database/migrations/, because the runner globs that directory and would
 * treat a helper as a migration to apply.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as DB;

// safeLoad, and only once: a second Dotenv pass over an already-populated
// environment is wasted file I/O sixty-six times over.
if (!isset($GLOBALS['__ag_migration_env'])) {
    $GLOBALS['__ag_migration_env'] = true;
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
}

if (!function_exists('ag_migration_db')) {
    /**
     * Make sure `DB::` works. Opens a connection only if there is not one already.
     *
     * Returns nothing on purpose: migrations use the static facade and never the
     * Capsule object, so handing one back would only invite a file to hold a
     * reference to a connection the runner owns.
     */
    function ag_migration_db(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        // Somebody else already booted one — the console, the web app, the test
        // harness. Adopt it rather than opening a second connection to the same
        // database, and, in the harness's case, rather than pointing the global
        // at production while a test is mid-run.
        try {
            DB::connection()->getPdo();
            return;
        } catch (\Throwable) {
            // No global Capsule, or one that cannot connect. Build our own — this
            // is the standalone `php database/migrations/x.php` path.
        }

        $capsule = new DB();
        $capsule->addConnection(require __DIR__ . '/../config/database.php');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}

ag_migration_db();
