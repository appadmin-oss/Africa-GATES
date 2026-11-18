<?php
/**
 * THE STOCK LEDGER AND LOW-STOCK LEVELS (shop upgrade phase 2, 18 Nov 2026).
 *
 * Stock was one number per product or variant that the product form overwrote. "We had twelve
 * large on Monday, four on Friday and sold six" had no answer. Every change to a count is now a
 * row here — how much, why (ShopStock::REASONS, a closed list held in code because the column is
 * a VARCHAR, never an ENUM), who, which order, and the count after — so the history adds up to
 * the shelf, and a stock-take's difference is an entry with a name on it.
 *
 * `low_stock` on a variant (and on a product with no variants) is the level at which it is
 * "running low". NULL means the shop-wide default (`shop_low_stock_default` in gates_settings,
 * ShopStock::lowLevel()), which is what makes the threshold settable in bulk: change one number,
 * or override the few that sell faster.
 *
 * Additive, idempotent, driver-aware; indexes through SchemaIndex. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$big  = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_shop_stock_moves')) {
    // `delta` and `after_qty` are signed INT: a till may sell below zero (a queue at a table
    // must not stall on a miscount), and the ledger has to be able to say so.
    DB::statement("CREATE TABLE gates_shop_stock_moves (id $id, product_id $big NOT NULL, variant_id $big NULL,
        delta INT NOT NULL, after_qty INT NULL, reason VARCHAR(16) NOT NULL, note VARCHAR(300) NULL,
        order_id $big NULL, admin_id $big NULL, created_at DATETIME NOT NULL)$tail");
    echo "  + gates_shop_stock_moves\n";
} else {
    echo "  = gates_shop_stock_moves already present\n";
}
echo SchemaIndex::ensure('gates_shop_stock_moves', 'idx_stock_move_product', ['product_id', 'id']) . "\n";
echo SchemaIndex::ensure('gates_shop_stock_moves', 'idx_stock_move_when', ['created_at']) . "\n";

foreach (['gates_product_variants', 'gates_products'] as $t) {
    if (!DB::schema()->hasTable($t)) continue;
    if (!DB::schema()->hasColumn($t, 'low_stock')) {
        DB::statement("ALTER TABLE {$t} ADD COLUMN low_stock " . ($sqlite ? 'INTEGER' : 'INT NULL') . ' DEFAULT NULL');
        echo "  + {$t}.low_stock\n";
    } else {
        echo "  = {$t}.low_stock already present\n";
    }
}
echo "shop stock ledger OK\n";
