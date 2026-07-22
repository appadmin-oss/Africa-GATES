<?php
/**
 * Nominator feedback — so no nomination ever goes silent.
 *   decision_reason   — the note shown to the nominator on approve/reject
 *                       (typed by a moderator, or AI-suggested).
 *   nominator_ack_at   — when a "still under review" acknowledgement was sent
 *                        for a long-pending nomination (dedupe guard).
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
if ($schema->hasTable('gates_nominations')) {
    $sqlite = DB::connection()->getDriverName() === 'sqlite';
    if (!$schema->hasColumn('gates_nominations', 'decision_reason')) {
        DB::statement('ALTER TABLE gates_nominations ADD COLUMN decision_reason ' . ($sqlite ? 'TEXT' : 'TEXT'));
        echo "added gates_nominations.decision_reason\n";
    }
    if (!$schema->hasColumn('gates_nominations', 'nominator_ack_at')) {
        DB::statement('ALTER TABLE gates_nominations ADD COLUMN nominator_ack_at ' . ($sqlite ? 'TEXT' : 'TIMESTAMP NULL DEFAULT NULL'));
        echo "added gates_nominations.nominator_ack_at\n";
    }
}
echo "nomination feedback migration OK\n";
