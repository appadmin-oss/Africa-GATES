<?php
/** Add gates_vote_snapshots.prev_hash for the tamper-evident hash chain. Idempotent. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
if (!$schema->hasColumn('gates_vote_snapshots', 'prev_hash')) {
    DB::statement('ALTER TABLE gates_vote_snapshots ADD COLUMN prev_hash TEXT');
    echo "  + gates_vote_snapshots.prev_hash added\n";
} else {
    echo "  = gates_vote_snapshots.prev_hash already present\n";
}
