<?php
/**
 * IN-PERSON SALES AND THE CASH DRAWER (shop upgrade phase 5, 18 Nov 2026).
 *
 * A sale at an event table or the office is an order like any other — one stock pool, one
 * ledger, one refund path — so it is a `gates_orders` row (`channel = 'pos'`) and not a table of
 * its own. What it adds is how it was paid (`pos_method`: cash, transfer, terminal) and, for
 * cash, which drawer it went into (`cash_session_id`).
 *
 * `gates_shop_cash_sessions` is the drawer for one event or day: the float it opened with, and
 * when closed, what the system expected (float + cash taken − cash given back) beside what was
 * counted. The difference is stored, not recomputed, because it is what somebody signed off.
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

if (!DB::schema()->hasTable('gates_shop_cash_sessions')) {
    // Signed INT for the difference: a drawer can be short.
    DB::statement("CREATE TABLE gates_shop_cash_sessions (id $id, label VARCHAR(120) NOT NULL,
        float_naira INT NOT NULL DEFAULT 0, opened_by $big NULL, opened_at DATETIME NOT NULL,
        closed_by $big NULL, closed_at DATETIME NULL, expected_naira INT NULL, counted_naira INT NULL,
        difference_naira INT NULL, note VARCHAR(300) NULL)$tail");
    echo "  + gates_shop_cash_sessions\n";
} else {
    echo "  = gates_shop_cash_sessions already present\n";
}

if (DB::schema()->hasTable('gates_orders')) {
    foreach ([
        'channel'         => $sqlite ? 'TEXT' : 'VARCHAR(10) NULL',
        'pos_method'      => $sqlite ? 'TEXT' : 'VARCHAR(12) NULL',
        'cash_session_id' => $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_orders', $col)) {
            DB::statement("ALTER TABLE gates_orders ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_orders.{$col}\n";
        } else {
            echo "  = gates_orders.{$col} already present\n";
        }
    }
    echo SchemaIndex::ensure('gates_orders', 'idx_order_cash_session', ['cash_session_id']) . "\n";
}
echo "shop pos OK\n";
