<?php
/** Add gates_products.delivery_regions (JSON array of allowed regions; NULL = nationwide). Idempotent, driver-aware. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasColumn('gates_products', 'delivery_regions')) {
    DB::statement('ALTER TABLE gates_products ADD COLUMN delivery_regions ' . ($sqlite ? 'TEXT' : 'TEXT DEFAULT NULL'));
    echo "added gates_products.delivery_regions\n";
} else {
    echo "gates_products.delivery_regions already present\n";
}
