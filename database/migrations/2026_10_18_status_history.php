<?php
/**
 * A record of what the status page said, so it can answer "was it broken earlier?"
 *
 * ── WHY A PAGE THAT MEASURES LIVE STILL NEEDS A HISTORY ──────────────────────
 *
 * {@see \AfricaGates\Services\SystemStatus} measures at request time, which makes it honest
 * and makes it blind. It can say what is true this second and nothing at all about the two
 * hours somebody could not check out — so a supporter whose payment failed at nine in the
 * morning loads a green page at noon and concludes the failure was theirs.
 *
 * That is the specific question a status page exists to answer. "It is fine now" is the less
 * useful half of it.
 *
 * ── ONE ROW PER TICK, NOT PER PAGE VIEW ──────────────────────────────────────
 *
 * Written by the scheduled task, not by the request. A row per visitor would make the table
 * a traffic log, and would let anybody grow it by holding down refresh. The cron tick runs
 * on a schedule nobody outside can influence, and the schedule is itself one of the things
 * being recorded — a gap in this table IS the evidence that scheduled work stopped, which is
 * the one outage no self-report can ever cover.
 *
 * Trimmed to 30 days by the same sweep that trims the mail log.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_status_log')) {
    echo "  = gates_status_log already present\n";
    return;
}

DB::statement($sqlite
    ? "CREATE TABLE gates_status_log (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         taken_at TEXT NOT NULL,
         overall TEXT NOT NULL,
         components_json TEXT NOT NULL,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE gates_status_log (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         taken_at TIMESTAMP NOT NULL,
         overall VARCHAR(16) NOT NULL,
         components_json TEXT NOT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_status_taken (taken_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE INDEX IF NOT EXISTS idx_status_taken ON gates_status_log (taken_at)');
}

echo "  + gates_status_log created\n";
