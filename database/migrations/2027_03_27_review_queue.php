<?php
/**
 * The nomination review queue's two pieces of shared state (AdminConsole v2, README §10b).
 *
 *   gates_nomination_notes   the drawer's "Add a note for your team": internal, never shown
 *                            to a nominator or a nominee, one row per note so nobody's words
 *                            are overwritten by a colleague's. Not the audit log — that is a
 *                            record of what was DONE, and a note is something somebody said.
 *
 *   gates_admin_presence     who has the review list or a nomination open, for "2 others
 *                            here" and "Tunde A. is viewing". One row per admin, overwritten
 *                            on every page view and heartbeat. It only ever INFORMS: nothing
 *                            reads it to block a decision, because a lock left by a closed
 *                            laptop would stop the queue for everybody.
 *
 * Plain VARCHAR/INT, no ENUM (CLAUDE.md). Idempotent + driver-aware; indexes through
 * SchemaIndex, never the raw `IF NOT EXISTS` form. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_nomination_notes')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_nomination_notes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              nomination_id INTEGER NOT NULL,
              admin_id INTEGER NULL,
              body TEXT NOT NULL,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
           )"
        : "CREATE TABLE gates_nomination_notes (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              nomination_id BIGINT UNSIGNED NOT NULL,
              admin_id BIGINT UNSIGNED NULL,
              body VARCHAR(2000) NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_nomination_notes\n";
}
echo '  ' . SchemaIndex::ensure('gates_nomination_notes', 'idx_nom_notes_nom', ['nomination_id', 'created_at']) . "\n";

if (!$schema->hasTable('gates_admin_presence')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_admin_presence (
              admin_id INTEGER PRIMARY KEY,
              area TEXT NOT NULL DEFAULT '',
              target_id INTEGER NULL,
              seen_at TEXT NOT NULL
           )"
        : "CREATE TABLE gates_admin_presence (
              admin_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
              area VARCHAR(40) NOT NULL DEFAULT '',
              target_id BIGINT UNSIGNED NULL,
              seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_admin_presence\n";
}
echo '  ' . SchemaIndex::ensure('gates_admin_presence', 'idx_admin_presence_area', ['area', 'seen_at']) . "\n";

echo "review queue state OK\n";
