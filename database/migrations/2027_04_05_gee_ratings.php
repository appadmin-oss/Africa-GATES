<?php
/**
 * How visitors rated Gee's answers (Services\GeeRatings).
 *
 *   gates_gee_ratings   one row per thumb: which mode answered (guide | support), where the
 *                       answer came from (`source`: the model, the scripted tier, the written
 *                       help), whether it helped, and the PATH of the page it was asked on.
 *
 * Deliberately not stored: the question, the answer, an IP, a session or an account. The
 * privacy note tells a visitor that what they type stays in their tab until they close it,
 * and a rating that kept the words would make that sentence false the moment somebody
 * pressed a thumb. What is kept answers the one question the operator has — does Gee help,
 * and where does it not — and nothing about who asked.
 *
 * VARCHAR, not ENUM, for every word (CLAUDE.md). Idempotent + driver-aware; indexes through
 * SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_gee_ratings')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_gee_ratings (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              mode TEXT NOT NULL DEFAULT 'guide',
              source TEXT NOT NULL DEFAULT '',
              helpful INTEGER NOT NULL DEFAULT 0,
              path TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE gates_gee_ratings (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              mode VARCHAR(16) NOT NULL DEFAULT 'guide',
              source VARCHAR(32) NOT NULL DEFAULT '',
              helpful SMALLINT NOT NULL DEFAULT 0,
              path VARCHAR(200) NOT NULL DEFAULT '',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_gee_ratings\n";
}
if ($schema->hasTable('gates_gee_ratings')) {
    echo '  ' . SchemaIndex::ensure('gates_gee_ratings', 'idx_geer_created', ['created_at']) . "\n";
}
