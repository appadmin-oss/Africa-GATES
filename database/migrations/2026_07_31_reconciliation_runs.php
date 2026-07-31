<?php
/**
 * The reconciliation audit trail.
 *
 * `payments:reconcile` has always been able to confirm a payment whose browser
 * callback was dropped — silently, leaving no record that it had done so. That is
 * acceptable for a cron nobody watches and unacceptable the moment an ADMIN can press
 * the button: "who confirmed this order, when, and what did the gateway say at the
 * time" has to be answerable months later by someone who was not there. A finance
 * correction with no trail is indistinguishable from tampering.
 *
 * One row per RUN rather than per payment. A run is the unit an operator performs and
 * the unit they would need to explain; the per-payment findings ride along in
 * `detail_json`, capped by the writer so a 200-row sweep cannot put a huge blob in a
 * table the Finance page reads on every load.
 *
 * `naira` records only what was CONFIRMED in that run, which is the figure that
 * reconciles against a bank statement. Idempotent, and additive on both drivers.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_reconciliation_runs')) {
    DB::statement($sqlite
        ? "CREATE TABLE IF NOT EXISTS gates_reconciliation_runs (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             ran_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
             actor TEXT NOT NULL DEFAULT 'system',
             mode TEXT NOT NULL DEFAULT 'check',
             checked INTEGER NOT NULL DEFAULT 0,
             confirmed INTEGER NOT NULL DEFAULT 0,
             failed INTEGER NOT NULL DEFAULT 0,
             mismatch INTEGER NOT NULL DEFAULT 0,
             unverifiable INTEGER NOT NULL DEFAULT 0,
             naira INTEGER NOT NULL DEFAULT 0,
             detail_json TEXT
           )"
        : "CREATE TABLE IF NOT EXISTS gates_reconciliation_runs (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             actor VARCHAR(120) NOT NULL DEFAULT 'system',
             mode VARCHAR(10) NOT NULL DEFAULT 'check',
             checked INT UNSIGNED NOT NULL DEFAULT 0,
             confirmed INT UNSIGNED NOT NULL DEFAULT 0,
             failed INT UNSIGNED NOT NULL DEFAULT 0,
             mismatch INT UNSIGNED NOT NULL DEFAULT 0,
             unverifiable INT UNSIGNED NOT NULL DEFAULT 0,
             naira BIGINT UNSIGNED NOT NULL DEFAULT 0,
             detail_json LONGTEXT,
             PRIMARY KEY(id),
             KEY idx_recon_ran (ran_at)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_reconciliation_runs created\n";
} else {
    echo "  = gates_reconciliation_runs already present\n";
}

echo "reconciliation run log OK\n";
