<?php
/**
 * THE GATEWAY'S ACTUAL FEE ON A SHOP ORDER (shop upgrade phase 6, 18 Nov 2026).
 *
 * The shop's "net" figures (dashboard, reports, CSV) need what the gateway really kept. The
 * fee schedule changes and differs for international cards, so recomputing it from today's
 * rates misstates every older sale; the verify call already returns the fee, and it is stored
 * here at confirmation. NULL = not reported (an order from before this column, another
 * gateway), and the reports then show an estimate and say it is one.
 *
 * Additive, idempotent, driver-aware. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
if (DB::schema()->hasTable('gates_orders') && !DB::schema()->hasColumn('gates_orders', 'fee_naira')) {
    DB::statement('ALTER TABLE gates_orders ADD COLUMN fee_naira ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED NULL') . ' DEFAULT NULL');
    echo "  + gates_orders.fee_naira\n";
} else {
    echo "  = gates_orders.fee_naira already present\n";
}
echo "shop fees OK\n";
