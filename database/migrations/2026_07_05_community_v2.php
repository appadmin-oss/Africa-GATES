<?php
/**
 * Batch 3 · Task 7 — community v2.
 * 1) author_user_id on gates_threads + gates_comments: posting is now
 *    members-only, so content is attributed to the account (enables reply
 *    notifications, own-post delete, member badges).
 * 2) gates_reports: member reporting; content quarantines at the threshold.
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

foreach (['gates_threads', 'gates_comments'] as $table) {
    if ($schema->hasTable($table) && !$schema->hasColumn($table, 'author_user_id')) {
        DB::statement("ALTER TABLE {$table} ADD COLUMN author_user_id " . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED DEFAULT NULL'));
        echo "added {$table}.author_user_id\n";
    }
}

if (!$schema->hasTable('gates_reports')) {
    if ($sqlite) {
        DB::statement("CREATE TABLE gates_reports (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          target_type TEXT NOT NULL CHECK(target_type IN ('thread','comment')),
          target_id INTEGER NOT NULL,
          user_id INTEGER NOT NULL,
          reason TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE(target_type, target_id, user_id)
        )");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reports_target ON gates_reports(target_type, target_id)');
    } else {
        DB::statement("CREATE TABLE gates_reports (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          target_type ENUM('thread','comment') NOT NULL,
          target_id BIGINT UNSIGNED NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          reason VARCHAR(300) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id), UNIQUE KEY uq_report(target_type, target_id, user_id),
          KEY idx_reports_target(target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "created gates_reports\n";
}

echo "community v2 migration OK\n";
