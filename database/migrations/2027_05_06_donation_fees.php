<?php
/**
 * A gift's processing fee, and the donor's cover for it, each on its own column.
 *
 *   gates_donations.fee_cover_naira    what the donor ADDED so the recipient is left with
 *                                      the gift (GatewayFee::cover). Not given to anybody:
 *                                      it is the gateway's, so "raised" never counts it.
 *   gates_donations.gateway_fee_naira  the gateway's fee where the ORGANISATION bears it
 *                                      (the standard since 3 Nov 2026; 0 where the platform
 *                                      does). Taken off what they are owed, and never shown
 *                                      as the Africa GATES fee, which is platform_fee_naira.
 *
 * Both default 0, so every existing row keeps exactly the figures it had: those gifts were
 * made when the platform bore the fee and nobody had covered one by this rule.
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$int = $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0';
foreach (['fee_cover_naira', 'gateway_fee_naira'] as $col) {
    if ($schema->hasTable('gates_donations') && !$schema->hasColumn('gates_donations', $col)) {
        DB::statement("ALTER TABLE gates_donations ADD COLUMN $col $int");
        echo "  + gates_donations.$col\n";
    }
}
echo "donation fees OK\n";
