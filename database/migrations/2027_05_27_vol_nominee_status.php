<?php
/**
 * Nominee status updates in the volunteer portal (Services\Volunteer\NomineeStatus).
 *
 *   gates_vol_nominee_status    one row per update a volunteer sends about one nominee: where
 *                               their portal profile stands, their publicity and the voting
 *                               mobilisation, and a note. Kept as a history, newest wins.
 *   gates_vol_status_requests   a lead asking the team on an award for an update on every
 *                               nominee by a date ("Can we have an update on all nominees,
 *                               please?" — the coordinators' own message, 16 Nov 2026).
 *
 * The three answers are VARCHAR, not ENUM: a word added later must not become `Data truncated`
 * on MySQL while the suite stays green (CLAUDE.md). Idempotent + driver-aware; indexes through
 * SchemaIndex. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$int = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_vol_nominee_status')) {
    DB::statement("CREATE TABLE gates_vol_nominee_status (id $id, cycle_id $int NOT NULL, nominee_id $int NOT NULL, volunteer_id $int NOT NULL,
        portal VARCHAR(16) NOT NULL, publicity VARCHAR(16) NOT NULL, mobilising VARCHAR(16) NOT NULL, note TEXT NULL, created_at DATETIME NOT NULL)$tail");
    echo "  + gates_vol_nominee_status\n";
} else {
    echo "  = gates_vol_nominee_status already present\n";
}
echo SchemaIndex::ensure('gates_vol_nominee_status', 'idx_vns_nominee', ['nominee_id', 'created_at']) . "\n";
echo SchemaIndex::ensure('gates_vol_nominee_status', 'idx_vns_cycle', ['cycle_id', 'created_at']) . "\n";

if (!DB::schema()->hasTable('gates_vol_status_requests')) {
    DB::statement("CREATE TABLE gates_vol_status_requests (id $id, cycle_id $int NOT NULL, volunteer_id $int NOT NULL, message TEXT NULL,
        due_on DATE NOT NULL, created_at DATETIME NOT NULL)$tail");
    echo "  + gates_vol_status_requests\n";
} else {
    echo "  = gates_vol_status_requests already present\n";
}
echo SchemaIndex::ensure('gates_vol_status_requests', 'idx_vsr_cycle', ['cycle_id', 'created_at']) . "\n";

echo "volunteer nominee status OK\n";
