<?php
/**
 * A shop order remembers the referral link that brought the buyer.
 *
 * Stamped on the ORDER rather than looked up from the session at fulfilment, for the same
 * reason `rate_bps` is stamped on the credit: the session is gone by the time a webhook
 * confirms a payment made on a phone that has since been closed, and a referral that only
 * survives while a browser tab is open is one that quietly fails on exactly the slow
 * payments where the referrer waited longest.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_orders')) {
    echo "  = gates_orders not present yet\n";
    return;
}

if (!DB::schema()->hasColumn('gates_orders', 'referral_code')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_orders ADD COLUMN referral_code TEXT NULL'
        : 'ALTER TABLE gates_orders ADD COLUMN referral_code VARCHAR(32) NULL');
    echo "  + gates_orders.referral_code added\n";
} else {
    echo "  = gates_orders.referral_code already present\n";
}
