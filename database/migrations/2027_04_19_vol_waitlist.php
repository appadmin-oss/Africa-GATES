<?php
/**
 * The shift waitlist (docs/architecture/WORKSPACE-COMPARISON.md §3).
 *
 *   gates_vol_signups.status     gains `waiting` — a person in line for a full shift. VARCHAR
 *                                already, so no repair is needed; the order is joined_at.
 *   gates_vol_signups.promoted_at  when a place opened and the person was moved on. Read by
 *                                the reminder sweep ("You're on it") and by the bell, so the
 *                                news is derived from the record rather than stored twice.
 *
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_vol_signups') && !$schema->hasColumn('gates_vol_signups', 'promoted_at')) {
    DB::statement('ALTER TABLE gates_vol_signups ADD COLUMN promoted_at ' . ($sqlite ? 'TEXT NULL' : 'DATETIME NULL DEFAULT NULL'));
    echo "  + gates_vol_signups.promoted_at\n";
}
