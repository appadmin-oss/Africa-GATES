<?php
/**
 * A nominee's own voice and words to the people backing them (owner, 16 Nov 2026: "build them
 * all"):
 *
 *   gates_nominee_updates  short updates in the nominee's words (Services\NomineeUpdates): shown
 *                          on their vote page and mailed to the backers who asked to hear, one
 *                          every three days, changeable for fifteen minutes before it goes.
 *   gates_nominee_voice    one voice thank-you per nominee (Services\NomineeVoice), up to thirty
 *                          seconds; the file lives outside the web root, the row names it.
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

if (!DB::schema()->hasTable('gates_nominee_updates')) {
    DB::statement("CREATE TABLE gates_nominee_updates (id $id, nominee_id $int NOT NULL, user_id $int NOT NULL, body TEXT NOT NULL,
        created_at DATETIME NOT NULL, sent_at DATETIME NULL, reached INT NOT NULL DEFAULT 0, removed_at DATETIME NULL)$tail");
    echo "  + gates_nominee_updates\n";
} else {
    echo "  = gates_nominee_updates already present\n";
}
echo SchemaIndex::ensure('gates_nominee_updates', 'idx_nominee_update', ['nominee_id', 'created_at']) . "\n";
echo SchemaIndex::ensure('gates_nominee_updates', 'idx_nominee_update_due', ['sent_at', 'created_at']) . "\n";

if (!DB::schema()->hasTable('gates_nominee_voice')) {
    DB::statement("CREATE TABLE gates_nominee_voice (id $id, nominee_id $int NOT NULL, user_id $int NOT NULL, path VARCHAR(120) NOT NULL,
        seconds INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$tail");
    echo "  + gates_nominee_voice\n";
} else {
    echo "  = gates_nominee_voice already present\n";
}
echo SchemaIndex::ensure('gates_nominee_voice', 'uq_nominee_voice', ['nominee_id'], true) . "\n";
echo "nominee voice and updates OK\n";
