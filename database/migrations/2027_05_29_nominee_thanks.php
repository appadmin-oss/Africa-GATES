<?php
/**
 * A nominee's thank-you to the people who backed them (Services\NomineeThanks; owner, 16 Nov
 * 2026: "make the voter feel good and proud of themselves … honour them").
 *
 *   gates_nominee_thanks   one message per nominee, written on their own page; sent once to
 *                          every backer who can be reached, then kept (and shown on their
 *                          vote page). `sent_at` is when the sending FINISHED; each address is
 *                          claimed in gates_broadcast_log as it goes, so a sweep that stops
 *                          half-way resumes rather than repeats.
 *
 * Idempotent + driver-aware; the UNIQUE index through SchemaIndex (CLAUDE.md). NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$int = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_nominee_thanks')) {
    DB::statement("CREATE TABLE gates_nominee_thanks (id $id, nominee_id $int NOT NULL, user_id $int NOT NULL, message TEXT NOT NULL,
        reached INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, sent_at DATETIME NULL)$tail");
    echo "  + gates_nominee_thanks\n";
} else {
    echo "  = gates_nominee_thanks already present\n";
}
echo SchemaIndex::ensure('gates_nominee_thanks', 'uq_nominee_thanks', ['nominee_id'], true) . "\n";
echo "nominee thanks OK\n";
