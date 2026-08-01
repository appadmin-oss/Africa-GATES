<?php
/** Add 'moderator' to the admin role enum. Idempotent + driver-aware (MySQL only — SQLite's CHECK is rebuilt from sqlite-admin-schema.sql). */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$sqlite) {
    try {
        // Re-stating the full enum is idempotent: re-running yields the same set.
        DB::statement("ALTER TABLE gates_admins MODIFY role ENUM('superadmin','admin','editor','moderator','judge','viewer') NOT NULL DEFAULT 'editor'");
        echo "admin role enum now includes 'moderator'\n";
    } catch (\Throwable $e) {
        echo '*** role enum alter failed: ' . $e->getMessage() . "\n";
    }
} else {
    echo "sqlite: role CHECK is rebuilt from sqlite-admin-schema.sql — no ALTER needed\n";
}
