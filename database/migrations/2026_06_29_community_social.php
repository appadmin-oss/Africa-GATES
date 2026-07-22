<?php
/** Community social backend: polls, follows, bookmarks, reposts. Idempotent + driver-aware. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_polls')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_polls (
             id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL,
             question TEXT NOT NULL, options TEXT NOT NULL, is_closed INTEGER NOT NULL DEFAULT 0,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(thread_id) )"
        : "CREATE TABLE gates_polls (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, thread_id BIGINT UNSIGNED NOT NULL,
             question VARCHAR(255) NOT NULL, options TEXT NOT NULL, is_closed TINYINT(1) NOT NULL DEFAULT 0,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_poll_thread (thread_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_polls\n";
}

if (!$schema->hasTable('gates_poll_votes')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_poll_votes (
             id INTEGER PRIMARY KEY AUTOINCREMENT, poll_id INTEGER NOT NULL, option_index INTEGER NOT NULL,
             fp TEXT NOT NULL, user_id INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE(poll_id, fp) )"
        : "CREATE TABLE gates_poll_votes (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, poll_id BIGINT UNSIGNED NOT NULL, option_index INT NOT NULL,
             fp VARCHAR(64) NOT NULL, user_id BIGINT UNSIGNED DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_pollvote (poll_id, fp) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_poll_votes\n";
}

if (!$schema->hasTable('gates_follows')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_follows (
             id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
             target_type TEXT NOT NULL, target_id INTEGER NOT NULL,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, target_type, target_id) )"
        : "CREATE TABLE gates_follows (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL,
             target_type VARCHAR(20) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_follow (user_id, target_type, target_id),
             KEY idx_follow_target (target_type, target_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_follows\n";
}

if (!$schema->hasTable('gates_bookmarks')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_bookmarks (
             id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, thread_id INTEGER NOT NULL,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, thread_id) )"
        : "CREATE TABLE gates_bookmarks (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, thread_id BIGINT UNSIGNED NOT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_bookmark (user_id, thread_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_bookmarks\n";
}

if (!$schema->hasTable('gates_reposts')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_reposts (
             id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, thread_id INTEGER NOT NULL,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, thread_id) )"
        : "CREATE TABLE gates_reposts (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, thread_id BIGINT UNSIGNED NOT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_repost (user_id, thread_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_reposts\n";
}

if (!$schema->hasColumn('gates_threads', 'repost_count')) {
    DB::statement('ALTER TABLE gates_threads ADD COLUMN repost_count ' . ($sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT NOT NULL DEFAULT 0'));
    echo "added gates_threads.repost_count\n";
}

echo "community-social migration OK\n";
