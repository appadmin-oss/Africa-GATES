<?php
/** Polls v2: generalize target (thread|post → blog polls) + WhatsApp-style multi-answer. Idempotent. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

// v1 → v2: only rebuild when target_type is absent. Polls are a brand-new
// feature with no production data, so rebuilding the two poll tables is safe
// (intentionally destructive of polls only — nothing else is touched).
if ($schema->hasTable('gates_polls') && !$schema->hasColumn('gates_polls', 'target_type')) {
    $schema->dropIfExists('gates_poll_votes');
    $schema->dropIfExists('gates_polls');
    echo "dropped v1 poll tables\n";
}

if (!$schema->hasTable('gates_polls')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_polls (
             id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL DEFAULT 'thread', target_id INTEGER NOT NULL,
             question TEXT NOT NULL, options TEXT NOT NULL, multi INTEGER NOT NULL DEFAULT 0, is_closed INTEGER NOT NULL DEFAULT 0,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(target_type, target_id) )"
        : "CREATE TABLE gates_polls (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, target_type VARCHAR(16) NOT NULL DEFAULT 'thread', target_id BIGINT UNSIGNED NOT NULL,
             question VARCHAR(255) NOT NULL, options TEXT NOT NULL, multi TINYINT(1) NOT NULL DEFAULT 0, is_closed TINYINT(1) NOT NULL DEFAULT 0,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_poll_target (target_type, target_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_polls (v2)\n";
}

if (!$schema->hasTable('gates_poll_votes')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_poll_votes (
             id INTEGER PRIMARY KEY AUTOINCREMENT, poll_id INTEGER NOT NULL, option_index INTEGER NOT NULL,
             fp TEXT NOT NULL, user_id INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE(poll_id, fp, option_index) )"
        : "CREATE TABLE gates_poll_votes (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, poll_id BIGINT UNSIGNED NOT NULL, option_index INT NOT NULL,
             fp VARCHAR(64) NOT NULL, user_id BIGINT UNSIGNED DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_pollvote (poll_id, fp, option_index) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_poll_votes (v2)\n";
}

echo "polls-v2 migration OK\n";
