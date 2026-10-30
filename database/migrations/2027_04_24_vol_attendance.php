<?php
/**
 * Time on site (owner, 30 Oct 2026: "the hours should still be calculated. We need to be able to
 * know how effective the volunteers are"). Shifts were removed the day before, and with them the
 * only thing that wrote observed hours — the check-in and check-out on a shift signup — so every
 * hour since was a task or a hand-logged claim, and the hours page went on promising scans.
 *
 *   gates_vol_attendance   one visit by a volunteer to an event they hold a role at: when they
 *                          arrived, when they left, and WHO recorded each end ('self' or 'lead').
 *                          Closing a visit writes one `gates_vol_hours` row (source 'scan',
 *                          ref_kind 'attend'); a lead's tap at either end makes it count at once,
 *                          two taps by the volunteer alone wait for a lead like hand-logged hours.
 *
 * VARCHAR, never ENUM. Indexes through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$ref = static fn (bool $null = false) => ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ($null ? ' NULL' : ' NOT NULL');
$str = static fn (int $n, bool $null = true) => ($sqlite ? 'TEXT' : "VARCHAR($n)") . ($null ? ' NULL' : ' NOT NULL');
$dt  = static fn (bool $null = false) => ($sqlite ? 'TEXT' : 'DATETIME') . ($null ? ' NULL' : ' NOT NULL');
$eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_vol_attendance')) {
    DB::statement("CREATE TABLE gates_vol_attendance (id $id, volunteer_id {$ref()}, event_id {$ref()}, in_at {$dt()}, in_by {$str(16, false)},
        in_name {$str(190)}, out_at {$dt(true)}, out_by {$str(16)}, out_name {$str(190)}, hours_id {$ref(true)}, created_at {$dt()})$eng");
    echo "  + gates_vol_attendance\n";
}
SchemaIndex::ensure('gates_vol_attendance', 'idx_volatt_vol', ['volunteer_id', 'event_id']);
SchemaIndex::ensure('gates_vol_attendance', 'idx_volatt_event', ['event_id', 'in_at']);
