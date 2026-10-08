<?php
/** User accounts + voting-points ledger + paid-event-ticket columns. Idempotent, driver-aware. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_users')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_users (
             id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
             phone TEXT, password_hash TEXT, points INTEGER NOT NULL DEFAULT 0,
             status TEXT NOT NULL DEFAULT 'active', email_verified INTEGER NOT NULL DEFAULT 0,
             created_at TEXT, last_login_at TEXT, last_login_ip TEXT )"
        : "CREATE TABLE gates_users (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(160) NOT NULL, email VARCHAR(191) NOT NULL,
             phone VARCHAR(40) DEFAULT NULL, password_hash VARCHAR(255) DEFAULT NULL, points INT NOT NULL DEFAULT 0,
             status VARCHAR(20) NOT NULL DEFAULT 'active', email_verified TINYINT(1) NOT NULL DEFAULT 0,
             created_at TIMESTAMP NULL DEFAULT NULL, last_login_at TIMESTAMP NULL DEFAULT NULL, last_login_ip VARCHAR(64) DEFAULT NULL,
             PRIMARY KEY (id), UNIQUE KEY uq_user_email (email) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_users\n";
}

if (!$schema->hasTable('gates_points_ledger')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_points_ledger (
             id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, delta INTEGER NOT NULL,
             reason TEXT NOT NULL, ref_type TEXT, ref_id TEXT, balance_after INTEGER NOT NULL DEFAULT 0,
             note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP )"
        : "CREATE TABLE gates_points_ledger (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, delta INT NOT NULL,
             reason VARCHAR(40) NOT NULL, ref_type VARCHAR(40) DEFAULT NULL, ref_id VARCHAR(80) DEFAULT NULL,
             balance_after INT NOT NULL DEFAULT 0, note VARCHAR(200) DEFAULT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), KEY idx_ledger_user (user_id, created_at) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created gates_points_ledger\n";
}
if ($sqlite) { try { DB::statement("CREATE INDEX IF NOT EXISTS idx_ledger_user ON gates_points_ledger(user_id, created_at)"); } catch (\Throwable $e) {} }

// Paid event ticketing — a price on events + linkage on registrations.
if (!$schema->hasColumn('gates_site_events', 'price_naira')) {
    DB::statement('ALTER TABLE gates_site_events ADD COLUMN price_naira ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED DEFAULT NULL'));
    echo "added gates_site_events.price_naira\n";
}
$regCols = [
    'amount_naira' => $sqlite ? 'INTEGER' : 'INT DEFAULT 0',
    'reference'    => $sqlite ? 'TEXT'    : 'VARCHAR(80) DEFAULT NULL',
    'user_id'      => $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED DEFAULT NULL',
];
foreach ($regCols as $col => $type) {
    if (!$schema->hasColumn('gates_event_registrations', $col)) {
        DB::statement("ALTER TABLE gates_event_registrations ADD COLUMN {$col} {$type}");
        echo "added gates_event_registrations.{$col}\n";
    }
}

echo $schema->hasTable('gates_users') && $schema->hasTable('gates_points_ledger') ? "user-accounts migration OK\n" : "*** FAILED ***\n";
