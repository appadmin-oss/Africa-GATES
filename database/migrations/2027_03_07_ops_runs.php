<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * EVERY RUN OF AN OPERATIONS SCRIPT — by a person or by the admin assistant.
 *
 * There is no shell on production, so the console commands that diagnose and repair this
 * platform (`payments:triage`, `standings:verify`, `app:doctor`…) could not be run by anybody.
 * {@see \AfricaGates\Services\Ops\OpsScripts} runs them in-process from the console, and the
 * assistant runs the read-only ones itself. A record of who ran what — and whether it was a
 * person or the assistant — is the price of letting either do it.
 *
 * `via` is VARCHAR over ENUM (house rule for a status-like column). `output` is the captured
 * text, capped by the runner. Indexes through SchemaIndex — never `CREATE INDEX IF NOT EXISTS`.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_ops_runs')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_ops_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            script_key TEXT NOT NULL,
            kind TEXT NOT NULL,
            via TEXT NOT NULL,
            admin_id INTEGER NULL,
            ok INTEGER NOT NULL DEFAULT 0,
            exit_code INTEGER NULL,
            output TEXT NULL,
            reason TEXT NULL,
            ms INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_ops_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            script_key VARCHAR(48) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            via VARCHAR(12) NOT NULL,
            admin_id BIGINT UNSIGNED NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            exit_code INT NULL,
            output MEDIUMTEXT NULL,
            reason VARCHAR(500) NULL,
            ms INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_ops_runs created\n";
} else {
    echo "  = gates_ops_runs already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_ops_runs', 'idx_opsrun_key', ['script_key', 'id']) . "\n";

echo "ops runs OK\n";
