<?php
/**
 * Milestones in a nominee's race, and the backers who asked to hear about them
 * (Services\NomineeMilestones; owner, 16 Nov 2026: "more features for voters and nominees").
 *
 *   gates_nominee_milestones  each milestone a nominee reaches, ONCE (UNIQUE nominee/kind/value):
 *                             10 … 5,000 supporters, the top 3 of their category, the lead, the
 *                             shortlist. `quiet` marks what was already true the first time a
 *                             cycle was looked at, so switching this on announces nothing.
 *   gates_nominee_follows     a backer who asked "tell me when they reach a milestone": an
 *                             address that VOTED for the nominee, nobody else's. `sent` caps
 *                             the messages at three per edition.
 *
 * VARCHAR, never ENUM. Idempotent + driver-aware; indexes through SchemaIndex. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$int = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_nominee_milestones')) {
    DB::statement("CREATE TABLE gates_nominee_milestones (id $id, nominee_id $int NOT NULL, cycle_id $int NOT NULL, kind VARCHAR(16) NOT NULL,
        value INT NOT NULL, reached_at DATETIME NOT NULL, quiet SMALLINT NOT NULL DEFAULT 0, notified_at DATETIME NULL)$tail");
    echo "  + gates_nominee_milestones\n";
} else {
    echo "  = gates_nominee_milestones already present\n";
}
echo SchemaIndex::ensure('gates_nominee_milestones', 'uq_nominee_milestone', ['nominee_id', 'kind', 'value'], true) . "\n";
echo SchemaIndex::ensure('gates_nominee_milestones', 'idx_milestone_cycle', ['cycle_id', 'notified_at']) . "\n";

if (!DB::schema()->hasTable('gates_nominee_follows')) {
    DB::statement("CREATE TABLE gates_nominee_follows (id $id, nominee_id $int NOT NULL, cycle_id $int NOT NULL, email VARCHAR(190) NOT NULL,
        email_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, sent INT NOT NULL DEFAULT 0, stopped_at DATETIME NULL)$tail");
    echo "  + gates_nominee_follows\n";
} else {
    echo "  = gates_nominee_follows already present\n";
}
echo SchemaIndex::ensure('gates_nominee_follows', 'uq_nominee_follow', ['nominee_id', 'email_hash'], true) . "\n";
echo "nominee milestones OK\n";
