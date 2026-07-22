<?php
/** Anti-abuse: device fingerprint on nominations (dedupe repeat nominations of the same person). Idempotent. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasColumn('gates_nominations', 'device_fp')) {
    DB::statement('ALTER TABLE gates_nominations ADD COLUMN device_fp ' . ($sqlite ? 'TEXT' : 'VARCHAR(64) DEFAULT NULL'));
    // Speeds the (cycle, device, nominee) duplicate lookup.
    try { DB::statement('CREATE INDEX idx_nominations_device ON gates_nominations (device_fp)'); } catch (\Throwable $e) {}
    echo "added gates_nominations.device_fp\n";
}

echo "nomination-device-fp migration OK\n";
