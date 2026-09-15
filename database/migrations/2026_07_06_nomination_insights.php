<?php
/**
 * Review-at-scale — advisory triage rows for nominations (quality score,
 * summary, duplicate hints). Idempotent + driver-aware. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_nomination_insights')) {
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::statement("CREATE TABLE gates_nomination_insights (
          nomination_id INTEGER PRIMARY KEY,
          quality_score INTEGER,
          summary TEXT,
          duplicates_json TEXT,
          model TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    } else {
        DB::statement("CREATE TABLE gates_nomination_insights (
          nomination_id BIGINT UNSIGNED NOT NULL,
          quality_score TINYINT UNSIGNED DEFAULT NULL,
          summary TEXT,
          duplicates_json TEXT,
          model VARCHAR(40) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(nomination_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "created gates_nomination_insights\n";
}
echo "nomination insights migration OK\n";
