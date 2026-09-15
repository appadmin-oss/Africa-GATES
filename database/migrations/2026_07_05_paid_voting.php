<?php
/**
 * Batch 3 · Task 13 — paid voting.
 * gates_donations.intent_nominee_id: a paid-vote order remembers which nominee
 * it is for, so a confirmed payment auto-mints the weighted paid votes.
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
if ($schema->hasTable('gates_donations') && !$schema->hasColumn('gates_donations', 'intent_nominee_id')) {
    $sqlite = DB::connection()->getDriverName() === 'sqlite';
    DB::statement('ALTER TABLE gates_donations ADD COLUMN intent_nominee_id ' . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED DEFAULT NULL'));
    echo "added gates_donations.intent_nominee_id\n";
}
echo "paid voting migration OK\n";
