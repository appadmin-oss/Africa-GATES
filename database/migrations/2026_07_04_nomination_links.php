<?php
/**
 * Batch 3 · Task 3 — shareable prefill nomination links.
 * gates_nomination_links: opaque high-entropy token → nominee-side JSON payload
 * used to prefill the nomination wizard. Idempotent + driver-aware.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_nomination_links')) {
    if ($sqlite) {
        DB::statement("CREATE TABLE gates_nomination_links (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          token TEXT NOT NULL UNIQUE,
          payload TEXT NOT NULL,
          created_ip_hash TEXT,
          hits INTEGER NOT NULL DEFAULT 0,
          expires_at TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_nomlink_expires ON gates_nomination_links(expires_at)');
    } else {
        DB::statement("CREATE TABLE gates_nomination_links (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          token VARCHAR(64) NOT NULL,
          payload TEXT NOT NULL,
          created_ip_hash VARCHAR(64) DEFAULT NULL,
          hits INT UNSIGNED NOT NULL DEFAULT 0,
          expires_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id), UNIQUE KEY uq_nomlink_token(token), KEY idx_nomlink_expires(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "created gates_nomination_links\n";
}

echo "nomination links migration OK\n";
