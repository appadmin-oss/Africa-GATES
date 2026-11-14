<?php
/** Add nominee_photo_path to gates_nominations (optional nominee portrait). Idempotent. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$type   = $sqlite ? 'TEXT' : 'VARCHAR(400) DEFAULT NULL';

if (!in_array('nominee_photo_path', DB::getSchemaBuilder()->getColumnListing('gates_nominations'), true)) {
    try { DB::statement('ALTER TABLE gates_nominations ADD COLUMN nominee_photo_path ' . $type); }
    catch (\Throwable $e) { echo 'ALTER failed: ' . $e->getMessage() . "\n"; }
}

echo in_array('nominee_photo_path', DB::getSchemaBuilder()->getColumnListing('gates_nominations'), true)
    ? "nominee_photo_path OK\n" : "*** FAILED ***\n";
