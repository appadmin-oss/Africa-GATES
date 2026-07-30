<?php
/**
 * Cloudinary media hosting: provenance columns on gates_uploads, and the sweep ledger.
 *
 * Both are declared in admin-schema.sql / sqlite-admin-schema.sql for fresh installs;
 * this is the forward-only catch-up for every database that already exists. The
 * convention in this project is that a base-schema edit fixes new deployments and does
 * NOTHING for current ones, because MigrationRunner records applied files in the
 * gates_migrations ledger and never re-runs a schema step it has already logged.
 *
 * WHAT gets added, and why each column is load-bearing rather than decorative:
 *
 *   gates_uploads.provider    — 'local' | 'cloudinary'. `path` already holds whichever
 *                               URL is serveable, so provider is not redundant with it:
 *                               it is how the sweep finds rows whose Cloudinary leg
 *                               failed at upload time, and how the admin media delete
 *                               knows whether to unlink a file or call the API.
 *   gates_uploads.public_id   — the handle needed to DELETE a Cloudinary asset. Without
 *                               it, removing an image from the admin console removes
 *                               the row and leaves the asset (and its bill) behind.
 *   gates_uploads.local_path  — the original on-disk path, kept even after a successful
 *                               remote upload. It is what makes the deterministic
 *                               public id re-derivable and a rollback possible.
 *   gates_media_migrations    — one row per local file swept, keyed UNIQUE on the source
 *                               path so a re-run is idempotent rather than a second
 *                               upload of every photo on the platform.
 *
 * Deliberately NOT an ENUM on the catch-up path. Adding an ENUM column to a large
 * InnoDB table is fine, but MySQL's strict mode rejects a value outside the set with an
 * error rather than a warning, and a deployment mid-way through this migration can have
 * new code writing a value the old schema does not know. VARCHAR + a default accepts
 * both, and the application is the thing that constrains the value anyway.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── gates_uploads: provenance ────────────────────────────────────────────────
if ($schema->hasTable('gates_uploads')) {
    $add = [
        'provider'   => $sqlite ? "TEXT NOT NULL DEFAULT 'local'" : "VARCHAR(20) NOT NULL DEFAULT 'local'",
        'public_id'  => $sqlite ? 'TEXT' : 'VARCHAR(255) DEFAULT NULL',
        'local_path' => $sqlite ? 'TEXT' : 'VARCHAR(500) DEFAULT NULL',
    ];
    foreach ($add as $col => $type) {
        if ($schema->hasColumn('gates_uploads', $col)) continue;
        try {
            DB::statement("ALTER TABLE gates_uploads ADD COLUMN {$col} {$type}");
            echo "gates_uploads.{$col} added\n";
        } catch (\Throwable $e) {
            echo "gates_uploads.{$col} skipped: " . $e->getMessage() . "\n";
        }
    }

    // Backfill local_path for rows that predate the column. `path` on such a row is
    // always a local /uploads/... value, because nothing else could have written it.
    try {
        if ($schema->hasColumn('gates_uploads', 'local_path')) {
            DB::table('gates_uploads')->whereNull('local_path')->update(['local_path' => DB::raw('path')]);
        }
    } catch (\Throwable $e) {
        echo 'local_path backfill skipped: ' . $e->getMessage() . "\n";
    }

    // Plain CREATE INDEX, guarded — `IF NOT EXISTS` is a 1064 on MySQL, which is the
    // exact mistake four earlier migrations in this directory made (see
    // 2026_07_28_vote_index_repair.php).
    foreach (['idx_uploads_provider' => 'provider', 'idx_uploads_public_id' => 'public_id'] as $idx => $col) {
        if (!$schema->hasColumn('gates_uploads', $col)) continue;
        try {
            DB::statement($sqlite
                ? "CREATE INDEX IF NOT EXISTS {$idx} ON gates_uploads({$col})"
                : "CREATE INDEX {$idx} ON gates_uploads({$col})");
        } catch (\Throwable) { /* already there */ }
    }
}

// ── gates_media_migrations: the sweep ledger ─────────────────────────────────
if (!$schema->hasTable('gates_media_migrations')) {
    try {
        if ($sqlite) {
            DB::statement('CREATE TABLE IF NOT EXISTS gates_media_migrations ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'source_path TEXT NOT NULL UNIQUE, '
                . 'public_id TEXT, remote_url TEXT, '
                . 'target_table TEXT, target_column TEXT, target_id INTEGER, '
                . "status TEXT NOT NULL DEFAULT 'migrated', "
                . 'error TEXT, bytes INTEGER, '
                . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_media_status ON gates_media_migrations(status)');
        } else {
            DB::statement('CREATE TABLE IF NOT EXISTS gates_media_migrations ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . 'source_path VARCHAR(500) NOT NULL, '
                . 'public_id VARCHAR(255) DEFAULT NULL, '
                . 'remote_url VARCHAR(500) DEFAULT NULL, '
                . 'target_table VARCHAR(64) DEFAULT NULL, '
                . 'target_column VARCHAR(64) DEFAULT NULL, '
                . 'target_id BIGINT UNSIGNED DEFAULT NULL, '
                . "status VARCHAR(20) NOT NULL DEFAULT 'migrated', "
                . 'error VARCHAR(300) DEFAULT NULL, '
                . 'bytes INT UNSIGNED DEFAULT NULL, '
                . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY(id), UNIQUE KEY uq_media_source(source_path), '
                . 'KEY idx_media_status(status)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        echo "gates_media_migrations created\n";
    } catch (\Throwable $e) {
        echo 'gates_media_migrations skipped: ' . $e->getMessage() . "\n";
    }
}

echo "cloudinary media migration OK\n";
