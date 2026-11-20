<?php
/**
 * Reversible registry-profile merges — the same tombstone + move-log machinery
 * as the nominee merge (2026_07_22), applied to duplicate profiles.
 *
 *   gates_profiles.merged_into / merged_at  — tombstone a merged-away profile
 *   gates_profile_merge_log                 — per-row undo journal
 *
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasColumn('gates_profiles', 'merged_into')) {
    DB::statement('ALTER TABLE gates_profiles ADD COLUMN merged_into BIGINT NULL');
    echo "  + gates_profiles.merged_into added\n";
} else { echo "  = gates_profiles.merged_into already present\n"; }

if (!$schema->hasColumn('gates_profiles', 'merged_at')) {
    DB::statement('ALTER TABLE gates_profiles ADD COLUMN merged_at ' . ($sqlite ? 'TEXT' : 'TIMESTAMP') . ' NULL DEFAULT NULL');
    echo "  + gates_profiles.merged_at added\n";
} else { echo "  = gates_profiles.merged_at already present\n"; }

if (!$schema->hasTable('gates_profile_merge_log')) {
    if ($sqlite) {
        DB::statement("CREATE TABLE gates_profile_merge_log (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          batch TEXT NOT NULL, keep_id INTEGER NOT NULL, merged_id INTEGER NOT NULL,
          op TEXT NOT NULL, tbl TEXT NOT NULL, row_pk INTEGER DEFAULT NULL,
          col TEXT DEFAULT NULL, old_val TEXT DEFAULT NULL, snapshot TEXT DEFAULT NULL,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pmerge_log_merged ON gates_profile_merge_log(merged_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pmerge_log_batch ON gates_profile_merge_log(batch)');
    } else {
        DB::statement("CREATE TABLE gates_profile_merge_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          batch VARCHAR(40) NOT NULL, keep_id BIGINT UNSIGNED NOT NULL, merged_id BIGINT UNSIGNED NOT NULL,
          op ENUM('reassign','delete') NOT NULL, tbl VARCHAR(64) NOT NULL, row_pk BIGINT UNSIGNED DEFAULT NULL,
          col VARCHAR(64) DEFAULT NULL, old_val VARCHAR(64) DEFAULT NULL, snapshot TEXT DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id), KEY idx_pmerge_log_merged(merged_id), KEY idx_pmerge_log_batch(batch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "  + gates_profile_merge_log created\n";
} else { echo "  = gates_profile_merge_log already present\n"; }

echo "profile merge-undo migration OK\n";
