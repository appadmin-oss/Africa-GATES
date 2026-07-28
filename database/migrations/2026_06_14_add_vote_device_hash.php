<?php
/**
 * Add gates_votes.device_hash (+ index) so the device-based fraud signals can
 * actually function. VoteService now writes this column; FraudService reads it.
 * Idempotent: safe to re-run.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

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

// Index. The previous version claimed "CREATE INDEX IF NOT EXISTS works on both
// SQLite and MySQL 8" — it does not. `IF NOT EXISTS` on an index is SQLite/Postgres
// syntax; MySQL answers 1064, and because the call was wrapped in try/catch this
// printed a warning and moved on, so on MySQL the index was never created. Harmless
// on a fresh database (schema.sql declares it inline as KEY) and NOT harmless on a
// deployment old enough to have needed this catch-up.
echo SchemaIndex::ensure('gates_votes', 'idx_votes_device', ['device_hash']) . "\n";

echo "Done. gates_votes columns: " . implode(', ', $schema->getColumnListing('gates_votes')) . "\n";
