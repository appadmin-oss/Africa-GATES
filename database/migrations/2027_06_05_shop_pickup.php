<?php
/**
 * COLLECTION POINTS (shop upgrade phase 4, 18 Nov 2026).
 *
 * An order can be collected in person from a named point instead of delivered. The point is a
 * row (name, address, opening hours, on/off) so an operator can add the office, an event stand
 * or a partner's front desk without a deploy; switching one off keeps every order that named it
 * readable. On the order: which point, the six-digit collection code (TEXT, so a leading zero
 * survives — an INT would turn 012345 into 12345 and it would never match), when it was
 * collected, by which staff member, and the reason when it was handed over without the code.
 *
 * Fulfilment gains `ready` and `collected`; the column is VARCHAR(20), so no repair is needed.
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

if (!DB::schema()->hasTable('gates_shop_pickup_points')) {
    // sort_order is INT, not TINYINT: CLAUDE.md, a TINYINT sort_order has capped at 255 before.
    DB::statement("CREATE TABLE gates_shop_pickup_points (id $id, name VARCHAR(120) NOT NULL,
        address VARCHAR(300) NULL, hours VARCHAR(200) NULL, is_active TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)$tail");
    echo "  + gates_shop_pickup_points\n";
} else {
    echo "  = gates_shop_pickup_points already present\n";
}

if (DB::schema()->hasTable('gates_orders')) {
    foreach ([
        'pickup_point_id'  => $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED NULL',
        'pickup_code'      => $sqlite ? 'TEXT' : 'VARCHAR(8) NULL',
        'collected_at'     => $sqlite ? 'TEXT' : 'DATETIME NULL',
        'collected_by'     => $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED NULL',
        'collect_override' => $sqlite ? 'TEXT' : 'VARCHAR(300) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_orders', $col)) {
            DB::statement("ALTER TABLE gates_orders ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_orders.{$col}\n";
        } else {
            echo "  = gates_orders.{$col} already present\n";
        }
    }
    echo SchemaIndex::ensure('gates_orders', 'idx_order_pickup', ['pickup_point_id', 'fulfilment']) . "\n";
}
echo "shop pickup OK\n";
