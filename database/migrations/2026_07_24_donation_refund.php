<?php
/**
 * Refund / chargeback clawback support.
 *
 * Adds gates_donations.refunded_at — set when a confirmed donation is reversed
 * (refund/dispute webhook, or an admin/ops action). The clawback voids the
 * purchased vote rows (bonus + paid) minted from that donation and rebuilds the
 * affected nominees' counters, so a refunded payment can't leave paid votes
 * standing — and refunded_at blocks any further redemption of that donation.
 *
 * A nullable column (not a new status enum value) so it's a clean additive
 * migration on both MySQL and SQLite (no ENUM/CHECK table rebuild). Idempotent.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasColumn('gates_donations', 'refunded_at')) {
    DB::statement('ALTER TABLE gates_donations ADD COLUMN refunded_at ' . ($sqlite ? 'TEXT' : 'TIMESTAMP') . ' NULL DEFAULT NULL');
    echo "  + gates_donations.refunded_at added\n";
} else {
    echo "  = gates_donations.refunded_at already present\n";
}

echo "donation refund/clawback migration OK\n";
