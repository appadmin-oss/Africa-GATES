<?php
/**
 * The console assistant's conversations, kept per administrator (Admin\Services\AssistantThreads).
 *
 *   gates_assistant_threads    one conversation: whose, its title (the first question), when
 *                              it last moved.
 *   gates_assistant_messages   each turn. An assistant turn keeps what it was built from —
 *                              the checks it ran (`ran_json`), the console pages it offered
 *                              (`actions_json`), the check results shaped for the evidence
 *                              panel (`evidence_json`) — and the operator's rating of it, so
 *                              "is the assistant any use?" has an answer that is not a guess.
 *
 * Before this the conversation lived in the browser tab's sessionStorage: a new tab, another
 * device or a closed laptop lost it, and nothing could be searched or rated. Retention is
 * AssistantThreads::RETAIN_DAYS, pruned by the daily maintenance run — the evidence holds
 * check output, which can name people, and is kept no longer than it is useful.
 *
 * VARCHAR, not ENUM, for every word (CLAUDE.md). Idempotent + driver-aware; indexes through
 * SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_assistant_threads')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_assistant_threads (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              admin_id INTEGER NOT NULL,
              title TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE gates_assistant_threads (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              admin_id BIGINT UNSIGNED NOT NULL,
              title VARCHAR(200) NOT NULL DEFAULT '',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_assistant_threads\n";
}
if ($schema->hasTable('gates_assistant_threads')) {
    echo '  ' . SchemaIndex::ensure('gates_assistant_threads', 'idx_asth_admin', ['admin_id', 'updated_at']) . "\n";
}

if (!$schema->hasTable('gates_assistant_messages')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_assistant_messages (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              thread_id INTEGER NOT NULL,
              role TEXT NOT NULL DEFAULT 'user',
              body TEXT NOT NULL,
              ran_json TEXT NULL,
              actions_json TEXT NULL,
              evidence_json TEXT NULL,
              page TEXT NULL,
              rating INTEGER NULL,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE gates_assistant_messages (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              thread_id BIGINT UNSIGNED NOT NULL,
              role VARCHAR(16) NOT NULL DEFAULT 'user',
              body MEDIUMTEXT NOT NULL,
              ran_json TEXT NULL,
              actions_json TEXT NULL,
              evidence_json MEDIUMTEXT NULL,
              page VARCHAR(300) NULL,
              rating SMALLINT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_assistant_messages\n";
}
if ($schema->hasTable('gates_assistant_messages')) {
    echo '  ' . SchemaIndex::ensure('gates_assistant_messages', 'idx_asm_thread', ['thread_id', 'id']) . "\n";
}
