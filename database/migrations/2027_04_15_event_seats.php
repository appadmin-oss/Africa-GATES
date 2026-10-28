<?php
/**
 * Seating by record (consoles v7 §5, People › Seating): a seat at a table belongs to a guest of
 * honour (gates_event_invites) or a ticket holder (gates_event_registrations), not only to a
 * typed name. So the plan knows who is still without a seat, and a person sits at ONE table:
 * UNIQUE (event_id, kind, ref_id) makes a second seat a move, never a duplicate.
 *
 * Typed names (gates_event_tables.guests) stay, for the MC's plus-one and anybody else with no
 * record; a table's seats count both. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_event_seats')) {
    DB::statement($sqlite
        ? 'CREATE TABLE gates_event_seats (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER NOT NULL, table_id INTEGER NOT NULL,
             kind TEXT NOT NULL, ref_id INTEGER NOT NULL, seated_by INTEGER NULL, created_at TEXT NOT NULL)'
        : 'CREATE TABLE gates_event_seats (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL, table_id INT UNSIGNED NOT NULL,
             kind VARCHAR(16) NOT NULL, ref_id INT UNSIGNED NOT NULL, seated_by INT UNSIGNED NULL, created_at DATETIME NOT NULL)
           ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    echo "  + gates_event_seats\n";
}
SchemaIndex::ensure('gates_event_seats', 'uq_evseat_person', ['event_id', 'kind', 'ref_id'], true);
SchemaIndex::ensure('gates_event_seats', 'idx_evseat_table', ['table_id']);
