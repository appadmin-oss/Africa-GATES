<?php
/** Performance indexes on hot query columns (scale readiness). Idempotent + driver-aware. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

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

// Hot read paths: vote tallies + recency, ledger by user, financial recency, account recency, CPI lookups.
$addIndex('gates_votes', ['nominee_id'], 'idx_votes_nominee');
$addIndex('gates_votes', ['voted_at'], 'idx_votes_voted_at');
$addIndex('gates_points_ledger', ['user_id'], 'idx_points_user');
$addIndex('gates_donations', ['created_at'], 'idx_donations_created');
$addIndex('gates_donations', ['status'], 'idx_donations_status');
$addIndex('gates_orders', ['created_at'], 'idx_orders_created');
$addIndex('gates_orders', ['status'], 'idx_orders_status');
$addIndex('gates_users', ['created_at'], 'idx_users_created');
$addIndex('gates_cpi_history', ['profile_id'], 'idx_cpi_profile');
$addIndex('gates_form_submissions', ['form_id'], 'idx_formsub_formid');

echo "perf-indexes migration OK\n";
