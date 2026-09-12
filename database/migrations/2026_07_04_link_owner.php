<?php
/**
 * Batch 3 · Task 6 — attribute share links to the member who created them
 * (nullable created_by on gates_nomination_links; guests stay NULL). Powers
 * the "your share links" card on the member dashboard.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
if ($schema->hasTable('gates_nomination_links') && !$schema->hasColumn('gates_nomination_links', 'created_by')) {
    $sqlite = DB::connection()->getDriverName() === 'sqlite';
    DB::statement('ALTER TABLE gates_nomination_links ADD COLUMN created_by ' . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED DEFAULT NULL'));
    echo "added gates_nomination_links.created_by\n";
}
echo "link owner migration OK\n";
