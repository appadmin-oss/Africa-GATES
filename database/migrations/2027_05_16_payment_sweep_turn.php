<?php
/**
 * When the hourly payment sweep last ASKED the gateway about a pending row — so it takes
 * turns instead of asking about the same rows every hour.
 *
 * ── THE FAULT ────────────────────────────────────────────────────────────────
 *
 * The sweep reads `status = 'pending' ORDER BY id DESC LIMIT 25` per table, per tick. An
 * abandoned checkout stays pending for three days (Paystack calls it `abandoned`, which may
 * still complete, so it is not written off sooner). On a voting night there are hundreds of
 * them, so the newest twenty-five were asked every hour and everything behind them was never
 * asked at all — including the payments Paystack had already marked FAILED. Paystack sends
 * no webhook for a failed charge, so asking is the only way a row learns it. The operator
 * saw "Payments 101" in the console, Paystack's dashboard said failed, and the site said
 * pending, for days.
 *
 * ── THE FIX ──────────────────────────────────────────────────────────────────
 *
 * Least recently asked first: never-asked rows, then the longest-ago, newest id breaking
 * ties. Every row in the queue is reached within (queue ÷ 25) ticks, and a row asked this
 * hour waits its turn rather than blocking the rest.
 *
 * Its own column, as `recovery_checked_at` is: `gateway_checked_at` belongs to the refund
 * evidence flow, and a housekeeping stamp must never overwrite what we told a customer.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

foreach (['gates_donations', 'gates_orders', 'gates_event_registrations'] as $table) {
    if (!DB::schema()->hasTable($table)) {
        echo "  = {$table} not present — skipped\n";
        continue;
    }
    if (!DB::schema()->hasColumn($table, 'swept_at')) {
        DB::statement("ALTER TABLE {$table} ADD COLUMN swept_at "
            . ($sqlite ? 'TEXT' : 'TIMESTAMP NULL') . ' DEFAULT NULL');
        echo "  + {$table}.swept_at added\n";
    } else {
        echo "  = {$table}.swept_at already present\n";
    }
}

// The sweep's read: pending rows, least recently asked first.
echo SchemaIndex::ensure('gates_donations', 'idx_donations_sweep', ['status', 'swept_at']) . "\n";
echo SchemaIndex::ensure('gates_orders', 'idx_orders_sweep', ['status', 'swept_at']) . "\n";
echo SchemaIndex::ensure('gates_event_registrations', 'idx_event_reg_sweep', ['status', 'swept_at']) . "\n";

echo "payment sweep turn OK\n";
