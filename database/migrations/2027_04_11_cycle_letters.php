<?php
/**
 * The nominee voting letter, written per edition (owner, 28 Oct 2026: "editable and viewable
 * per cycle with an ultramodern editor").
 *
 *   gates_cycle_letters  one row per SAVED VERSION of an edition's letter.
 *     state     'draft' (the one the editor autosaves into, at most one per cycle and kind)
 *               or 'published' (every publish is a new row, so history is the table itself
 *               and a restore is a copy, never an edit of the past)
 *     kind      'voting' today; a VARCHAR so a second letter needs no migration
 *     headline  the line in large type above "Dear …"
 *     body      the paragraphs, separated by a blank line, with {fields} (Services\CycleLetter)
 *
 * The letter in use is the newest published row; with none, the shipped default. Nothing
 * here is the anti-fraud notice: that paragraph is fixed in code and cannot be edited away.
 * VARCHAR, not ENUM (CLAUDE.md); the index through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
if (!$schema->hasTable('gates_cycle_letters')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_cycle_letters (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             cycle_id INTEGER NOT NULL,
             kind TEXT NOT NULL DEFAULT 'voting',
             state TEXT NOT NULL DEFAULT 'draft',
             headline TEXT NOT NULL DEFAULT '',
             body TEXT NOT NULL,
             saved_by INTEGER NULL,
             created_at TEXT NOT NULL,
             updated_at TEXT NULL)"
        : "CREATE TABLE gates_cycle_letters (
             id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             cycle_id INT UNSIGNED NOT NULL,
             kind VARCHAR(32) NOT NULL DEFAULT 'voting',
             state VARCHAR(16) NOT NULL DEFAULT 'draft',
             headline VARCHAR(160) NOT NULL DEFAULT '',
             body TEXT NOT NULL,
             saved_by INT UNSIGNED NULL,
             created_at TIMESTAMP NOT NULL,
             updated_at TIMESTAMP NULL DEFAULT NULL
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_cycle_letters\n";
}
\AfricaGates\Support\SchemaIndex::ensure('gates_cycle_letters', 'idx_cycle_letter', ['cycle_id', 'kind', 'state', 'id']);
echo "cycle letters OK\n";
