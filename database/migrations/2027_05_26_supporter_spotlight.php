<?php
/**
 * The Supporter Spotlight's two records (Services\SupporterSpotlight, Services\SpotlightMail).
 *
 *   gates_spotlight_hidden   a person who asked to be taken off the Spotlight. Keyed by the
 *                            same person key the Spotlight ranks by (an address hash, or
 *                            `points:<userId>`), so one request hides them on every edition.
 *   gates_spotlight_notices  the claim on each message the Spotlight sends — Champion of the
 *                            Day, Mobiliser of the Week, a first place in the top supporters —
 *                            taken BEFORE the message is composed. Its own table rather than
 *                            gates_supporter_honours, whose `kind` is ENUM('thanks','victory')
 *                            on MySQL: a third kind there is `Data truncated`, swallowed by the
 *                            sender's catch, and the claim would never hold (CLAUDE.md).
 *
 * Idempotent + driver-aware; indexes through SchemaIndex. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_spotlight_hidden')) {
    DB::statement($sqlite
        ? 'CREATE TABLE gates_spotlight_hidden (id INTEGER PRIMARY KEY AUTOINCREMENT, person_key VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL)'
        : 'CREATE TABLE gates_spotlight_hidden (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, person_key VARCHAR(80) NOT NULL,
             created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    echo "  + gates_spotlight_hidden\n";
} else {
    echo "  = gates_spotlight_hidden already present\n";
}
echo SchemaIndex::ensure('gates_spotlight_hidden', 'uq_spotlight_hidden', ['person_key'], true) . "\n";

if (!DB::schema()->hasTable('gates_spotlight_notices')) {
    DB::statement($sqlite
        ? 'CREATE TABLE gates_spotlight_notices (id INTEGER PRIMARY KEY AUTOINCREMENT, kind VARCHAR(24) NOT NULL, cycle_id INTEGER NOT NULL,
             period VARCHAR(16) NOT NULL, person_key VARCHAR(80) NOT NULL, delivered INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)'
        : 'CREATE TABLE gates_spotlight_notices (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(24) NOT NULL,
             cycle_id INT UNSIGNED NOT NULL, period VARCHAR(16) NOT NULL, person_key VARCHAR(80) NOT NULL,
             delivered TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    echo "  + gates_spotlight_notices\n";
} else {
    echo "  = gates_spotlight_notices already present\n";
}
echo SchemaIndex::ensure('gates_spotlight_notices', 'uq_spotlight_notice', ['kind', 'cycle_id', 'period', 'person_key'], true) . "\n";

echo "supporter spotlight OK\n";
