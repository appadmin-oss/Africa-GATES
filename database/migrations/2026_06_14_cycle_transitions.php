<?php
/** Add gates_cycle_transitions (auditable cycle phase-change log). Idempotent. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ddl = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_cycle_transitions (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         cycle_id INTEGER NOT NULL,
         from_status TEXT,
         to_status TEXT NOT NULL,
         reason TEXT,
         actor TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_cycle_transitions (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         cycle_id BIGINT UNSIGNED NOT NULL,
         from_status VARCHAR(20) DEFAULT NULL,
         to_status VARCHAR(20) NOT NULL,
         reason VARCHAR(200) DEFAULT NULL,
         actor VARCHAR(80) DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_cyctrans_cycle (cycle_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

DB::connection()->getPdo()->exec($ddl);
if ($sqlite) {
    try { DB::statement('CREATE INDEX IF NOT EXISTS idx_cyctrans_cycle ON gates_cycle_transitions(cycle_id)'); } catch (\Throwable $e) {}
}
echo DB::schema()->hasTable('gates_cycle_transitions') ? "gates_cycle_transitions OK\n" : "*** FAILED ***\n";
