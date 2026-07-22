<?php
/** Gated single-use form links for verified nominees + judge invites. Idempotent + driver-aware. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_form_tokens')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_form_tokens (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             purpose TEXT NOT NULL,                 -- 'nominee' | 'judge'
             subject_id INTEGER NOT NULL,           -- nominee_id or judge_id
             email_hash TEXT,
             token_hash TEXT NOT NULL UNIQUE,
             payload TEXT,                          -- JSON of the submitted form, once filled
             is_used INTEGER NOT NULL DEFAULT 0,
             used_at TEXT,
             expires_at TEXT,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP )"
        : "CREATE TABLE gates_form_tokens (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             purpose VARCHAR(16) NOT NULL,
             subject_id BIGINT UNSIGNED NOT NULL,
             email_hash VARCHAR(64) DEFAULT NULL,
             token_hash VARCHAR(64) NOT NULL,
             payload TEXT,
             is_used TINYINT(1) NOT NULL DEFAULT 0,
             used_at TIMESTAMP NULL DEFAULT NULL,
             expires_at TIMESTAMP NULL DEFAULT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_formtok (token_hash), KEY idx_formtok_subject (purpose, subject_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($sqlite) {
        try { DB::statement('CREATE INDEX idx_formtok_subject ON gates_form_tokens (purpose, subject_id)'); } catch (\Throwable $e) {}
    }
    echo "created gates_form_tokens\n";
}

echo "form-tokens migration OK\n";
