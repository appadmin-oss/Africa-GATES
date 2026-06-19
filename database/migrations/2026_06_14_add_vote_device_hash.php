<?php
/**
 * Add gates_votes.device_hash (+ index) so the device-based fraud signals can
 * actually function. VoteService now writes this column; FraudService reads it.
 * Idempotent: safe to re-run.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
if (!$schema->hasColumn('gates_votes', 'device_hash')) {
    DB::statement('ALTER TABLE gates_votes ADD COLUMN device_hash TEXT');
    echo "  + gates_votes.device_hash added\n";
} else {
    echo "  = gates_votes.device_hash already present\n";
}

// Index (CREATE INDEX IF NOT EXISTS works on both SQLite and MySQL 8).
try {
    DB::statement('CREATE INDEX IF NOT EXISTS idx_votes_device ON gates_votes(device_hash)');
    echo "  = idx_votes_device ensured\n";
} catch (\Throwable $e) {
    echo "  ! index skipped: " . $e->getMessage() . "\n";
}

echo "Done. gates_votes columns: " . implode(', ', $schema->getColumnListing('gates_votes')) . "\n";
