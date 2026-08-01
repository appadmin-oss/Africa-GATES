<?php
/** Add gates_jobs (background job queue). Idempotent. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ddl = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_jobs (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         type TEXT NOT NULL,
         payload TEXT,
         status TEXT NOT NULL DEFAULT 'pending',
         attempts INTEGER NOT NULL DEFAULT 0,
         run_after TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         locked_at TEXT,
         last_error TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_jobs (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         type VARCHAR(80) NOT NULL,
         payload JSON DEFAULT NULL,
         status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
         attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
         run_after TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         locked_at TIMESTAMP NULL DEFAULT NULL,
         last_error VARCHAR(500) DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_jobs_due (status, run_after)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

DB::connection()->getPdo()->exec($ddl);
if ($sqlite) { try { DB::statement('CREATE INDEX IF NOT EXISTS idx_jobs_due ON gates_jobs(status, run_after)'); } catch (\Throwable $e) {} }
echo DB::schema()->hasTable('gates_jobs') ? "gates_jobs OK\n" : "*** FAILED ***\n";
