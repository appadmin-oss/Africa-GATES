<?php
/** Add gates_votes.idempotency_key (+ index) for safe vote retries. Idempotent. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
if (!$schema->hasColumn('gates_votes', 'idempotency_key')) {
    DB::statement('ALTER TABLE gates_votes ADD COLUMN idempotency_key TEXT');
    echo "  + gates_votes.idempotency_key added\n";
} else {
    echo "  = gates_votes.idempotency_key already present\n";
}
// Was CREATE INDEX IF NOT EXISTS — MySQL-invalid, so this never ran there.
echo SchemaIndex::ensure('gates_votes', 'idx_votes_idem', ['idempotency_key']) . "\n";
