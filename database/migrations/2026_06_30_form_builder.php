<?php
/** Advanced form builder: admin-designed forms + their submissions. Idempotent + driver-aware. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_forms')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_forms (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             form_key TEXT NOT NULL UNIQUE,
             title TEXT NOT NULL,
             description TEXT,
             schema_json TEXT NOT NULL DEFAULT '{\"fields\":[]}',
             submit_message TEXT,
             status TEXT NOT NULL DEFAULT 'draft',
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
             updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP )"
        : "CREATE TABLE gates_forms (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             form_key VARCHAR(80) NOT NULL,
             title VARCHAR(200) NOT NULL,
             description TEXT,
             schema_json MEDIUMTEXT NOT NULL,
             submit_message TEXT,
             status VARCHAR(20) NOT NULL DEFAULT 'draft',
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_form_key (form_key)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_forms\n";
}

if (!$schema->hasTable('gates_form_submissions')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_form_submissions (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             form_id INTEGER NOT NULL,
             form_key TEXT NOT NULL,
             data_json TEXT NOT NULL,
             ip_hash TEXT,
             user_id INTEGER,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP )"
        : "CREATE TABLE gates_form_submissions (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             form_id BIGINT UNSIGNED NOT NULL,
             form_key VARCHAR(80) NOT NULL,
             data_json MEDIUMTEXT NOT NULL,
             ip_hash VARCHAR(64) DEFAULT NULL,
             user_id BIGINT UNSIGNED DEFAULT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), KEY idx_formsub_form (form_key)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($sqlite) { try { DB::statement('CREATE INDEX idx_formsub_form ON gates_form_submissions (form_key)'); } catch (\Throwable $e) {} }
    echo "created gates_form_submissions\n";
}

echo "form-builder migration OK\n";
