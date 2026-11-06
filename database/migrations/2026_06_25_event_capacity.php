<?php
/** Add gates_site_events.capacity (max attendees; NULL = unlimited). Idempotent, driver-aware. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasColumn('gates_site_events', 'capacity')) {
    DB::statement('ALTER TABLE gates_site_events ADD COLUMN capacity ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED DEFAULT NULL'));
    echo "added gates_site_events.capacity\n";
} else {
    echo "gates_site_events.capacity already present\n";
}
