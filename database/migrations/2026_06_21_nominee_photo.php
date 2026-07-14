<?php
/** Add nominee_photo_path to gates_nominations (optional nominee portrait). Idempotent. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$type   = $sqlite ? 'TEXT' : 'VARCHAR(400) DEFAULT NULL';

if (!in_array('nominee_photo_path', DB::getSchemaBuilder()->getColumnListing('gates_nominations'), true)) {
    try { DB::statement('ALTER TABLE gates_nominations ADD COLUMN nominee_photo_path ' . $type); }
    catch (\Throwable $e) { echo 'ALTER failed: ' . $e->getMessage() . "\n"; }
}

echo in_array('nominee_photo_path', DB::getSchemaBuilder()->getColumnListing('gates_nominations'), true)
    ? "nominee_photo_path OK\n" : "*** FAILED ***\n";
