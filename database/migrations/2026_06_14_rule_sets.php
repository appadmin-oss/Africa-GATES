<?php
/** Add gates_rule_sets (per-scope CPI/policy overrides). Idempotent. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ddl = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_rule_sets (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         scope TEXT NOT NULL DEFAULT 'global',
         scope_id INTEGER,
         rules TEXT NOT NULL DEFAULT '{}',
         updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE(scope, scope_id)
       )"
    : "CREATE TABLE IF NOT EXISTS gates_rule_sets (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         scope VARCHAR(20) NOT NULL DEFAULT 'global',
         scope_id BIGINT UNSIGNED DEFAULT NULL,
         rules JSON NOT NULL,
         updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uq_rule_scope (scope, scope_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

DB::connection()->getPdo()->exec($ddl);
echo DB::schema()->hasTable('gates_rule_sets') ? "gates_rule_sets OK\n" : "*** FAILED ***\n";
