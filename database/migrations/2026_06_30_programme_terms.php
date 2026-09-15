<?php
/** Per-programme editable terms (nomination/voting). Idempotent + driver-aware. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasColumn('gates_award_programmes', 'terms')) {
    DB::statement('ALTER TABLE gates_award_programmes ADD COLUMN terms ' . ($sqlite ? 'TEXT' : 'MEDIUMTEXT NULL'));
    echo "added gates_award_programmes.terms\n";
}

echo "programme-terms migration OK\n";
