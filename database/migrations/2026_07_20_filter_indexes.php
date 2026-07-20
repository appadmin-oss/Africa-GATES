<?php
/**
 * Indexes for the admin list filters/lookups added in the enterprise-filtering
 * work, plus a MySQL range CHECK on judge scores (SQLite already has it).
 * Idempotent + driver-aware. None of these duplicate an existing single-column
 * index (unlike the earlier perf_indexes migration).
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

/** Create an index only when the table + all columns exist and it isn't already present. */
$addIndex = function (string $table, array $cols, string $name) use ($schema, $sqlite) {
    if (!$schema->hasTable($table)) return;
    foreach ($cols as $col) { if (!$schema->hasColumn($table, $col)) return; }
    $colList = implode(', ', $cols);
    try {
        if ($sqlite) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$colList})");
        } else {
            $exists = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$name]);
            if (!$exists) DB::statement("CREATE INDEX {$name} ON {$table} ({$colList})");
        }
        echo "index {$name} ensured on {$table}({$colList})\n";
    } catch (\Throwable $e) {
        echo "skip {$name}: " . $e->getMessage() . "\n";
    }
};

// Admin nominations list now filters/sorts by these + builds a DISTINCT country
// dropdown each load; both were unindexed → full scans as the table grows.
$addIndex('gates_nominations', ['country_code'], 'idx_nom_country');
$addIndex('gates_nominations', ['created_at'], 'idx_nom_created');
// Profile→nominee lookups + the ON DELETE SET NULL FK scan without this.
$addIndex('gates_nominees', ['profile_id'], 'idx_nominees_profile');
// Category-scoped vote scans (analytics/collusion) — category_id only appears as
// the 2nd column of uq_one_vote, which can't serve a category-first predicate.
$addIndex('gates_votes', ['category_id'], 'idx_votes_category');

// Defence-in-depth: judge scores are clamped 0..10 in JudgeService::saveScore and
// SQLite already CHECKs the range; add the same CHECK on MySQL so an out-of-range
// value from any future write path can't silently persist and skew CPI. Best-effort
// (needs MySQL 8.0.16+; no-op if already present or if legacy data would violate it).
if (!$sqlite && $schema->hasTable('gates_judge_criteria_scores')) {
    try {
        $has = DB::select(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores'
               AND CONSTRAINT_NAME = 'chk_jcrit_score' LIMIT 1"
        );
        if (!$has) {
            DB::statement("ALTER TABLE gates_judge_criteria_scores ADD CONSTRAINT chk_jcrit_score CHECK (score BETWEEN 0 AND 10)");
            echo "added CHECK chk_jcrit_score on gates_judge_criteria_scores\n";
        }
    } catch (\Throwable $e) {
        echo "skip chk_jcrit_score: " . $e->getMessage() . "\n";
    }
}

echo "filter-indexes migration OK\n";
