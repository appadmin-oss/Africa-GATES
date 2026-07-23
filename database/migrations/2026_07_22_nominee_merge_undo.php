<?php
/**
 * Reversible nominee merges (tombstones + move-log).
 *
 * Before this, a merge HARD-DELETED the duplicate rows after folding their
 * votes/scores into the survivor — irreversible, and a fat-fingered merge lost
 * data for good. This makes a merge a *tombstone*: the duplicate nominee row
 * stays (hidden from every public/scoring surface via `merged_into`) and every
 * row the merge moved or dropped is recorded in `gates_merge_log`, so an unmerge
 * can restore the pre-merge state exactly.
 *
 *   gates_nominees.merged_into  — survivor id when this row is a merge tombstone (NULL = live)
 *   gates_nominees.merged_at    — when it was merged
 *   gates_merge_log             — per-row undo journal (reassign old value / deleted-row snapshot)
 *
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema  = DB::schema();
$sqlite  = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasColumn('gates_nominees', 'merged_into')) {
    DB::statement('ALTER TABLE gates_nominees ADD COLUMN merged_into BIGINT NULL');
    echo "  + gates_nominees.merged_into added\n";
} else {
    echo "  = gates_nominees.merged_into already present\n";
}

if (!$schema->hasColumn('gates_nominees', 'merged_at')) {
    // A plain nullable timestamp — no default (portable across MySQL/SQLite).
    DB::statement('ALTER TABLE gates_nominees ADD COLUMN merged_at ' . ($sqlite ? 'TEXT' : 'TIMESTAMP') . ' NULL DEFAULT NULL');
    echo "  + gates_nominees.merged_at added\n";
} else {
    echo "  = gates_nominees.merged_at already present\n";
}

if (!$schema->hasTable('gates_merge_log')) {
    if ($sqlite) {
        DB::statement("CREATE TABLE gates_merge_log (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          batch TEXT NOT NULL,
          keep_id INTEGER NOT NULL,
          merged_id INTEGER NOT NULL,
          op TEXT NOT NULL,
          tbl TEXT NOT NULL,
          row_pk INTEGER DEFAULT NULL,
          col TEXT DEFAULT NULL,
          old_val TEXT DEFAULT NULL,
          snapshot TEXT DEFAULT NULL,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_merge_log_merged ON gates_merge_log(merged_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_merge_log_batch ON gates_merge_log(batch)');
    } else {
        DB::statement("CREATE TABLE gates_merge_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          batch VARCHAR(40) NOT NULL,
          keep_id BIGINT UNSIGNED NOT NULL,
          merged_id BIGINT UNSIGNED NOT NULL,
          op ENUM('reassign','delete') NOT NULL,
          tbl VARCHAR(64) NOT NULL,
          row_pk BIGINT UNSIGNED DEFAULT NULL,
          col VARCHAR(64) DEFAULT NULL,
          old_val VARCHAR(64) DEFAULT NULL,
          snapshot TEXT DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id), KEY idx_merge_log_merged(merged_id), KEY idx_merge_log_batch(batch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "  + gates_merge_log created\n";
} else {
    echo "  = gates_merge_log already present\n";
}

echo "nominee merge-undo migration OK\n";
