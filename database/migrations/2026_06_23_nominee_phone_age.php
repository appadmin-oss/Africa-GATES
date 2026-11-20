<?php
/** Add nominee_phone + nominator_age_range to gates_nominations. Idempotent. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$cols = [
    'nominee_phone'       => $sqlite ? 'TEXT' : 'VARCHAR(40) DEFAULT NULL',
    'nominator_age_range' => $sqlite ? 'TEXT' : 'VARCHAR(20) DEFAULT NULL',
];

$existing = DB::getSchemaBuilder()->getColumnListing('gates_nominations');
foreach ($cols as $name => $type) {
    if (!in_array($name, $existing, true)) {
        try { DB::statement("ALTER TABLE gates_nominations ADD COLUMN {$name} {$type}"); }
        catch (\Throwable $e) { echo "ALTER {$name} failed: " . $e->getMessage() . "\n"; }
    }
}

$now = DB::getSchemaBuilder()->getColumnListing('gates_nominations');
foreach (array_keys($cols) as $name) {
    echo in_array($name, $now, true) ? "{$name} OK\n" : "*** {$name} FAILED ***\n";
}
