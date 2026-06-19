<?php
/**
 * Add bonus/paid-vote weighting to gates_votes: vote_type, weight, donation_id.
 * Wires the previously-dead gates_donations.bonus_votes. Idempotent.
 *
 * CHECK on vote_type is omitted here (kept in the fresh schema) — SQLite's
 * ADD COLUMN can't always attach it post-hoc; the app writes only known values.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();

$cols = [
    'vote_type'   => "ALTER TABLE gates_votes ADD COLUMN vote_type TEXT NOT NULL DEFAULT 'standard'",
    'weight'      => 'ALTER TABLE gates_votes ADD COLUMN weight INTEGER NOT NULL DEFAULT 1',
    'donation_id' => 'ALTER TABLE gates_votes ADD COLUMN donation_id INTEGER',
];
foreach ($cols as $col => $ddl) {
    if (!$schema->hasColumn('gates_votes', $col)) {
        DB::statement($ddl);
        echo "  + gates_votes.$col added\n";
    } else {
        echo "  = gates_votes.$col already present\n";
    }
}

try { DB::statement('CREATE INDEX IF NOT EXISTS idx_votes_donation ON gates_votes(donation_id)'); echo "  = idx_votes_donation ensured\n"; }
catch (\Throwable $e) { echo "  ! index skipped: " . $e->getMessage() . "\n"; }

echo "vote weighting OK\n";
