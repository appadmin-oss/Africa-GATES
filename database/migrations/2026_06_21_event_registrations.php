<?php
/** Add gates_event_registrations (on-platform event RSVP). Idempotent. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ddl = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_event_registrations (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         event_id INTEGER NOT NULL,
         name TEXT NOT NULL,
         email TEXT NOT NULL,
         phone TEXT,
         status TEXT NOT NULL DEFAULT 'confirmed',
         ip_hash TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_event_registrations (
         id INT UNSIGNED NOT NULL AUTO_INCREMENT,
         event_id INT UNSIGNED NOT NULL,
         name VARCHAR(160) NOT NULL,
         email VARCHAR(190) NOT NULL,
         phone VARCHAR(40) DEFAULT NULL,
         status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
         ip_hash VARCHAR(64) DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uq_evreg (event_id, email),
         KEY idx_evreg_event (event_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

DB::connection()->getPdo()->exec($ddl);
// Add ip_hash to a pre-existing table (idempotent — ignores "duplicate column").
try { DB::statement('ALTER TABLE gates_event_registrations ADD COLUMN ip_hash ' . ($sqlite ? 'TEXT' : 'VARCHAR(64) DEFAULT NULL')); } catch (\Throwable $e) {}
if ($sqlite) { try { DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_evreg ON gates_event_registrations(event_id, email)'); } catch (\Throwable $e) {} }
echo DB::schema()->hasTable('gates_event_registrations') ? "gates_event_registrations OK\n" : "*** FAILED ***\n";
