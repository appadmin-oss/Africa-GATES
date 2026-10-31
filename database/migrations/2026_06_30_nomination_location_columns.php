<?php
/**
 * gates_nominations location + reference columns — idempotent + driver-aware.
 *
 * Replaces the raw `ALTER TABLE … ADD COLUMN IF NOT EXISTS …` block that used to
 * live in schema.sql. `ADD COLUMN IF NOT EXISTS` is MariaDB-only syntax and is a
 * HARD SYNTAX ERROR on Oracle MySQL, so on a MySQL host it aborted the whole
 * schema apply (and therefore every later migration). Per-column hasColumn guards
 * work on MySQL, MariaDB and SQLite alike. NEVER use exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
if (!$schema->hasTable('gates_nominations')) { echo "no gates_nominations — skip\n"; return; }
$sqlite = DB::connection()->getDriverName() === 'sqlite';

$cols = [
    'nominee_state'      => $sqlite ? 'TEXT' : 'VARCHAR(100) DEFAULT NULL',
    'nominee_lga'        => $sqlite ? 'TEXT' : 'VARCHAR(100) DEFAULT NULL',
    'reference_url'      => $sqlite ? 'TEXT' : 'VARCHAR(400) DEFAULT NULL',
    'reference_url_2'    => $sqlite ? 'TEXT' : 'VARCHAR(400) DEFAULT NULL',
    'reference_url_3'    => $sqlite ? 'TEXT' : 'VARCHAR(400) DEFAULT NULL',
    'nominator_phone'    => $sqlite ? 'TEXT' : 'VARCHAR(30) DEFAULT NULL',
    'nominator_location' => $sqlite ? 'TEXT' : 'VARCHAR(200) DEFAULT NULL',
    'nominator_country'  => $sqlite ? 'TEXT' : 'CHAR(2) DEFAULT NULL',
    'nominator_state'    => $sqlite ? 'TEXT' : 'VARCHAR(100) DEFAULT NULL',
    'nominator_lga'      => $sqlite ? 'TEXT' : 'VARCHAR(100) DEFAULT NULL',
];

foreach ($cols as $col => $type) {
    if (!$schema->hasColumn('gates_nominations', $col)) {
        DB::statement("ALTER TABLE gates_nominations ADD COLUMN {$col} {$type}");
        echo "added gates_nominations.{$col}\n";
    }
}

echo "nomination location/reference columns OK\n";
